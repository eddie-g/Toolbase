<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocumentExportJob;
use App\Models\Document;
use App\Models\DocumentConversion;
use App\Models\DocumentConversionSetting;
use App\Models\User;
use App\Services\AdobePdfServices;
use App\Services\DocumentConversionPricing;
use App\Services\DocumentExportConversionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentConversionQueueTest extends TestCase
{
    private User $user;

    private Document $document;

    private string $testDiskRoot;

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
        $this->testDiskRoot = sys_get_temp_dir().'/netkit_conversion_'.bin2hex(random_bytes(8));
        File::makeDirectory($this->testDiskRoot, 0700, true);
        config(['filesystems.disks.local.root' => $this->testDiskRoot]);
        Storage::forgetDisk('local');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->decimal('credit_balance', 12, 4)->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
        Schema::create('monthly_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('product_key');
            $table->timestamps();
        });
        Schema::create('user_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('monthly_plan_id');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('user_pdf_monthly_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('month_start');
            $table->unsignedInteger('uploads_count')->default(0);
            $table->unsignedInteger('actions_count')->default(0);
            $table->boolean('has_unlimited_actions')->default(false);
            $table->timestamps();
        });
        Schema::create('document_conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('format');
            $table->string('status');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('options');
            $table->json('quote');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('input_path');
            $table->string('output_path')->nullable();
            $table->string('download_name')->nullable();
            $table->string('content_type')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_conversion_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('word_provider', 32)->default('adobe');
            $table->string('excel_provider', 32)->default('adobe');
            $table->boolean('fallback_to_local')->default(false);
            $table->timestamps();
        });
        DocumentConversionSetting::create([
            'word_provider' => DocumentConversionSetting::PROVIDER_ADOBE,
            'excel_provider' => DocumentConversionSetting::PROVIDER_ADOBE,
            'fallback_to_local' => false,
        ]);

        $this->user = User::create([
            'name' => 'Conversion User',
            'email' => 'conversion@example.test',
            'password' => 'password',
            'credit_balance' => 5,
        ]);
        Storage::put('documents/source.pdf', "%PDF-1.4\n%%EOF\n");
        $this->document = Document::create([
            'user_id' => $this->user->id,
            'original_name' => 'source.pdf',
            'path' => 'documents/source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => Storage::size('documents/source.pdf'),
        ]);

        $service = Mockery::mock(DocumentExportConversionService::class);
        $service->shouldReceive('quote')->andReturn([
            'page_count' => 2,
            'pages_per_transaction' => 50,
            'transactions' => 1,
            'price_per_transaction' => 0.10,
            'charge_usd' => 0.10,
        ]);
        $this->app->instance(DocumentExportConversionService::class, $service);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        if (isset($this->testDiskRoot)) {
            File::deleteDirectory($this->testDiskRoot);
        }

        parent::tearDown();
    }

    public function test_word_and_excel_exports_are_dispatched_to_the_horizon_queue(): void
    {
        $word = $this->actingAs($this->user)->post(route('documents.convertToWord', $this->document), [
            'layout' => 'exact',
            'include_images' => true,
            'ocr' => false,
            'pdf' => UploadedFile::fake()->createWithContent(
                'edited.pdf',
                file_get_contents(base_path('public/ss-5.pdf'))
            ),
        ], ['Accept' => 'application/json']);
        $word->assertStatus(202)->assertJson([
            'success' => true,
            'queued' => true,
            'status' => 'queued',
        ]);
        $this->assertNotEmpty($word->json('status_url'));

        $excel = $this->actingAs($this->user)->postJson(route('documents.convertToExcel', $this->document), [
            'mode' => 'all',
            'merge_cells' => true,
            'sheet_per_page' => true,
        ]);
        $excel->assertStatus(202)->assertJson([
            'success' => true,
            'queued' => true,
            'status' => 'queued',
        ]);

        Queue::assertPushed(ConvertDocumentExportJob::class, 2);
        Queue::assertPushed(ConvertDocumentExportJob::class, function (ConvertDocumentExportJob $job): bool {
            return $job->connection === 'redis' && $job->queue === 'document-conversion';
        });
        $this->assertDatabaseCount('document_conversions', 2);
        $this->assertTrue((bool) DocumentConversion::where('format', 'word')->first()->options['visual_fidelity']);
    }

    public function test_completed_conversion_status_exposes_an_authorized_download(): void
    {
        Storage::put('documents/conversions/test/output.docx', 'word-content');
        $conversion = DocumentConversion::create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'document_id' => $this->document->id,
            'user_id' => $this->user->id,
            'format' => 'word',
            'status' => DocumentConversion::STATUS_COMPLETED,
            'progress' => 100,
            'options' => ['layout' => 'exact'],
            'quote' => ['charge_usd' => 0.10],
            'result' => ['charge_usd' => 0.10, 'engine' => 'netkit'],
            'input_path' => 'documents/conversions/test/input.pdf',
            'output_path' => 'documents/conversions/test/output.docx',
            'download_name' => 'source.docx',
            'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'expires_at' => now()->addMinutes(10),
        ]);

        $status = $this->actingAs($this->user)->getJson(route('documents.conversions.status', [
            $this->document,
            $conversion,
        ]));
        $status->assertOk()->assertJson([
            'success' => true,
            'status' => 'completed',
            'download_token' => $conversion->uuid,
            'download_name' => 'source.docx',
        ]);

        $this->actingAs($this->user)
            ->get(route('documents.downloadConverted', ['token' => $conversion->uuid]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=source.docx');
    }

    public function test_edited_word_export_is_prepared_then_sent_to_adobe(): void
    {
        $sourcePath = Storage::path('documents/edited-source.pdf');
        File::copy(base_path('public/ss-5.pdf'), $sourcePath);
        $outputPath = Storage::path('documents/conversions/adobe-output.docx');
        $adobeInputPath = null;

        $adobe = Mockery::mock(AdobePdfServices::class);
        $adobe->shouldReceive('export')
            ->once()
            ->withArgs(function (string $inputPath, string $actualOutputPath, string $format) use (&$adobeInputPath, $sourcePath, $outputPath): bool {
                $adobeInputPath = $inputPath;
                $this->assertNotSame($sourcePath, $inputPath);
                $this->assertFileExists($inputPath);
                $this->assertGreaterThan(0, filesize($inputPath));
                $this->assertSame($outputPath, $actualOutputPath);
                $this->assertSame('docx', $format);
                File::ensureDirectoryExists(dirname($actualOutputPath));
                File::put($actualOutputPath, 'adobe-word-content');

                return true;
            })
            ->andReturn([
                'success' => true,
                'engine' => 'adobe_pdf_services',
                'provider' => 'adobe',
            ]);

        $service = new DocumentExportConversionService(
            app(DocumentConversionPricing::class),
            $adobe,
        );
        $conversion = new DocumentConversion([
            'format' => 'word',
            'options' => ['visual_fidelity' => true],
        ]);

        $result = $service->convert($conversion, $sourcePath, $outputPath);

        $this->assertSame('adobe', $result['provider']);
        $this->assertSame('adobe', $result['provider_requested']);
        $this->assertTrue($result['prepared_for_adobe']);
        $this->assertTrue($result['native_text_preserved']);
        $this->assertTrue($result['vector_shapes_preserved']);
        $this->assertTrue($result['image_layers_preserved']);
        $this->assertFalse($result['provider_fallback_used']);
        $this->assertFalse($result['provider_bypassed_for_visual_fidelity']);
        $this->assertNotNull($adobeInputPath);
        $this->assertFileDoesNotExist($adobeInputPath);
        $this->assertFileExists($outputPath);
    }
}
