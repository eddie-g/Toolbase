<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTrashAndDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_streams_the_stored_pdf_with_its_own_name(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        $document = $this->createStoredDocument($admin, 'Quarterly report.pdf');

        $response = $this->actingAs($admin, 'admin')->get(route('documents.download', $document));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="Quarterly report.pdf"');
        $this->assertSame('%PDF-1.4 stored', $response->streamedContent());
    }

    public function test_move_to_trash_hides_the_document_but_keeps_its_files(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        $document = $this->createStoredDocument($admin, 'keep-me.pdf');

        $this->actingAs($admin, 'admin')
            ->post(route('documents.trash', $document))
            ->assertRedirect(route('documents.index'));

        $this->assertNotNull($document->fresh()?->deleted_at ?? Document::withTrashed()->find($document->id)?->deleted_at);
        $this->assertNull(Document::find($document->id));
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('local')->assertExists($document->original_backup_path);

        // Gone from the list and from the editor, present in the trash.
        // The flashed status quotes the name, so look for the card itself.
        $this->actingAs($admin, 'admin')->get(route('documents.index'))->assertOk()->assertDontSee('Select keep-me.pdf');
        $this->actingAs($admin, 'admin')->get(route('documents.index', ['view' => 'trash']))->assertOk()->assertSee('keep-me.pdf')->assertSee('Trashed');
        $this->actingAs($admin, 'admin')->get(route('documents.editPdfjs', $document))->assertNotFound();
        // Download still works from the trash, so a file can be saved before it is deleted for good.
        $this->actingAs($admin, 'admin')->get(route('documents.download', $document))->assertOk();
    }

    public function test_restore_brings_a_trashed_document_back(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        $document = $this->createStoredDocument($admin, 'back-again.pdf');
        $document->delete();

        $this->actingAs($admin, 'admin')
            ->post(route('documents.restore', $document))
            ->assertRedirect(route('documents.index'));

        $this->assertNotNull(Document::find($document->id));
        $this->actingAs($admin, 'admin')->get(route('documents.index'))->assertOk()->assertSee('back-again.pdf');
    }

    public function test_delete_permanently_removes_the_row_and_its_files(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        $document = $this->createStoredDocument($admin, 'gone.pdf');
        $document->delete();

        $this->actingAs($admin, 'admin')
            ->delete(route('documents.destroy', $document))
            ->assertRedirect(route('documents.index', ['view' => 'trash']));

        $this->assertNull(Document::withTrashed()->find($document->id));
        Storage::disk('local')->assertMissing($document->path);
        Storage::disk('local')->assertMissing($document->original_backup_path);
    }

    public function test_bulk_delete_moves_the_selection_to_the_trash_and_empty_trash_removes_it(): void
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        $first = $this->createStoredDocument($admin, 'first.pdf');
        $second = $this->createStoredDocument($admin, 'second.pdf');
        $kept = $this->createStoredDocument($admin, 'kept.pdf');

        $this->actingAs($admin, 'admin')
            ->post(route('documents.bulkDestroy'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('documents.index'))
            ->assertSessionHas('status', '2 documents moved to the trash.');

        $this->assertSame(2, Document::onlyTrashed()->count());
        Storage::disk('local')->assertExists($first->path);
        $this->assertNotNull(Document::find($kept->id));

        $this->actingAs($admin, 'admin')
            ->post(route('documents.emptyTrash'))
            ->assertRedirect(route('documents.index'));

        $this->assertSame(0, Document::withTrashed()->onlyTrashed()->count());
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertMissing($second->path);
        Storage::disk('local')->assertExists($kept->path);
    }

    public function test_another_admin_cannot_trash_restore_or_download_a_document(): void
    {
        Storage::fake('local');
        $owner = $this->createAdmin('owner@example.com');
        $stranger = $this->createAdmin('stranger@example.com');
        $document = $this->createStoredDocument($owner, 'private.pdf');

        $this->actingAs($stranger, 'admin')->post(route('documents.trash', $document))->assertNotFound();
        $this->actingAs($stranger, 'admin')->get(route('documents.download', $document))->assertNotFound();
        $document->delete();
        $this->actingAs($stranger, 'admin')->post(route('documents.restore', $document))->assertNotFound();
        $this->actingAs($stranger, 'admin')->delete(route('documents.destroy', $document))->assertNotFound();
        $this->assertNotNull(Document::withTrashed()->find($document->id));
    }

    private function createAdmin(string $email = 'admin@example.com'): Admin
    {
        return Admin::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function createStoredDocument(Admin $admin, string $name): Document
    {
        $path = 'documents/' . str()->uuid() . '.pdf';
        $backup = 'documents/originals/' . str()->uuid() . '_original.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 stored');
        Storage::disk('local')->put($backup, '%PDF-1.4 original');

        return Document::query()->create([
            'admin_id' => $admin->id,
            'original_name' => $name,
            'path' => $path,
            'original_backup_path' => $backup,
            'mime_type' => 'application/pdf',
            'size_bytes' => 15,
            'mode' => 'editor',
        ]);
    }
}
