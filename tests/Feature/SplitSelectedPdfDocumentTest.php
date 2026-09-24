<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SplitSelectedPdfDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('original_backup_path')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mode')->nullable();
            $table->timestamp('deleted_at')->nullable(); // Document uses SoftDeletes (trash)
            // Upload processing status (App\Services\DocumentProcessing): "create as a new editor document" queues the extraction.
            $table->string('processing_status', 16)->nullable();
            $table->string('processing_error', 64)->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('processing_queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_selected_pages_are_exported_in_source_order_without_changing_the_working_pdf(): void
    {
        Storage::fake('local');
        $workingPdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        $editedPdf = file_get_contents(base_path('tests/pdfjs/test_pdf_1.pdf'));
        Storage::put('documents/current.pdf', $workingPdf);
        $document = Document::query()->create([
            'original_name' => 'current.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($workingPdf),
        ]);

        $response = $this->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.splitPdf', $document), [
                'mode' => 'selected',
                'page_indices' => [2, 0],
                'output_name' => '../ Quarterly: pages?.PDF',
                'session_id' => 'split-session',
                'pdf' => UploadedFile::fake()->createWithContent('edited.pdf', $editedPdf),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson([
            'success' => true,
            'download_name' => 'Quarterly pages.pdf',
            'selected_pages' => [1, 3],
            'selected_page_count' => 2,
            'source_page_count' => 3,
        ]);

        $token = $response->json('download_token');
        $download = session("converted_download_{$token}");
        $this->assertIsArray($download);
        $this->assertFileExists($download['path']);
        $this->assertSame('Quarterly pages.pdf', $download['name']);
        $this->assertSame($workingPdf, Storage::get('documents/current.pdf'));

        $pageCount = [];
        $exitCode = 0;
        $controller = app(\App\Http\Controllers\DocumentController::class);
        $resolver = new \ReflectionMethod($controller, 'resolvePythonBinaryForPdfEditor');
        $resolver->setAccessible(true);
        $pythonBinary = $resolver->invoke($controller, 'fitz');
        exec(sprintf(
            '%s -c %s %s',
            escapeshellarg($pythonBinary),
            escapeshellarg('import fitz,sys; print(fitz.open(sys.argv[1]).page_count)'),
            escapeshellarg($download['path']),
        ), $pageCount, $exitCode);
        $this->assertSame(0, $exitCode);
        $this->assertSame('2', trim(implode('', $pageCount)));
    }

    public function test_selected_split_rejects_a_page_outside_the_uploaded_pdf(): void
    {
        Storage::fake('local');
        $pdf = file_get_contents(base_path('tests/pdfjs/test_pdf_1.pdf'));
        Storage::put('documents/current.pdf', $pdf);
        $document = Document::query()->create([
            'original_name' => 'current.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
        ]);

        $response = $this->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.splitPdf', $document), [
                'mode' => 'selected',
                'page_indices' => [3],
                'output_name' => 'invalid.pdf',
                'pdf' => UploadedFile::fake()->createWithContent('edited.pdf', $pdf),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame($pdf, Storage::get('documents/current.pdf'));
    }

    public function test_selected_pages_can_be_created_as_a_new_editor_document(): void
    {
        Storage::fake('local');
        Queue::fake();
        $workingPdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        $editedPdf = file_get_contents(base_path('tests/pdfjs/test_pdf_1.pdf'));
        Storage::put('documents/current.pdf', $workingPdf);
        $document = Document::query()->create([
            'original_name' => 'current.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($workingPdf),
        ]);

        $response = $this->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.splitPdf', $document), [
                'mode' => 'selected',
                'output_action' => 'editor',
                'page_indices' => [2, 0],
                'output_name' => 'Editor copy.pdf',
                'session_id' => 'split-editor-session',
                'pdf' => UploadedFile::fake()->createWithContent('edited.pdf', $editedPdf),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson([
            'success' => true,
            'output_action' => 'editor',
            'download_name' => 'Editor copy.pdf',
            'selected_pages' => [1, 3],
            'selected_page_count' => 2,
            'source_page_count' => 3,
        ]);
        $response->assertJsonMissingPath('download_token');

        $splitDocument = Document::query()->findOrFail($response->json('document_id'));
        $this->assertSame('Editor copy.pdf', $splitDocument->original_name);
        $this->assertSame('editor', $splitDocument->mode);
        $this->assertTrue(Storage::exists($splitDocument->path));
        $this->assertTrue(Storage::exists($splitDocument->original_backup_path));
        $this->assertSame(route('documents.editPdfjs', $splitDocument), $response->json('edit_url'));
        $this->assertContains($splitDocument->id, session('pdf_editor_accessible_document_ids'));
        $this->assertSame($workingPdf, Storage::get('documents/current.pdf'));
        $this->get($response->json('edit_url'))->assertRedirect(route('documents.editNew', [
            'document' => $splitDocument,
            'pdfjs' => 1,
        ]));

        Queue::assertPushed(ProcessUploadedDocumentJob::class, fn (ProcessUploadedDocumentJob $job): bool =>
            $job->documentId === $splitDocument->id
        );
    }
}
