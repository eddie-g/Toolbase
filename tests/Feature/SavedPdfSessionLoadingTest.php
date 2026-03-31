<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PdfState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedPdfSessionLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_saved_annotations_honors_requested_session_instead_of_falling_back(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'session-test.pdf',
            'path' => 'documents/session-test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-full',
            'page_number' => 1,
            'annotation_data' => ['id' => 'full-1', 'type' => 'text', 'text' => 'full session first'],
            'state' => 'materialized',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-full',
            'page_number' => 1,
            'annotation_data' => ['id' => 'full-2', 'type' => 'shape', 'shapeType' => 'rect'],
            'state' => 'materialized',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-partial',
            'page_number' => 1,
            'annotation_data' => ['id' => 'partial-1', 'type' => 'text', 'text' => 'partial session only'],
            'state' => 'saved',
        ]);

        $response = $this->actingAs($user)->getJson(
            route('documents.getSavedAnnotations', $document) . '?session_id=session-full'
        );

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'session_id' => 'session-full',
                'count' => 2,
            ]);

        $annotationIds = collect($response->json('annotations'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['full-1', 'full-2'], $annotationIds);
    }

    public function test_saved_pdf_options_return_distinct_entries_per_document_session(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'session-list.pdf',
            'path' => 'documents/session-list.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-a',
            'page_number' => 1,
            'annotation_data' => ['id' => 'a-1', 'type' => 'text', 'text' => 'A1'],
            'state' => 'saved',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-a',
            'page_number' => 2,
            'annotation_data' => ['id' => 'a-2', 'type' => 'shape', 'shapeType' => 'star'],
            'state' => 'saved',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-b',
            'page_number' => 1,
            'annotation_data' => ['id' => 'b-1', 'type' => 'text', 'text' => 'B1'],
            'state' => 'saved',
        ]);

        $response = $this->actingAs($user)->getJson(route('documents.savedPdfOptions'));

        $response->assertOk();

        $entries = collect($response->json('pdfs'))
            ->where('document_id', $document->id)
            ->values();

        $this->assertCount(2, $entries);

        $sessionCounts = $entries
            ->mapWithKeys(static fn (array $entry) => [$entry['session_id'] => $entry['annotation_count']])
            ->all();
        ksort($sessionCounts);

        $this->assertSame([
            'session-a' => 2,
            'session-b' => 1,
        ], $sessionCounts);
    }

    public function test_get_saved_annotations_prefers_latest_session_even_if_not_saved(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'draft-session-test.pdf',
            'path' => 'documents/draft-session-test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-saved',
            'page_number' => 1,
            'annotation_data' => ['id' => 'saved-1', 'type' => 'text', 'text' => 'older saved session'],
            'state' => 'saved',
            'updated_at' => CarbonImmutable::parse('2026-03-29 10:00:00'),
            'created_at' => CarbonImmutable::parse('2026-03-29 10:00:00'),
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-draft',
            'page_number' => 1,
            'annotation_data' => ['id' => 'draft-1', 'type' => 'text', 'text' => 'newer draft session'],
            'state' => 'not_saved',
            'updated_at' => CarbonImmutable::parse('2026-03-30 10:00:00'),
            'created_at' => CarbonImmutable::parse('2026-03-30 10:00:00'),
        ]);

        $response = $this->actingAs($user)->getJson(route('documents.getSavedAnnotations', $document));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'session_id' => 'session-draft',
                'count' => 1,
            ]);

        $annotationIds = collect($response->json('annotations'))
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSame(['draft-1'], $annotationIds);
    }

    public function test_save_annotation_state_infers_deleted_promoted_source_keys_from_missing_session_annotations(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'promoted-delete-save.pdf',
            'path' => 'documents/promoted-delete-save.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => null,
            'session_id' => 'session-promoted',
            'page_number' => 0,
            'annotation_data' => [
                'id' => 'promoted_1_4',
                'type' => 'text',
                'pageIndex' => 0,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-4',
                'text' => 'Old promoted block',
            ],
            'state' => 'not_saved',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => null,
            'session_id' => 'session-promoted',
            'page_number' => 0,
            'annotation_data' => [
                'id' => 'promoted_1_5',
                'type' => 'text',
                'pageIndex' => 0,
                'promotedFromExtraction' => true,
                'promotedSourceKey' => 'block-1-5',
                'text' => 'Kept promoted block',
            ],
            'state' => 'not_saved',
        ]);

        $saveResponse = $this->actingAs($user)->postJson(
            route('documents.saveAnnotationState', $document),
            [
                'session_id' => 'session-promoted',
                'annotations' => [
                    [
                        'id' => 'promoted_1_5',
                        'type' => 'text',
                        'pageIndex' => 0,
                        'promotedFromExtraction' => true,
                        'promotedSourceKey' => 'block-1-5',
                        'text' => 'Kept promoted block',
                    ],
                ],
                'session_annotations' => [
                    [
                        'id' => 'promoted_1_5',
                        'type' => 'text',
                        'pageIndex' => 0,
                        'promotedFromExtraction' => true,
                        'promotedSourceKey' => 'block-1-5',
                        'text' => 'Kept promoted block',
                    ],
                ],
                'deleted_promoted_source_keys' => [],
            ]
        );

        $saveResponse->assertOk()
            ->assertJson([
                'success' => true,
                'session_id' => 'session-promoted',
            ]);

        $loadResponse = $this->actingAs($user)->getJson(
            route('documents.getSavedAnnotations', $document) . '?session_id=session-promoted'
        );

        $loadResponse->assertOk()
            ->assertJson([
                'success' => true,
                'session_id' => 'session-promoted',
                'count' => 1,
            ]);

        $this->assertSame(
            ['promoted_1_5'],
            collect($loadResponse->json('annotations'))->pluck('id')->values()->all()
        );
        $this->assertSame(
            ['block-1-4'],
            collect($loadResponse->json('deleted_promoted_source_keys'))->sort()->values()->all()
        );

        $this->assertDatabaseMissing('pdf_state', [
            'document_id' => $document->id,
            'session_id' => 'session-promoted',
            'state' => 'not_saved',
        ]);
        $this->assertDatabaseHas('pdf_state', [
            'document_id' => $document->id,
            'session_id' => 'session-promoted',
            'state' => 'deleted',
        ]);
    }
}
