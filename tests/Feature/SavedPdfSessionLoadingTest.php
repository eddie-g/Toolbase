<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PdfState;
use App\Models\User;
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
}