<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfPasswordProtectionTest extends TestCase
{
    private string $testDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDiskRoot = sys_get_temp_dir() . '/netkit_pdf_password_' . bin2hex(random_bytes(8));
        File::makeDirectory($this->testDiskRoot, 0700, true);
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'filesystems.disks.local.root' => $this->testDiskRoot,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Storage::forgetDisk('local');

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
            $table->string('pdf_password_hash')->nullable();
            $table->string('pdf_password_algorithm', 16)->nullable();
            $table->timestamp('pdf_password_set_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        if (isset($this->testDiskRoot)) {
            File::deleteDirectory($this->testDiskRoot);
        }

        parent::tearDown();
    }

    public function test_password_persists_gates_reopening_encrypts_downloads_and_can_be_removed(): void
    {
        $sourcePdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        Storage::put('documents/current.pdf', $sourcePdf);
        $document = Document::query()->create([
            'original_name' => 'invoice.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($sourcePdf),
        ]);
        $session = ['pdf_editor_accessible_document_ids' => [$document->id]];

        $setResponse = $this->withSession($session)
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'set',
                'algorithm' => 'aes-128',
                'persist_protection' => true,
                'password' => 'strong-test-password',
                'password_confirmation' => 'strong-test-password',
            ], ['Accept' => 'application/json']);

        $setResponse->assertOk()->assertJson([
            'success' => true,
            'action' => 'set',
            'persisted' => true,
            'protected' => true,
            'algorithm' => 'aes-128',
        ]);
        $this->assertNotEmpty($setResponse->json('unlock_token'));

        $document->refresh();
        $this->assertTrue(Hash::check('strong-test-password', $document->pdf_password_hash));
        $this->assertNotSame('strong-test-password', $document->pdf_password_hash);
        $this->assertSame('aes-128', $document->pdf_password_algorithm);
        $this->assertNotNull($document->pdf_password_set_at);

        $this->withSession($session)
            ->get(route('documents.file', $document))
            ->assertStatus(423);
        $this->withSession($session)
            ->postJson(route('documents.downloadAnnotatedPdf', $document), [])
            ->assertStatus(423);

        $this->withSession($session)
            ->postJson(route('documents.unlockPdfPassword', $document), [
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'incorrect_password',
            ]);

        $unlockResponse = $this->withSession($session)
            ->postJson(route('documents.unlockPdfPassword', $document), [
                'password' => 'strong-test-password',
            ]);
        $unlockResponse->assertOk()->assertJson([
            'success' => true,
            'protected' => true,
        ]);
        $unlockToken = (string) $unlockResponse->json('unlock_token');
        $this->assertNotSame('', $unlockToken);
        $this->withSession($session)
            ->get(route('documents.file', [
                'document' => $document,
                'pdf_unlock_token' => $unlockToken,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $protectedExportResponse = $this->withSession($session)
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'set',
                'algorithm' => 'aes-128',
                'password' => 'strong-test-password',
                'password_confirmation' => 'strong-test-password',
                'pdf' => UploadedFile::fake()->createWithContent('edited.pdf', $sourcePdf),
            ], ['Accept' => 'application/json']);
        $protectedExportResponse->assertOk()->assertJson([
            'success' => true,
            'action' => 'set',
            'encrypted' => true,
        ]);
        $protectedDownload = session(
            'converted_download_' . $protectedExportResponse->json('download_token')
        );
        $this->assertIsArray($protectedDownload);
        $this->assertFileExists($protectedDownload['path']);
        $this->assertPdfPasswordState(
            $protectedDownload['path'],
            true,
            'strong-test-password',
            true,
        );

        $updateResponse = $this->withSession($session)
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'set',
                'algorithm' => 'aes-128',
                'persist_protection' => true,
                'current_password' => 'strong-test-password',
                'password' => 'updated-test-password',
                'password_confirmation' => 'updated-test-password',
            ], ['Accept' => 'application/json']);

        $updateResponse->assertOk()->assertJson([
            'success' => true,
            'action' => 'set',
            'persisted' => true,
            'protected' => true,
        ]);
        $document->refresh();
        $this->assertFalse(Hash::check('strong-test-password', $document->pdf_password_hash));
        $this->assertTrue(Hash::check('updated-test-password', $document->pdf_password_hash));

        $wrongPasswordResponse = $this->withSession($session)
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'remove',
                'algorithm' => 'aes-128',
                'persist_protection' => true,
                'current_password' => 'strong-test-password',
            ], ['Accept' => 'application/json']);

        $wrongPasswordResponse->assertStatus(422)->assertJson([
            'success' => false,
            'code' => 'incorrect_password',
            'message' => 'Current password is incorrect.',
        ]);

        $removeResponse = $this->withSession($session)
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'remove',
                'algorithm' => 'aes-128',
                'persist_protection' => true,
                'current_password' => 'updated-test-password',
            ], ['Accept' => 'application/json']);

        $removeResponse->assertOk()->assertJson([
            'success' => true,
            'action' => 'remove',
            'persisted' => true,
            'protected' => false,
        ]);

        $document->refresh();
        $this->assertNull($document->pdf_password_hash);
        $this->assertNull($document->pdf_password_algorithm);
        $this->assertNull($document->pdf_password_set_at);
        $this->withSession($session)
            ->get(route('documents.file', $document))
            ->assertOk();
        $this->assertSame($sourcePdf, Storage::get('documents/current.pdf'));
        $this->assertSame(
            [],
            glob(storage_path('app/temp/pdf_password_*')) ?: [],
        );
    }

    public function test_set_requires_matching_password_confirmation(): void
    {
        $sourcePdf = file_get_contents(base_path('tests/OverlayEditor/invoicesample.pdf'));
        Storage::put('documents/current.pdf', $sourcePdf);
        $document = Document::query()->create([
            'original_name' => 'invoice.pdf',
            'path' => 'documents/current.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($sourcePdf),
        ]);

        $response = $this
            ->withSession(['pdf_editor_accessible_document_ids' => [$document->id]])
            ->post(route('documents.encryptPdf', $document), [
                'action' => 'set',
                'algorithm' => 'aes-128',
                'persist_protection' => true,
                'password' => 'first-password',
                'password_confirmation' => 'different-password',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
        $this->assertSame($sourcePdf, Storage::get('documents/current.pdf'));
    }

    private function assertPdfPasswordState(
        string $path,
        bool $needsPassword,
        string $password,
        bool $passwordShouldAuthenticate,
    ): void {
        $controller = app(\App\Http\Controllers\DocumentController::class);
        $resolver = new \ReflectionMethod($controller, 'resolvePythonBinaryForPdfEditor');
        $resolver->setAccessible(true);
        $pythonBinary = $resolver->invoke($controller, 'fitz');
        $output = [];
        $exitCode = 1;
        exec(sprintf(
            '%s -c %s %s %s',
            escapeshellarg($pythonBinary),
            escapeshellarg(
                'import fitz,json,sys;'
                . 'doc=fitz.open(sys.argv[1]);'
                . 'needs=bool(doc.needs_pass);'
                . 'authenticated=(int(doc.authenticate(sys.argv[2]))>0) if needs else False;'
                . 'print(json.dumps({"needs_password":needs,"authenticated":authenticated}))'
            ),
            escapeshellarg($path),
            escapeshellarg($password),
        ), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $state = json_decode(trim(implode("\n", $output)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($needsPassword, $state['needs_password']);
        $this->assertSame($passwordShouldAuthenticate, $state['authenticated']);
    }
}
