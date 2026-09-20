<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\PdfAcroForm;
use App\Models\PdfState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MergePdfDocumentTest extends TestCase
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
        $this->createMergeTestSchema();
    }

    public function test_authorized_editor_can_merge_whole_pdf_before_current_document_and_state_is_offset(): void
    {
        Storage::fake('local');
        Queue::fake();

        $currentPdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        $addedPdf = file_get_contents(base_path('tests/pdfjs/test_pdf_1.pdf'));
        Storage::put('documents/current.pdf', $currentPdf);
        Storage::put('documents/original.pdf', $currentPdf);

        $document = Document::query()->create([
            'original_name' => 'current.pdf',
            'path' => 'documents/current.pdf',
            'original_backup_path' => 'documents/original.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($currentPdf),
        ]);

        $state = PdfState::query()->create([
            'document_id' => $document->id,
            'session_id' => 'merge-session',
            'page_number' => 0,
            'annotation_data' => [
                'id' => "pdfjs_{$document->id}_0_source:0:2",
                'type' => 'text',
                'pageIndex' => 0,
                'promotedSourceKey' => 'block-1-2',
                'text' => 'Saved edit',
            ],
            'state' => 'saved',
        ]);
        $form = PdfAcroForm::query()->create([
            'document_id' => $document->id,
            'sess_id' => 'merge-session',
            'page_num' => 0,
            'data' => ['key' => 'name', 'pageIndex' => 0, 'value' => 'Ada'],
            'state' => 'saved',
        ]);
        $note = DocumentNote::query()->create([
            'document_id' => $document->id,
            'page_index' => 0,
            'body' => 'Check this value',
        ]);

        $upload = UploadedFile::fake()->createWithContent('added.pdf', $addedPdf);
        $response = $this->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.mergePdfs', $document), [
                'pdfs' => ['upload-added' => $upload],
                'order' => json_encode(['upload-added', 'current']),
                'session_id' => 'merge-session',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'total_pages' => 4,
                'current_document_start_page' => 3,
            ]);

        $state->refresh();
        $form->refresh();
        $note->refresh();
        $document->refresh();

        $this->assertSame(3, $state->page_number);
        $this->assertSame(3, $state->annotation_data['pageIndex']);
        $this->assertSame("pdfjs_{$document->id}_3_source:3:2", $state->annotation_data['id']);
        $this->assertSame('block-4-2', $state->annotation_data['promotedSourceKey']);
        $this->assertSame(3, $form->page_num);
        $this->assertSame(3, $form->data['pageIndex']);
        $this->assertSame(3, $note->page_index);
        $this->assertGreaterThan(strlen($currentPdf), $document->size_bytes);
        $this->assertSame($currentPdf, Storage::get('documents/original.pdf'));

        Queue::assertPushed(ProcessUploadedDocumentJob::class, fn (ProcessUploadedDocumentJob $job): bool => (
            $job->documentId === $document->id && $job->sessionId === 'merge-session'
        ));
    }

    public function test_merge_rejects_an_order_that_omits_an_uploaded_pdf(): void
    {
        Storage::fake('local');
        Queue::fake();
        $pdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        Storage::put('documents/current.pdf', $pdf);
        $document = Document::query()->create([
            'original_name' => 'current.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdf),
        ]);

        $response = $this->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.mergePdfs', $document), [
                'pdfs' => ['upload-added' => UploadedFile::fake()->createWithContent('added.pdf', $pdf)],
                'order' => json_encode(['current']),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The PDF document order is invalid.',
        ]);
        $this->assertSame($pdf, Storage::get('documents/current.pdf'));
        Queue::assertNothingPushed();
    }

    private function createMergeTestSchema(): void
    {
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
            $table->timestamps();
        });
        Schema::create('pdf_extractions_fitz', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pdf_state', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('pdf_extraction_fitz_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('user_email')->nullable();
            $table->string('session_id')->nullable();
            $table->integer('page_number')->nullable();
            $table->json('annotation_data')->nullable();
            $table->string('state')->nullable();
            $table->json('annotation_debug')->nullable();
            $table->timestamps();
        });
        Schema::create('pdf_acro_form', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('sess_id');
            $table->unsignedInteger('page_num')->nullable();
            $table->json('data');
            $table->string('state')->nullable();
            $table->timestamps();
        });
        Schema::create('document_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedInteger('page_index')->nullable();
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('pdf_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('pdf_extraction_fitz_id')->nullable();
            $table->integer('page_number')->nullable();
            $table->string('group_key')->nullable();
            $table->string('root_source_key')->nullable();
            $table->json('annotation_ids')->nullable();
            $table->json('annotation_source_keys')->nullable();
            $table->json('group_data')->nullable();
            $table->timestamps();
        });
    }
}
