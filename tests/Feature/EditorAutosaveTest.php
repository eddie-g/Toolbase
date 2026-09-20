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
 * The editor's autosave (POST documents/{document}/save-annotation-state):
 * written as a diff in a handful of statements, refused when another tab has
 * saved a newer state, bounded in size, and rate limited per editor.
 */
class EditorAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->document = Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'autosave.pdf',
            'path' => 'documents/autosave.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
    }

    public function test_a_save_writes_only_what_changed_in_a_handful_of_statements(): void
    {
        $annotations = $this->annotations(500);

        $first = $this->countingWrites(fn () => $this->save(['annotations' => $annotations])->assertOk());
        $this->assertSame(500, PdfState::where('document_id', $this->document->id)->count());
        $this->assertLessThanOrEqual(4, $first['pdf_state'], '500 new annotations go in as bulk inserts');

        $idsBefore = PdfState::where('document_id', $this->document->id)->orderBy('id')->pluck('id')->all();
        $stampsBefore = PdfState::where('document_id', $this->document->id)->pluck('updated_at', 'id')->map->toDateTimeString()->all();
        $this->travel(5)->minutes();

        // The usual autosave: the whole state again, nothing different.
        $unchanged = $this->countingWrites(fn () => $this->save(['annotations' => $annotations])->assertOk());
        $this->assertSame(0, $unchanged['pdf_state'], 'an unchanged state rewrites no annotation rows');

        // One edit, sent with the keys in another order, as MySQL's JSON type hands them back.
        $edited = $annotations;
        $edited[7]['text'] = 'edited once';
        $edited[8] = array_reverse($edited[8], true);
        $oneEdit = $this->countingWrites(fn () => $this->save(['annotations' => $edited])->assertOk());
        $this->assertSame(1, $oneEdit['pdf_state'], 'one edited annotation is one statement');
        $this->assertLessThanOrEqual(12, $oneEdit['all'], 'the whole save stays a handful of statements');

        $rows = PdfState::where('document_id', $this->document->id)->get()->keyBy(fn ($row) => $row->annotation_data['id']);
        $this->assertSame('edited once', $rows['ann_7']->annotation_data['text']);
        $this->assertSame($idsBefore, $rows->pluck('id')->sort()->values()->all(), 'rows are updated in place, not recreated');
        $this->assertNotSame($stampsBefore[$rows['ann_7']->id], $rows['ann_7']->updated_at->toDateTimeString());
        $this->assertSame($stampsBefore[$rows['ann_8']->id], $rows['ann_8']->updated_at->toDateTimeString(), 'key order alone is not a change');
        $this->assertSame($stampsBefore[$rows['ann_9']->id], $rows['ann_9']->updated_at->toDateTimeString());

        // Removing, adding and editing in one save: still bulk, and the row set follows the payload.
        $next = array_slice($edited, 0, 400);
        $next[3]['text'] = 'edited too';
        $next[] = ['id' => 'ann_new', 'type' => 'text', 'pageIndex' => 2, 'text' => 'new'];
        $mixed = $this->countingWrites(fn () => $this->save(['annotations' => $next])->assertOk());
        $this->assertLessThanOrEqual(3, $mixed['pdf_state'], 'one upsert, one insert, one delete');
        $this->assertSame(401, PdfState::where('document_id', $this->document->id)->count());
        $this->assertSame(1, PdfState::where('document_id', $this->document->id)->where('annotation_data->id', 'ann_new')->where('page_number', 2)->where('user_id', $this->user->id)->count());
        $this->assertSame(0, PdfState::where('document_id', $this->document->id)->where('annotation_data->id', 'ann_450')->count());
    }

    public function test_a_second_tab_cannot_silently_overwrite_the_first(): void
    {
        $info = $this->actingAs($this->user)
            ->getJson(route('pdfTests.documentInfo', $this->document).'?session_id=tab&skip_embedded_fonts=1');
        $info->assertOk();
        $loaded = $info->json('state_version');
        $this->assertSame(0, $loaded, 'both tabs load version 0');

        // Tab A saves.
        $a = $this->save(['annotations' => [$this->annotation('a', 'from tab A')], 'base_version' => $loaded]);
        $a->assertOk()->assertJson(['success' => true, 'state_version' => 1]);

        // Tab B, still on version 0, is refused and nothing of A's is touched.
        $b = $this->save(['annotations' => [$this->annotation('b', 'from tab B')], 'base_version' => $loaded]);
        $b->assertStatus(409)->assertJson(['success' => false, 'code' => 'stale_state', 'state_version' => 1]);
        $this->assertStringContainsString('another tab', $b->json('message'));
        $this->assertSame(['from tab A'], $this->storedTexts());
        $this->assertSame(1, (int) $this->document->fresh()->editor_state_version);

        // Tab A carries on from the version its save returned.
        $this->save(['annotations' => [$this->annotation('a', 'A again')], 'base_version' => 1])
            ->assertOk()->assertJson(['state_version' => 2]);
        $this->assertSame(['A again'], $this->storedTexts());

        // After a reload tab B has the current version and may save.
        $this->save(['annotations' => [$this->annotation('a', 'A again'), $this->annotation('b', 'from tab B')], 'base_version' => 2])
            ->assertOk()->assertJson(['state_version' => 3]);

        // Tools that send no version are not checked, and still move it on.
        $this->save(['annotations' => [$this->annotation('a', 'tool')]])->assertOk()->assertJson(['state_version' => 4]);

        // The version is not a reason to reorder the documents list.
        $this->assertSame(
            $this->document->updated_at->toDateTimeString(),
            $this->document->fresh()->updated_at->toDateTimeString()
        );
    }

    public function test_oversized_saves_are_refused_with_a_message_for_the_user(): void
    {
        config([
            'pdf_editor.autosave.max_annotations' => 5,
            'pdf_editor.autosave.max_text_length' => 100,
            'pdf_editor.autosave.max_body_kb' => 8,
        ]);

        $tooMany = $this->save(['annotations' => $this->annotations(6)]);
        $tooMany->assertStatus(422);
        $this->assertStringContainsString('at most 5 edited items', $tooMany->json('errors.annotations.0'));

        $tooLong = $this->save(['annotations' => [$this->annotation('a', 'ok'), ['id' => 'b', 'type' => 'text', 'pageIndex' => 1, 'text' => str_repeat('x', 101)]]]);
        $tooLong->assertStatus(422);
        $this->assertStringContainsString('page 2 is too long', $tooLong->json('errors')['annotations.1.text'][0]);

        $this->save(['annotations' => [['id' => 'c', 'type' => 'text', 'pageIndex' => 0, 'text' => 'ok', 'richTextHtml' => str_repeat('x', 400)]]])
            ->assertOk();
        $this->save(['annotations' => [['id' => 'c', 'type' => 'text', 'pageIndex' => 0, 'richTextHtml' => str_repeat('x', 401)]]])
            ->assertStatus(422);

        // The per-annotation checks keep the validator's shape.
        $missing = $this->save(['annotations' => [['id' => 'd', 'pageIndex' => 0], ['id' => 'e', 'type' => 'text']]]);
        $missing->assertStatus(422)->assertJsonValidationErrors(['annotations.0.type', 'annotations.1.pageIndex']);

        $tooBig = $this->save(['annotations' => [$this->annotation('f', str_repeat('y', 90))], 'padding' => str_repeat('z', 9000)]);
        $tooBig->assertStatus(413)->assertJson(['success' => false, 'code' => 'payload_too_large']);
        $this->assertStringContainsString('too much edited content', $tooBig->json('message'));

        $this->assertSame(['ok'], $this->storedTexts(), 'a refused save stores nothing');
        $this->assertSame(1, (int) $this->document->fresh()->editor_state_version, 'and does not move the version');
    }

    public function test_an_inline_image_is_stored_once_and_answered_with_its_reference(): void
    {
        Storage::fake('public');
        Storage::fake('local');   // where annotation assets are written
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $image = ['id' => 'img_1', 'type' => 'image', 'pageIndex' => 0, 'x' => 10, 'y' => 10, 'width' => 40, 'height' => 40, 'dataUrl' => $png];

        $first = $this->save(['annotations' => [$image, $this->annotation('t', 'text')]]);
        $first->assertOk();
        $assetPath = $first->json('assets.img_1.assetPath');
        $this->assertNotEmpty($assetPath);
        $this->assertNotEmpty($first->json('assets.img_1.src'));
        $this->assertArrayNotHasKey('t', $first->json('assets'));

        $stored = PdfState::where('document_id', $this->document->id)->where('annotation_data->id', 'img_1')->firstOrFail();
        $this->assertSame($assetPath, $stored->annotation_data['assetPath']);
        $this->assertNull($stored->annotation_data['dataUrl'], 'no base64 in the database row');

        // What the editor sends from then on (autosave-guard.js): the
        // reference and the fields the server derived, no data.
        $byReference = [
            'dataUrl' => null,
            'assetPath' => $assetPath,
            'mimeType' => $first->json('assets.img_1.mimeType'),
            'fileName' => $first->json('assets.img_1.fileName'),
        ] + $image;
        $writes = $this->countingWrites(fn () => $this->save(['annotations' => [$byReference, $this->annotation('t', 'text')]])
            ->assertOk()
            ->assertJsonCount(0, 'assets'));
        $this->assertSame(0, $writes['pdf_state'], 'the reference is the same stored state');
    }

    public function test_autosave_is_rate_limited_per_editor_and_document_and_says_when_to_retry(): void
    {
        config(['pdf_editor.autosave.saves_per_minute' => 3]);
        $payload = ['annotations' => [$this->annotation('a', 'x')]];

        foreach (range(1, 3) as $attempt) {
            $this->save($payload)->assertOk();
        }
        $limited = $this->save($payload);
        $limited->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        // Another document of the same editor has its own allowance...
        $other = Document::query()->create([
            'user_id' => $this->user->id,
            'original_name' => 'other.pdf',
            'path' => 'documents/other.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
        $this->save($payload, $other)->assertOk();

        // ...and so does another editor behind the same address.
        $colleague = User::factory()->create();
        $theirs = Document::query()->create([
            'user_id' => $colleague->id,
            'original_name' => 'theirs.pdf',
            'path' => 'documents/theirs.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);
        $this->actingAs($colleague)
            ->postJson(route('documents.saveAnnotationState', $theirs), $payload + ['session_id' => 'colleague'])
            ->assertOk();

        $this->travel(61)->seconds();
        $this->save($payload)->assertOk();
    }

    private function save(array $payload, ?Document $document = null)
    {
        return $this->actingAs($this->user)->postJson(
            route('documents.saveAnnotationState', $document ?? $this->document),
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

    /** @return string[] the text of every stored annotation of the document */
    private function storedTexts(): array
    {
        return PdfState::where('document_id', $this->document->id)->orderBy('id')->get()
            ->map(fn (PdfState $row) => $row->annotation_data['text'] ?? null)->all();
    }

    /** @return array{all:int, pdf_state:int} statements run, and the writes among them that touch pdf_state */
    private function countingWrites(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'all' => count($log),
            'pdf_state' => count(array_filter($log, fn (array $query) => preg_match('/^\s*(insert into|update|delete from) [`"]pdf_state[`"]/i', $query['query']) === 1)),
        ];
    }
}
