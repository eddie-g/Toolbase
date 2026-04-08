<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Document;
use App\Models\PdfState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminOwnedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_owned_document_appears_on_recent_documents_index(): void
    {
        $admin = $this->createAdmin('owner@example.com');
        $otherAdmin = $this->createAdmin('other@example.com');

        Document::query()->create([
            'admin_id' => $admin->id,
            'original_name' => 'admin-visible.pdf',
            'path' => 'documents/admin-visible.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        Document::query()->create([
            'admin_id' => $otherAdmin->id,
            'original_name' => 'admin-hidden.pdf',
            'path' => 'documents/admin-hidden.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('admin-visible.pdf')
            ->assertDontSee('admin-hidden.pdf');
    }

    public function test_admin_owned_saved_annotations_are_loaded_via_admin_ownership(): void
    {
        $admin = $this->createAdmin('owner@example.com');

        $document = Document::query()->create([
            'admin_id' => $admin->id,
            'original_name' => 'admin-owned.pdf',
            'path' => 'documents/admin-owned.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        PdfState::query()->create([
            'document_id' => $document->id,
            'admin_id' => $admin->id,
            'user_email' => null,
            'session_id' => 'admin-session',
            'page_number' => 0,
            'annotation_data' => ['id' => 'admin-1', 'type' => 'text', 'text' => 'admin owned note'],
            'state' => 'saved',
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('documents.getSavedAnnotations', $document))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'session_id' => 'admin-session',
                'count' => 1,
            ])
            ->assertJsonPath('annotations.0.id', 'admin-1');
    }

    public function test_admin_save_annotations_persists_rows_by_admin_id(): void
    {
        $admin = $this->createAdmin('owner@example.com');

        $document = Document::query()->create([
            'admin_id' => $admin->id,
            'original_name' => 'admin-save.pdf',
            'path' => 'documents/admin-save.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')->postJson(route('documents.saveAnnotations', $document), [
            'session_id' => 'admin-session',
            'annotations' => [
                [
                    'id' => 'admin-save-1',
                    'type' => 'text',
                    'pageIndex' => 0,
                    'text' => 'admin note',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('pdf_state', [
            'document_id' => $document->id,
            'session_id' => 'admin-session',
            'user_id' => null,
            'admin_id' => $admin->id,
            'user_email' => null,
            'state' => 'not_saved',
        ]);
    }

    private function createAdmin(string $email): Admin
    {
        return Admin::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
