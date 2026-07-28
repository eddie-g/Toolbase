<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Document;
use App\Models\PdfUploadTest;
use App\Models\PdfUploadTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PdfUploadTestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:admin');
    }

    public function index()
    {
        $adminId = (int) Auth::guard('admin')->id();
        $fixtures = PdfUploadTest::query()
            ->select([
                'id',
                'uuid',
                'admin_id',
                'document_id',
                'original_name',
                'mime_type',
                'size_bytes',
                'sha256',
                'paragraph_grouping_enabled',
                'created_at',
                'updated_at',
            ])
            ->where('admin_id', $adminId)
            ->with([
                'document:id,admin_id,original_name,path,original_backup_path,mime_type,size_bytes,mode,created_at,updated_at',
                'cases' => fn ($query) => $query
                    ->orderBy('page_index')
                    ->orderBy('id'),
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'tests' => $fixtures
                ->map(fn (PdfUploadTest $fixture) => $this->serializeFixture($fixture))
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'paragraph_grouping_enabled' => ['nullable', 'boolean'],
        ]);

        $admin = Auth::guard('admin')->user();
        abort_unless($admin, 401);

        $uploadedFile = $validated['pdf'];
        $contents = file_get_contents($uploadedFile->getRealPath());
        if ($contents === false || $contents === '') {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded PDF could not be read.',
            ], 422);
        }

        $uuid = Str::uuid()->toString();
        $originalName = $this->normalizePdfName((string) $uploadedFile->getClientOriginalName());
        $directory = 'pdf-upload-tests/'.$uuid;
        $documentPath = $directory.'/current.pdf';
        $originalPath = $directory.'/original.pdf';

        if (! Storage::put($documentPath, $contents) || ! Storage::put($originalPath, $contents)) {
            Storage::deleteDirectory($directory);

            return response()->json([
                'success' => false,
                'message' => 'The uploaded PDF could not be stored.',
            ], 500);
        }

        try {
            [$document, $fixture] = DB::transaction(function () use (
                $admin,
                $contents,
                $documentPath,
                $originalName,
                $originalPath,
                $uploadedFile,
                $uuid,
                $validated
            ) {
                $document = Document::query()->create([
                    'user_id' => null,
                    'admin_id' => $admin->id,
                    'original_name' => $originalName,
                    'path' => $documentPath,
                    'original_backup_path' => $originalPath,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => strlen($contents),
                    'mode' => 'regression',
                ]);

                $fixture = PdfUploadTest::query()->create([
                    'uuid' => $uuid,
                    'admin_id' => $admin->id,
                    'document_id' => $document->id,
                    'original_name' => $originalName,
                    'mime_type' => $uploadedFile->getClientMimeType() ?: 'application/pdf',
                    'size_bytes' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                    'pdf_base64' => base64_encode($contents),
                    'paragraph_grouping_enabled' => (bool) ($validated['paragraph_grouping_enabled'] ?? false),
                ]);

                return [$document, $fixture];
            });
        } catch (\Throwable $error) {
            Storage::deleteDirectory($directory);
            report($error);

            return response()->json([
                'success' => false,
                'message' => 'The PDF upload test record could not be created.',
            ], 500);
        }

        ProcessUploadedDocumentJob::dispatch(
            $document->id,
            is_string($admin->email ?? null) ? $admin->email : null,
            $request->session()->getId()
        );

        $fixture->setRelation('document', $document);

        return response()->json([
            'success' => true,
            'message' => 'PDF uploaded. Opening the annotation review.',
            'test' => $this->serializeFixture($fixture),
        ], 201);
    }

    public function review(PdfUploadTest $pdfUploadTest)
    {
        $this->authorizeFixture($pdfUploadTest);
        $pdfUploadTest->load([
            'document',
            'cases' => fn ($query) => $query
                ->orderByDesc('test_saved_at')
                ->orderByDesc('id'),
        ]);
        abort_unless($pdfUploadTest->document, 404);

        return view('documents.edit-new-pdfjs', [
            'document' => $pdfUploadTest->document,
            'pdfUploadTest' => $pdfUploadTest,
            'pdfUploadTestCases' => $pdfUploadTest->cases,
            'selectedPdfUploadTestCase' => $pdfUploadTest->cases->first(),
            'uploadTestReview' => true,
        ]);
    }

    public function update(Request $request, PdfUploadTest $pdfUploadTest)
    {
        $this->authorizeFixture($pdfUploadTest);

        $validated = $request->validate([
            'annotation_id' => ['required', 'string', 'max:255'],
            'runtime_annotation_id' => ['nullable', 'string', 'max:255'],
            'page_index' => ['required', 'integer', 'min:0'],
            'target_text' => ['nullable', 'string', 'max:2000'],
            'test_comment' => ['required', 'string', 'max:5000'],
        ]);

        $testCase = $pdfUploadTest->cases()->updateOrCreate(
            ['annotation_id' => $validated['annotation_id']],
            [
                'runtime_annotation_id' => $validated['runtime_annotation_id'] ?? null,
                'page_index' => $validated['page_index'],
                'target_text' => $validated['target_text'] ?? null,
                'test_comment' => $validated['test_comment'],
                'test_saved_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'PDF test saved.',
            'case' => $this->serializeCase($testCase),
        ]);
    }

    public function updateParagraphGrouping(Request $request, PdfUploadTest $pdfUploadTest)
    {
        $this->authorizeFixture($pdfUploadTest);

        $validated = $request->validate([
            'paragraph_grouping_enabled' => ['required', 'boolean'],
        ]);

        $pdfUploadTest->update([
            'paragraph_grouping_enabled' => (bool) $validated['paragraph_grouping_enabled'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $pdfUploadTest->paragraph_grouping_enabled
                ? 'Paragraph grouping enabled.'
                : 'Paragraph grouping disabled.',
            'test' => $this->serializeFixture($pdfUploadTest->fresh(['document', 'cases'])),
        ]);
    }

    public function destroy(PdfUploadTest $pdfUploadTest)
    {
        $this->authorizeFixture($pdfUploadTest);
        $pdfUploadTest->load('document');

        $document = $pdfUploadTest->document;
        $documentId = $document?->id;
        $directory = Str::isUuid((string) $pdfUploadTest->uuid)
            ? 'pdf-upload-tests/'.$pdfUploadTest->uuid
            : null;

        DB::transaction(function () use ($document, $pdfUploadTest) {
            if ($document) {
                DB::table('pdf_extractions_fitz')
                    ->where('document_id', $document->id)
                    ->delete();
            }

            $pdfUploadTest->delete();
            $document?->delete();
        });

        $filesDeleted = true;
        if ($directory !== null) {
            try {
                $filesDeleted = Storage::deleteDirectory($directory);
            } catch (\Throwable $error) {
                $filesDeleted = false;
                report($error);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $filesDeleted
                ? 'Uploaded PDF deleted.'
                : 'Uploaded PDF deleted, but its storage directory could not be removed.',
            'deleted_test_id' => (int) $pdfUploadTest->id,
            'deleted_document_id' => $documentId !== null ? (int) $documentId : null,
            'files_deleted' => $filesDeleted,
        ]);
    }

    public function original(PdfUploadTest $pdfUploadTest)
    {
        $this->authorizeFixture($pdfUploadTest);

        $disposition = HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $pdfUploadTest->original_name,
            'pdf-upload-test-'.$pdfUploadTest->id.'.pdf'
        );

        return response($pdfUploadTest->pdfContents(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $pdfUploadTest->size_bytes,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authorizeFixture(PdfUploadTest $fixture): void
    {
        abort_unless(
            (int) $fixture->admin_id === (int) Auth::guard('admin')->id(),
            404
        );
    }

    private function normalizePdfName(string $name): string
    {
        $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', ' ', trim($name));
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        $name = $name !== '' ? $name : 'pdf-upload-test.pdf';

        return str_ends_with(strtolower($name), '.pdf')
            ? $name
            : rtrim($name, ". \t\n\r\0\x0B").'.pdf';
    }

    private function serializeFixture(PdfUploadTest $fixture): array
    {
        $document = $fixture->document;

        return [
            'id' => (int) $fixture->id,
            'uuid' => $fixture->uuid,
            'document_id' => (int) $fixture->document_id,
            'original_name' => $fixture->original_name,
            'mime_type' => $fixture->mime_type,
            'size_bytes' => (int) $fixture->size_bytes,
            'sha256' => $fixture->sha256,
            'paragraph_grouping_enabled' => (bool) $fixture->paragraph_grouping_enabled,
            'case_count' => $fixture->relationLoaded('cases') ? $fixture->cases->count() : 0,
            'cases' => $fixture->relationLoaded('cases')
                ? $fixture->cases->map(fn (PdfUploadTestCase $case) => $this->serializeCase($case))->values()->all()
                : [],
            'created_at' => $fixture->created_at?->toIso8601String(),
            'updated_at' => $fixture->updated_at?->toIso8601String(),
            'file_exists' => $document ? Storage::exists($document->path) : false,
            'review_url' => $document
                ? route('pdfTests.uploadTests.review', $fixture)
                : null,
            'current_pdf_url' => $document ? route('documents.file', $document) : null,
            'original_pdf_url' => route('pdfTests.uploadTests.original', $fixture),
            'delete_url' => route('pdfTests.uploadTests.destroy', $fixture),
            'paragraph_grouping_url' => route('pdfTests.uploadTests.paragraphGrouping', $fixture),
        ];
    }

    private function serializeCase(PdfUploadTestCase $case): array
    {
        return [
            'id' => (int) $case->id,
            'test_id' => $case->test_id,
            'pdf_upload_test_id' => (int) $case->pdf_upload_test_id,
            'annotation_id' => $case->annotation_id,
            'runtime_annotation_id' => $case->runtime_annotation_id,
            'page_index' => (int) $case->page_index,
            'target_text' => $case->target_text,
            'test_comment' => $case->test_comment,
            'test_saved_at' => $case->test_saved_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
        ];
    }
}
