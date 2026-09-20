<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PdfState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Delta saves: the editor sends what changed and the ids of what did not, and
 * the stored state ends up exactly as a full save would have left it. Images
 * are uploaded on their own and never travel inside the state.
 */
class EditorDeltaSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        config(['editor_limits.scale' => 1000]);
        $this->user = User::factory()->create();
        $this->document = Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'delta.pdf',
            'path' => 'documents/delta.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
    }

    public function test_a_delta_changes_adds_and_removes_exactly_what_a_full_save_would(): void
    {
        $all = $this->annotations(500);
        $this->save(['annotations' => $all])->assertOk();

        // One edited, one added, one deleted, 498 untouched.
        $edited = ['text' => 'Edited in a delta'] + $all[7];
        $added = ['id' => 'ann_new', 'text' => 'Added in a delta'] + $all[0];
        $payload = ['delta' => true, 'annotations' => [$edited, $added], 'removed_ids' => ['ann_9'], 'expected_count' => 500, 'base_version' => 1];

        $this->assertLessThan(2 * 1024, strlen(json_encode($payload)), 'under 2 KB instead of the whole state');
        $this->assertGreaterThan(100 * 1024, strlen(json_encode(['annotations' => $all])));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->save($payload)->assertOk()->assertJson(['success' => true, 'state_version' => 2]);
        $writes = array_filter(DB::getQueryLog(), fn (array $query) => preg_match('/^\s*(insert into|update|delete from) [`"]pdf_state[`"]/i', $query['query']) === 1);
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, count($writes), 'one update, one insert, one delete');

        $stored = PdfState::where('document_id', $this->document->id)->get()->keyBy(fn (PdfState $row) => $row->annotation_data['id']);
        $this->assertCount(500, $stored);
        $this->assertSame('Edited in a delta', $stored['ann_7']->annotation_data['text']);
        $this->assertSame('Added in a delta', $stored['ann_new']->annotation_data['text']);
        $this->assertArrayNotHasKey('ann_9', $stored->all());
        $this->assertSame('Annotation number 8', $stored['ann_8']->annotation_data['text']);

        // The same state again, in full: nothing to write. The delta left it exactly there.
        $expected = array_values(array_filter($all, fn ($a) => ! in_array($a['id'], ['ann_7', 'ann_9'], true)));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->save(['annotations' => [...$expected, $edited, $added]])->assertOk();
        $rewrites = array_filter(DB::getQueryLog(), fn (array $query) => preg_match('/^\s*(insert into|update|delete from) [`"]pdf_state[`"]/i', $query['query']) === 1);
        DB::disableQueryLog();
        $this->assertCount(0, $rewrites);
    }

    public function test_a_delta_that_does_not_add_up_to_the_editors_state_writes_nothing(): void
    {
        $this->save(['annotations' => [$this->annotation('a', 'one'), $this->annotation('b', 'two')]])->assertOk();

        // The editor believes there are three annotations; the server has two.
        $refused = $this->save(['delta' => true, 'annotations' => [$this->annotation('a', 'changed')], 'removed_ids' => [], 'expected_count' => 3, 'base_version' => 1]);

        $refused->assertStatus(409)->assertJson(['success' => false, 'code' => 'delta_base_missing']);
        $this->assertSame(['one', 'two'], $this->storedTexts());
        $this->assertSame(1, (int) DB::table('documents')->where('id', $this->document->id)->value('editor_state_version'), 'the version was not taken');

        // The editor answers with the whole state, which goes through.
        $this->save(['annotations' => [$this->annotation('a', 'changed'), $this->annotation('b', 'two')], 'base_version' => 1])->assertOk();
        $this->assertSame(['changed', 'two'], $this->storedTexts());
    }

    public function test_a_delta_is_still_checked_against_the_version_and_the_limits(): void
    {
        $this->save(['annotations' => [$this->annotation('a', 'one')]])->assertOk();
        $this->save(['annotations' => [$this->annotation('a', 'from another tab')], 'base_version' => 1])->assertOk();

        $this->save(['delta' => true, 'annotations' => [$this->annotation('a', 'stale tab')], 'removed_ids' => [], 'expected_count' => 1, 'base_version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'stale_state');
        $this->assertSame(['from another tab'], $this->storedTexts());

        // A delta without its count, or past the limit, is not a delta the server will apply.
        $this->save(['delta' => true, 'annotations' => [$this->annotation('a', 'no count')], 'base_version' => 2])->assertStatus(422);
        config(['pdf_editor.autosave.max_annotations' => 5]);
        $this->save(['delta' => true, 'annotations' => [$this->annotation('x', 'x')], 'removed_ids' => [], 'expected_count' => 6])->assertStatus(422);
        $this->assertSame(['from another tab'], $this->storedTexts());
    }

    public function test_a_delta_keeps_promoted_source_text_suppressed_or_active_like_a_full_save(): void
    {
        $promoted = fn (string $id, string $key, string $text) => [
            'id' => $id, 'type' => 'text', 'pageIndex' => 0, 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 12, 'text' => $text,
            'promotedFromExtraction' => true, 'promotedSourceKey' => $key,
        ];
        $first = $promoted('promoted_1_1', '0:1:1', 'First paragraph');
        $second = $promoted('promoted_1_2', '0:1:2', 'Second paragraph');
        $this->save(['annotations' => [$first, $second, $this->annotation('note', 'a note')]])->assertOk();
        $full = fn () => PdfState::where('document_id', $this->document->id)->orderBy('id')->get()
            ->map(fn (PdfState $row) => [$row->state, $row->annotation_data['id'] ?? null, $row->annotation_data['promotedSourceKey'] ?? null])->all();
        $before = $full();

        // Only the note changes; both promoted paragraphs are taken from the stored rows.
        $this->save(['delta' => true, 'annotations' => [$this->annotation('note', 'a changed note')], 'removed_ids' => [], 'expected_count' => 3, 'base_version' => 1])->assertOk();

        $this->assertSame($before, $full(), 'no promoted row was dropped, duplicated or turned into a suppression');
    }

    public function test_an_image_is_uploaded_on_its_own_and_the_state_carries_only_the_reference(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $upload = $this->actingAs($this->user)->postJson(route('documents.uploadAnnotationAsset', $this->document), [
            'annotation_id' => 'img_1', 'data_url' => $png, 'file_name' => 'logo.png',
        ]);
        $upload->assertCreated()->assertJson(['success' => true, 'mimeType' => 'image/png', 'fileName' => 'logo.png']);
        $assetPath = $upload->json('assetPath');
        $this->assertStringStartsWith("annotation-assets/documents/{$this->document->id}/img_1_", $assetPath);
        $this->assertStringContainsString("/documents/{$this->document->id}/annotation-assets/", $upload->json('src'));

        // The state that follows has no base64 in it, and the row points at the file.
        $image = ['id' => 'img_1', 'type' => 'image', 'pageIndex' => 0, 'x' => 10, 'y' => 10, 'width' => 40, 'height' => 40, 'dataUrl' => null, 'assetPath' => $assetPath];
        $this->save(['annotations' => [$image]])->assertOk()->assertJsonCount(0, 'assets');
        $this->assertSame($assetPath, PdfState::where('document_id', $this->document->id)->firstOrFail()->annotation_data['assetPath']);

        // Not an image, too large, or not this visitor's document.
        $this->actingAs($this->user)->postJson(route('documents.uploadAnnotationAsset', $this->document), ['annotation_id' => 'x', 'data_url' => 'data:text/html;base64,PHNjcmlwdD4='])->assertStatus(422);
        config(['pdf_editor.autosave.max_image_kb' => 1]);
        $this->actingAs($this->user)->postJson(route('documents.uploadAnnotationAsset', $this->document), ['annotation_id' => 'big', 'data_url' => 'data:image/png;base64,'.str_repeat('A', 4096)])
            ->assertStatus(413)->assertJsonPath('code', 'image_too_large');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->postJson(route('documents.uploadAnnotationAsset', $this->document), ['annotation_id' => 'img_2', 'data_url' => $png])->assertNotFound();
    }

    private function save(array $payload)
    {
        return $this->actingAs($this->user)->postJson(
            route('documents.saveAnnotationState', $this->document),
            $payload + ['session_id' => 'tab-session', 'acro_form_entries' => []]
        );
    }

    private function annotation(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'pageIndex' => 0, 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 12, 'text' => $text];
    }

    private function annotations(int $count): array
    {
        return array_map(fn (int $i) => [
            'id' => "ann_{$i}", 'type' => 'text', 'pageIndex' => $i % 5, 'x' => 40 + ($i % 10) * 50, 'y' => 40.5 + intdiv($i, 10) * 14,
            'width' => 120, 'height' => 12, 'text' => "Annotation number {$i}", 'fontSize' => 10, 'fontFamily' => 'Helvetica',
            'color' => '#111111', 'bold' => false, 'rotation' => 0, 'opacity' => 1, 'style' => ['align' => 'left', 'lineHeight' => 1.2],
        ], range(0, $count - 1));
    }

    /** @return string[] */
    private function storedTexts(): array
    {
        return PdfState::where('document_id', $this->document->id)->where('state', 'saved')->orderBy('id')->get()
            ->map(fn (PdfState $row) => $row->annotation_data['text'] ?? null)->all();
    }
}
