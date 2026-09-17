<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production Ready, editor P0: every endpoint that takes a document id from
 * the request body or query enforces the same ownership rule as the
 * {document} route binding, and the "local environment" bypass is gone.
 */
class DocumentOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private function document(?int $userId, string $name = 'doc.pdf'): Document
    {
        return Document::create([
            'user_id' => $userId,
            'original_name' => $name,
            'path' => 'documents/'.uniqid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1234,
            'mode' => 'editor',
        ]);
    }

    private function pdfStateRow(Document $document, string $sessionId = 'sess-owner'): int
    {
        return (int) DB::table('pdf_state')->insertGetId([
            'document_id' => $document->id,
            'user_id' => $document->user_id,
            'session_id' => $sessionId,
            'page_number' => 1,
            'annotation_data' => json_encode(['id' => 'promoted_1_1', 'type' => 'text', 'text' => 'hello', 'page' => 1]),
            'state' => 'saved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_overwrite_annotation_text_refuses_another_users_document(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $theirs = $this->document($b->id);

        $this->actingAs($a)->postJson('/documents/overwrite-annotation-text', [
            'document_id' => $theirs->id, 'annotation_id' => 'promoted_1_1', 'text' => 'pwned',
        ])->assertNotFound();

        // A guest gets the same answer, and cannot tell the id exists.
        $this->flushSession();
        auth()->logout();
        $this->postJson('/documents/overwrite-annotation-text', [
            'document_id' => $theirs->id, 'annotation_id' => 'promoted_1_1', 'text' => 'pwned',
        ])->assertNotFound();
    }

    public function test_stamp_preview_refuses_another_users_document_and_its_state_rows(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $theirs = $this->document($b->id);
        $stateId = $this->pdfStateRow($theirs);

        $this->actingAs($a)->postJson('/pdf-state/stamp-preview', ['document_id' => $theirs->id])->assertNotFound();
        $this->actingAs($a)->postJson('/pdf-state/stamp-preview', ['pdf_state_id' => [$stateId]])->assertNotFound();
    }

    public function test_the_ai_editor_endpoints_refuse_another_users_document(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $theirs = $this->document($b->id);

        $this->actingAs($a)->postJson('/ai/add-to-pdf', ['document_id' => $theirs->id, 'images' => ['data:image/png;base64,AAAA']])->assertNotFound();
        $this->actingAs($a)->getJson('/ai/sections/'.$theirs->id)->assertNotFound();
        $this->actingAs($a)->deleteJson('/ai/sections/'.$theirs->id)->assertNotFound();
        $this->actingAs($a)->postJson('/ai/sections', ['document_id' => (string) $theirs->id, 'sections' => [['type' => 'text']]])->assertNotFound();
        $this->actingAs($a)->postJson('/ai/chat', ['prompt' => 'hello', 'document_id' => (string) $theirs->id])->assertNotFound();
    }

    public function test_bulk_destroy_and_saved_options_stay_inside_what_the_visitor_owns(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        $theirs = $this->document($b->id);
        $this->pdfStateRow($theirs, 'sess-leaked');

        $this->actingAs($a)->post('/documents/bulk-destroy', ['ids' => [$theirs->id]])->assertNotFound();
        $this->assertDatabaseHas('documents', ['id' => $theirs->id]);

        // A guest who somehow knows a saved session id still sees nothing of a document their browser did not create.
        $this->flushSession();
        auth()->logout();
        $this->getJson('/documents/saved-pdf-options?session_ids[]=sess-leaked')
            ->assertOk()
            ->assertJsonCount(0, 'pdfs');
    }

    public function test_a_guest_owns_only_the_documents_their_session_created(): void
    {
        $unowned = $this->document(null, 'guest.pdf');

        $this->postJson('/documents/overwrite-annotation-text', [
            'document_id' => $unowned->id, 'annotation_id' => 'promoted_1_1', 'text' => 'x',
        ])->assertNotFound();

        $response = $this->withSession([DocumentAccess::SESSION_KEY => [$unowned->id]])
            ->postJson('/documents/overwrite-annotation-text', [
                'document_id' => $unowned->id, 'annotation_id' => 'promoted_1_1', 'text' => 'x',
            ]);
        // Past the ownership check the endpoint answers for itself (here: no such annotation yet).
        $this->assertFalse($response->json('success'), 'the creating session passes the ownership check');
        $this->assertStringNotContainsString('Document not found', (string) $response->json('message'));
    }

    public function test_the_local_environment_no_longer_opens_every_unowned_document(): void
    {
        $unowned = $this->document(null, 'guest.pdf');
        $this->app['env'] = 'local';

        $this->postJson('/documents/overwrite-annotation-text', [
            'document_id' => $unowned->id, 'annotation_id' => 'promoted_1_1', 'text' => 'x',
        ])->assertNotFound();

        $this->assertFalse(app(DocumentAccess::class)->canAccess(request(), $unowned));
    }

    public function test_signing_in_claims_the_session_documents_and_keeps_others_out(): void
    {
        $user = User::factory()->create();
        $unowned = $this->document(null, 'guest.pdf');

        $this->withSession([DocumentAccess::SESSION_KEY => [$unowned->id]])
            ->actingAs($user)
            ->postJson('/pdf-state/stamp-preview', ['document_id' => $unowned->id]);

        $this->assertSame($user->id, (int) $unowned->refresh()->user_id, 'the signed-in user now owns the document');

        $this->flushSession();
        $this->actingAs(User::factory()->create())
            ->postJson('/pdf-state/stamp-preview', ['document_id' => $unowned->id])
            ->assertNotFound();
    }
}
