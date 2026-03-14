<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\PdfState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestoreOriginalDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_original_restores_pdf_and_deletes_current_users_annotations_for_document(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);
        $otherUser = User::factory()->create([
            'email' => 'other@example.com',
        ]);

        Storage::put('documents/current.pdf', 'modified-pdf');
        Storage::put('documents/original.pdf', 'original-pdf');

        $document = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'invoice.pdf',
            'path' => 'documents/current.pdf',
            'original_backup_path' => 'documents/original.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('modified-pdf'),
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-owner',
            'page_number' => 1,
            'annotation_data' => ['id' => 'owner-1', 'text' => 'owner note'],
            'state' => 'saved',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $user->email,
            'session_id' => 'session-owner',
            'page_number' => 2,
            'annotation_data' => ['id' => 'owner-2', 'text' => 'owner note 2'],
            'state' => 'not_saved',
        ]);
        PdfState::query()->create([
            'document_id' => $document->id,
            'user_email' => $otherUser->email,
            'session_id' => 'session-other',
            'page_number' => 1,
            'annotation_data' => ['id' => 'other-1', 'text' => 'other user note'],
            'state' => 'saved',
        ]);
        $otherDocument = Document::query()->create([
            'user_id' => $user->id,
            'original_name' => 'other.pdf',
            'path' => 'documents/other-current.pdf',
            'original_backup_path' => 'documents/other-original.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $otherDocument->id,
            'user_email' => $user->email,
            'session_id' => 'session-owner',
            'page_number' => 1,
            'annotation_data' => ['id' => 'owner-other-doc', 'text' => 'keep me'],
            'state' => 'saved',
        ]);

        $response = $this->actingAs($user)->postJson(route('documents.restoreOriginal', $document));

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Original PDF restored.',
                'deleted_annotations' => 2,
            ]);

        Storage::assertExists('documents/current.pdf');
        $this->assertSame('original-pdf', Storage::get('documents/current.pdf'));

        $this->assertDatabaseMissing('pdf_state', [
            'document_id' => $document->id,
            'user_email' => $user->email,
        ]);
        $this->assertDatabaseHas('pdf_state', [
            'document_id' => $document->id,
            'user_email' => $otherUser->email,
        ]);
        $this->assertDatabaseHas('pdf_state', [
            'document_id' => $otherDocument->id,
            'user_email' => $user->email,
            'session_id' => 'session-owner',
        ]);
    }
}
