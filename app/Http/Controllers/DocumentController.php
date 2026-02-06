<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\PdfState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index()
    {
        $documents = Document::latest()->get();

        return view('documents.index', [
            'documents' => $documents,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $validated['document'];
        $storedPath = $file->storeAs(
            'documents',
            Str::uuid()->toString() . '.pdf'
        );

        $document = Document::create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        // Auto-download fonts for this PDF in background
        $fullPath = Storage::path($storedPath);
        $fontScript = base_path('python/auto_download_fonts.py');
        $fontCommand = sprintf(
            'python3 %s %s > /dev/null 2>&1 &',
            escapeshellarg($fontScript),
            escapeshellarg($fullPath)
        );
        exec($fontCommand);

        return redirect()
            ->route('documents.edit', $document)
            ->with('status', 'PDF uploaded. You can edit it below.');
    }

    public function processOcr(Document $document)
    {
        // Run OCR extraction in background
        $fullPath = Storage::path($document->path);
        $pythonScript = base_path('python/extract_pdf_text.py');
        $documentId = $document->id;
        
        // Execute Python script in background (non-blocking)
        $command = sprintf(
            'python3 %s %s %d > /dev/null 2>&1 &',
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            $documentId
        );
        
        exec($command);
        
        return response()->json([
            'success' => true,
            'message' => 'OCR processing started in background'
        ]);
    }

    public function processFitz(Document $document)
    {
        // Run PyMuPDF extraction in background
        $fullPath = Storage::path($document->path);
        $pythonScript = base_path('python/extract_pdf_pymupdf.py');
        $documentId = $document->id;
        $userEmail = auth()->user()->email ?? 'guest';
        $sessionId = session()->getId();
        
        // Execute Python script in background (non-blocking)
        $command = sprintf(
            'python3 %s %s %d %s %s > /dev/null 2>&1 &',
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            $documentId,
            escapeshellarg($userEmail),
            escapeshellarg($sessionId)
        );
        
        exec($command);
        
        return response()->json([
            'success' => true,
            'message' => 'PyMuPDF extraction started in background'
        ]);
    }

    public function getFitzExtractionData(Document $document)
    {
        $userEmail = auth()->user()->email ?? 'guest';
        $sessionId = session()->getId();
        
        // Prioritize user_email over session_id for authenticated users
        if ($userEmail !== 'guest') {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('user_email', $userEmail)
                ->orderBy('id', 'desc')
                ->first();
        } else {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->first();
        }

        // Fallback to any extraction if no user/session-specific one exists
        if (!$extraction) {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$extraction) {
            return response()->json([
                'success' => false,
                'message' => 'No extraction data found. Processing may still be in progress.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'extraction_data' => json_decode($extraction->extraction_data, true),
            'total_pages' => $extraction->total_pages,
            'total_words' => $extraction->total_words,
            'full_text' => $extraction->full_text
        ]);
    }

    public function edit(Document $document)
    {
        return view('documents.edit', [
            'document' => $document,
        ]);
    }

    public function fullscreen(Document $document)
    {
        return view('documents.fullscreen', [
            'document' => $document,
        ]);
    }

    public function editExtractedText(Document $document)
    {
        $userEmail = auth()->user()->email ?? 'guest';
        $sessionId = session()->getId();
        
        // Get the latest PyMuPDF extraction data - prioritize user_email over session_id
        if ($userEmail !== 'guest') {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('user_email', $userEmail)
                ->orderBy('id', 'desc')
                ->first();
        } else {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->first();
        }

        // Fallback to any extraction if no user/session-specific one exists
        if (!$extraction) {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$extraction) {
            return redirect()->back()->with('error', 'No extraction data found. Please wait for processing to complete.');
        }

        $extractionData = json_decode($extraction->extraction_data, true);

        return view('documents.edit-extracted', [
            'document' => $document,
            'extraction' => $extraction,
            'extractionData' => $extractionData,
        ]);
    }

    public function file(Document $document)
    {
        $fullPath = Storage::path($document->path);
        
        // Get file modification time for ETag
        $lastModified = filemtime($fullPath);
        $etag = md5($lastModified . filesize($fullPath));

        return response()->file($fullPath, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);
    }

    public function flattenRotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $validated['pdf'];
        $tempPath = $file->getPathname();
        $outputPath = tempnam(sys_get_temp_dir(), 'flattened_') . '.pdf';
        
        $pythonScript = base_path('python/flatten_pdf_rotations.py');
        
        $command = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($tempPath),
            escapeshellarg($outputPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error('Rotation flattening failed', [
                'document_id' => $document->id,
                'output' => implode("\n", $output)
            ]);
            
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            
            return response()->json(['error' => 'Failed to flatten rotations'], 500);
        }
        
        // Return the flattened PDF
        return response()->file($outputPath, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function applyRotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'rotations' => ['required', 'string'], // JSON string
        ]);

        $file = $validated['pdf'];
        $tempPath = $file->getPathname();
        $rotations = json_decode($validated['rotations'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($rotations)) {
            return response()->json(['error' => 'Invalid rotation data'], 400);
        }
        
        $pythonScript = base_path('python/rotate_pdf_page.py');
        
        // Apply rotations one by one
        foreach ($rotations as $pageIndex => $rotation) {
            if ($rotation == 0) continue;
            
            $pageNumber = (int)$pageIndex + 1;
            $tempOutputPath = tempnam(sys_get_temp_dir(), 'rotated_') . '.pdf';
            
            $command = sprintf(
                'python3 %s %s %s %d %d 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($tempPath),
                escapeshellarg($tempOutputPath),
                $pageNumber,
                (int)$rotation
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::error('Rotation failed', [
                    'document_id' => $document->id,
                    'page_number' => $pageNumber,
                    'output' => implode("\n", $output)
                ]);
                
                if (file_exists($tempOutputPath)) {
                    unlink($tempOutputPath);
                }
                
                return response()->json(['error' => 'Rotation failed'], 500);
            }
            
            // Replace input with output for next rotation
            if (file_exists($tempOutputPath)) {
                copy($tempOutputPath, $tempPath);
                unlink($tempOutputPath);
            }
        }
        
        // Return the rotated PDF
        return response()->file($tempPath, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    public function save(Request $request, Document $document)
    {
        $validated = $request->validate([
            'edited_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $validated['edited_pdf'];
        $tempPath = $file->getPathname();
        
        Storage::put($document->path, file_get_contents($tempPath));

        // CRITICAL: Use direct DB update to avoid updating 'updated_at' timestamp
        // This prevents prepareOverlay() from auto-refreshing extraction data
        // Shapes/signatures/annotations are visual only and should NOT trigger extraction refresh
        $document->mime_type = $file->getClientMimeType();
        $document->size_bytes = $file->getSize();
        $document->saveQuietly(); // Saves without updating timestamps or firing events

        // IMPORTANT: save() should NEVER update pdf_extractions_fitz data
        // Extraction data should ONLY be updated by the overlay editor via saveEdits()
        // Shapes, signatures, and text annotations are visual stamps that don't affect extraction

        return response()->json([
            'ok' => true,
            'message' => 'Document saved.',
        ]);
    }

    public function destroy(Document $document)
    {
        // Delete related extraction data
        DB::table('pdf_extractions_fitz')
            ->where('document_id', $document->id)
            ->delete();
        
        Storage::delete($document->path);
        $document->delete();

        return redirect()
            ->route('documents.index')
            ->with('status', 'Document deleted.');
    }

    public function prepareOverlay(Document $document)
    {
        $userEmail = auth()->user()->email ?? 'guest';
        $sessionId = session()->getId();
        
        // Check if extraction data exists - prioritize user_email over session_id
        if ($userEmail !== 'guest') {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('user_email', $userEmail)
                ->orderBy('id', 'desc')
                ->first();
        } else {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->first();
        }

        // Fallback to any extraction if no user/session-specific one exists
        if (!$extraction) {
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$extraction) {
            // Run extraction automatically
            $fullPath = Storage::path($document->path);
            $pythonScript = base_path('python/extract_pdf_pymupdf.py');
            $documentId = $document->id;
            $userEmail = auth()->user()->email ?? 'guest';
            $sessionId = session()->getId();
            
            // Execute Python script synchronously (wait for it to complete)
            $command = sprintf(
                'python3 %s %s %d %s %s 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($fullPath),
                $documentId,
                escapeshellarg($userEmail),
                escapeshellarg($sessionId)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                return redirect()->back()->with('error', 'Failed to extract PDF text. Please try again.');
            }
            
            // Reload extraction data
            $extraction = DB::table('pdf_extractions_fitz')
                ->where('document_id', $document->id)
                ->orderBy('id', 'desc')
                ->first();
        } else {
            // DO NOT auto-refresh extraction based on timestamps
            // Extraction should ONLY be refreshed by overlay editor's saveEdits()
            // Shapes/signatures saved via save() route should not trigger re-extraction
            // If auto-refresh is needed, it should be explicitly requested

            // Ensure extraction includes font_xref data (refresh if missing)
            $extractionData = json_decode($extraction->extraction_data, true);
            $hasFontXref = false;
            if (is_array($extractionData)) {
                foreach ($extractionData as $page) {
                    if (!empty($page['words'])) {
                        $firstWord = $page['words'][0];
                        if (array_key_exists('font_xref', $firstWord)) {
                            $hasFontXref = true;
                        }
                        break;
                    }
                }
            }
            if (!$hasFontXref) {
                $fullPath = Storage::path($document->path);
                $pythonScript = base_path('python/extract_pdf_pymupdf.py');
                $documentId = $document->id;
                $userEmail = auth()->user()->email ?? 'guest';
                $sessionId = session()->getId();
                $command = sprintf(
                    'python3 %s %s %d %s %s 2>&1',
                    escapeshellarg($pythonScript),
                    escapeshellarg($fullPath),
                    $documentId,
                    escapeshellarg($userEmail),
                    escapeshellarg($sessionId)
                );
                exec($command, $output, $returnCode);
                if ($returnCode === 0) {
                    $extraction = DB::table('pdf_extractions_fitz')
                        ->where('document_id', $document->id)
                        ->orderBy('id', 'desc')
                        ->first();
                }
            }
        }

        // Create clean PDF (with all text removed) for overlay editing
        $fullPath = Storage::path($document->path);
        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        $extractionFile = storage_path('app/temp_extraction_' . $document->id . '.json');
        
        // Always delete old clean PDF to ensure we get fresh version
        if (file_exists($cleanPath)) {
            unlink($cleanPath);
        }
        
        // Ensure temp directory exists
        $tempDir = dirname($cleanPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // Save extraction data to temp file
        file_put_contents($extractionFile, $extraction->extraction_data);
        
        $pythonScript = base_path('python/create_clean_pdf.py');
        $command = sprintf(
            'python3 %s %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            escapeshellarg($extractionFile),
            escapeshellarg($cleanPath)
        );
        
        exec($command, $output, $returnCode);
        
        // Clean up temp extraction file
        if (file_exists($extractionFile)) {
            unlink($extractionFile);
        }
        
        if ($returnCode !== 0) {
            \Log::error('Failed to create clean PDF', ['output' => implode("\n", $output)]);
            return response()->json(['success' => false, 'error' => 'Failed to prepare PDF for editing.'], 500);
        }

        return response()->json(['success' => true]);
    }

    public function saveEdits(Request $request, Document $document)
    {
        $validated = $request->validate([
            'edits' => ['required', 'array'],
            'edits.*.page_number' => ['required', 'integer'],
            'edits.*.original_text' => ['required', 'string'],
            'edits.*.new_text' => ['nullable', 'string'],
            'edits.*.bbox' => ['required', 'array'],
            'edits.*.original_bbox' => ['nullable', 'array'],
            'edits.*.origin_x' => ['nullable', 'numeric'],
            'edits.*.origin_y' => ['nullable', 'numeric'],
            'edits.*.font_xref' => ['nullable', 'integer'],
            'edits.*.font' => ['required', 'string'],
            'edits.*.font_size' => ['required', 'numeric'],
            'edits.*.font_weight' => ['nullable', 'string'],
            'edits.*.font_style' => ['nullable', 'string'],
            'edits.*.line_height' => ['nullable', 'numeric'],
            'edits.*.color' => ['nullable', 'string'],
            'edits.*.rich_html' => ['nullable', 'string'],
            'skip_refresh' => ['nullable', 'boolean'],
        ]);

        // Save edits to temporary file
        $editsFile = storage_path('app/temp_edits_' . $document->id . '.json');
        file_put_contents($editsFile, json_encode($validated['edits']));

        // Run Python script to apply edits (using simple version)
        $fullPath = Storage::path($document->path);
        
        // CRITICAL: Create a backup of the PDF before applying destructive edits
        // This allows recovery if the edit process corrupts or loses content
        $backupPath = Storage::path('documents/backup_' . pathinfo($document->path, PATHINFO_FILENAME) . '.pdf');
        if (file_exists($fullPath)) {
            copy($fullPath, $backupPath);
            \Log::info('Created pre-edit backup', [
                'document_id' => $document->id,
                'backup_path' => $backupPath,
            ]);
        }
        
        $pythonScript = base_path('python/apply_pdf_edits_simple.py');
        
        // Log the command for debugging
        \Log::info('Applying PDF edits', [
            'document_id' => $document->id,
            'pdf_path' => $fullPath,
            'edits_json' => json_encode($validated['edits']),
            'edits_count' => count($validated['edits'])
        ]);
        
        // Pass edits as JSON argument instead of file
        $editsJson = json_encode($validated['edits']);
        $command = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($fullPath),
            escapeshellarg($editsJson)
        );
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        // Log the output
        \Log::info('Python script output', [
            'return_code' => $returnCode,
            'output' => implode("\n", $output)
        ]);

        if ($returnCode === 0) {
            // Refresh extraction data so overlay editor reflects latest text positions
            // Skip refresh if requested (e.g., during page reordering)
            if (!($validated['skip_refresh'] ?? false)) {
                $extractScript = base_path('python/extract_pdf_pymupdf.py');
                $userEmail = auth()->user()->email ?? 'guest';
                $sessionId = session()->getId();
                $refreshCommand = sprintf(
                    'python3 %s %s %d %s %s 2>&1',
                    escapeshellarg($extractScript),
                    escapeshellarg($fullPath),
                    $document->id,
                    escapeshellarg($userEmail),
                    escapeshellarg($sessionId)
                );
                $refreshOutput = [];
                $refreshCode = 0;
                exec($refreshCommand, $refreshOutput, $refreshCode);
                \Log::info('Refreshed extraction data', [
                    'document_id' => $document->id,
                    'return_code' => $refreshCode,
                    'output' => implode("\n", $refreshOutput)
                ]);
                
                // CRITICAL: Regenerate clean PDF after save
                // This ensures overlay editor continues to work properly
                $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
                $latestExtraction = \App\Models\PdfExtractionFitz::where('document_id', $document->id)
                    ->orderBy('id', 'desc')
                    ->first();
                
                if ($latestExtraction) {
                    $extractionFile = storage_path('app/temp_extraction_' . $document->id . '.json');
                    
                    // Ensure extraction_data is a string (it might be an array)
                    $extractionData = is_string($latestExtraction->extraction_data) 
                        ? $latestExtraction->extraction_data 
                        : json_encode($latestExtraction->extraction_data);
                    
                    file_put_contents($extractionFile, $extractionData);
                    
                    $pythonScript = base_path('python/create_clean_pdf.py');
                    $cleanCommand = sprintf(
                        'python3 %s %s %s %s 2>&1',
                        escapeshellarg($pythonScript),
                        escapeshellarg($fullPath),
                        escapeshellarg($extractionFile),
                        escapeshellarg($cleanPath)
                    );
                    exec($cleanCommand, $cleanOutput, $cleanCode);
                    
                    // Clean up temp extraction file
                    if (file_exists($extractionFile)) {
                        unlink($extractionFile);
                    }
                    
                    \Log::info('Regenerated clean PDF after save', [
                        'document_id' => $document->id,
                        'return_code' => $cleanCode,
                        'clean_exists' => file_exists($cleanPath)
                    ]);
                }
            }
        }

        if ($returnCode !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply edits to PDF',
                'error' => implode("\n", $output)
            ], 500);
        }

        $document->touch();

        return response()->json([
            'success' => true,
            'message' => 'Edits applied successfully',
            'debug_output' => implode("\n", $output)
        ]);
    }

    public function cleanPdf(Document $document)
    {
        // After edits are saved, the "clean" PDF is deleted
        // Check if it exists (during editing), otherwise serve the original PDF
        $cleanPath = Storage::path('temp/clean_' . $document->id . '.pdf');
        
        if (file_exists($cleanPath)) {
            // During editing: serve the clean PDF (with text removed for overlay)
            return response()->file($cleanPath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="clean.pdf"',
            ]);
        }
        
        // After save: serve the edited PDF (the original has been updated)
        $pdfPath = Storage::path($document->path);
        
        if (!file_exists($pdfPath)) {
            return response()->json(['error' => 'PDF not found'], 404);
        }
        
        return response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document.pdf"',
        ]);
    }
    
    public function getFonts(Document $document)
    {
        // Use the path column like other methods
        $pdfPath = Storage::path($document->path);
        
        if (!file_exists($pdfPath)) {
            return response()->json(['error' => 'PDF not found'], 404);
        }
        
        // Run Python script to extract fonts
        $pythonScript = base_path('python/extract_pdf_fonts.py');
        $command = sprintf(
            'python3 %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($pdfPath)
        );
        
        $output = shell_exec($command);
        $result = json_decode($output, true);
        
        if (!$result || isset($result['error'])) {
            return response()->json(['error' => $result['error'] ?? 'Failed to extract fonts'], 500);
        }
        
        return response()->json($result);
    }

    public function matchFonts(Document $document)
    {
        // Get the PyMuPDF extraction data to analyze fonts
        $fitzExtraction = $document->pdfExtractionsFitz()->latest()->first();
        
        if (!$fitzExtraction || !$fitzExtraction->extraction_data) {
            return response()->json([
                'success' => false,
                'message' => 'No extraction data found. Please run PyMuPDF extraction first.'
            ], 404);
        }

        // Save extraction data to a temporary file
        $tempJsonPath = storage_path('app/temp_extraction_' . $document->id . '.json');
        file_put_contents($tempJsonPath, json_encode($fitzExtraction->extraction_data));

        // Output CSS path in storage (writable), then we'll symlink or serve it
        $outputCssPath = storage_path('app/public/loaded_fonts.css');
        
        // Ensure storage/app/public directory exists
        if (!file_exists(storage_path('app/public'))) {
            mkdir(storage_path('app/public'), 0755, true);
        }

        // Run install_fonts.py script with output CSS path
        $pythonScript = base_path('python/install_fonts.py');
        $command = sprintf(
            'python3 %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($tempJsonPath),
            escapeshellarg($outputCssPath)
        );
        
        $output = shell_exec($command);
        
        // Log the full output for debugging
        \Log::info('Font matching output:', ['output' => $output]);
        
        // Extract JSON from output (last line should be JSON)
        $lines = explode("\n", trim($output));
        $jsonLine = end($lines);
        $result = json_decode($jsonLine, true);
        
        // Clean up temp file
        if (file_exists($tempJsonPath)) {
            unlink($tempJsonPath);
        }
        
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse font matching output',
                'output' => $output,
                'raw_json_line' => $jsonLine
            ], 500);
        }
        
        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
                'output' => $output
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'loaded_fonts' => $result['loaded_fonts'] ?? 0,
            'total_fonts' => $result['total_fonts'] ?? 0,
            'font_results' => $result['font_results'] ?? [],
            'message' => 'Font matching completed',
            'css_url' => route('loadedFonts') . '?t=' . time()
        ]);
    }

    public function reorderPages(Request $request, Document $document)
    {
        $validated = $request->validate([
            'page_order' => ['required', 'array'],
            'page_order.*' => ['required', 'integer', 'min:0'],
            'session_id' => ['nullable', 'string'],
        ]);

        $pageOrder = $validated['page_order'];
        $sessionId = $validated['session_id'] ?? null;
        $inputPath = Storage::path($document->path);
        
        // Generate output path for reordered PDF
        $tempOutputPath = Storage::path('documents/temp_reorder_' . Str::uuid() . '.pdf');
        
        // Call Python script to reorder pages
        $pythonScript = base_path('python/reorder_pdf_pages.py');
        $pageOrderStr = implode(',', $pageOrder);
        
        $command = sprintf(
            'python3 %s %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg($pageOrderStr)
        );
        
        exec($command, $output, $returnCode);
        $output = implode("\n", $output);
        
        // Parse JSON response from Python script
        $result = json_decode($output, true);
        
        if (!$result || !isset($result['success'])) {
            // Clean up temp file if it exists
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse reorder output',
                'output' => $output
            ], 500);
        }
        
        if (!$result['success']) {
            // Clean up temp file if it exists
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unknown error occurred',
                'output' => $output
            ], 500);
        }
        
        // Replace the original PDF with the reordered one
        if (file_exists($tempOutputPath)) {
            // Backup original (optional)
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            
            // Get original page count to detect deleted pages
            $pdf = new \finfo(FILEINFO_MIME_TYPE);
            $pdfInfo = shell_exec(sprintf('pdfinfo %s 2>&1 | grep "Pages:"', escapeshellarg($inputPath)));
            $originalPageCount = 0;
            if (preg_match('/Pages:\s+(\d+)/', $pdfInfo, $matches)) {
                $originalPageCount = (int)$matches[1];
            }
            
            // Detect which pages were deleted (not in page_order)
            $deletedPages = [];
            if ($originalPageCount > 0) {
                $allPages = range(0, $originalPageCount - 1);
                $deletedPages = array_diff($allPages, $pageOrder);
            }
            
            // Delete annotations for deleted pages if session_id provided
            if ($sessionId && !empty($deletedPages)) {
                foreach ($deletedPages as $deletedPage) {
                    $deletedCount = PdfState::where('document_id', $document->id)
                        ->where('session_id', $sessionId)
                        ->where('page_number', $deletedPage)
                        ->delete();
                    
                    if ($deletedCount > 0) {
                        \Log::info("Deleted {$deletedCount} annotations for page {$deletedPage}", [
                            'document_id' => $document->id,
                            'session_id' => $sessionId,
                            'page' => $deletedPage
                        ]);
                    }
                }
            }
            
            // Replace with reordered PDF
            copy($tempOutputPath, $inputPath);
            
            // Clean up temp file
            unlink($tempOutputPath);
            
            // Always re-extract the PDF after page reordering to update extraction data
            \Log::info('Re-extracting PDF after reorder', [
                'document_id' => $document->id,
                'deleted_pages' => $deletedPages,
                'new_page_count' => count($pageOrder)
            ]);
            
            $pythonScript = base_path('python/extract_pdf_pymupdf.py');
            $userEmail = auth()->user()->email ?? 'guest';
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                'python3 %s %s %d %s %s 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode === 0) {
                \Log::info('Re-extraction completed successfully', [
                    'document_id' => $document->id
                ]);
            } else {
                \Log::error('Failed to re-extract PDF after reordering', [
                    'document_id' => $document->id,
                    'return_code' => $extractReturnCode,
                    'output' => implode("\n", $extractOutput)
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Pages reordered successfully',
                'total_pages' => $result['total_pages'] ?? count($pageOrder),
                'deleted_pages' => array_values($deletedPages),
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Reordered PDF file not found'
        ], 500);
    }

    public function addBlankPage(Request $request, Document $document)
    {
        $validated = $request->validate([
            'insert_after' => ['nullable', 'integer'],
            'size_reference' => ['nullable', 'integer'],
        ]);

        $insertAfter = $validated['insert_after'] ?? -1;
        $sizeReference = $validated['size_reference'] ?? -1;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_add_page_' . Str::uuid() . '.pdf');

        $pythonScript = base_path('python/add_blank_page.py');

        $command = sprintf(
            'python3 %s %s %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg((string) $insertAfter),
            escapeshellarg((string) $sizeReference)
        );

        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);

        // Try to find JSON in the output (last line should be JSON)
        $lines = array_filter($output, function($line) {
            return !empty(trim($line));
        });
        $jsonLine = end($lines);
        
        $result = json_decode($jsonLine, true);

        if (!$result || !isset($result['success'])) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            \Log::error('Add blank page - Failed to parse output', [
                'output' => $outputStr,
                'json_line' => $jsonLine,
                'return_code' => $returnCode
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse add-page output',
                'output' => $outputStr,
                'debug' => [
                    'json_line' => $jsonLine,
                    'return_code' => $returnCode
                ]
            ], 500);
        }

        if (!$result['success']) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }

            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Unknown error occurred',
                'output' => $outputStr
            ], 500);
        }

        if (file_exists($tempOutputPath)) {
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            copy($tempOutputPath, $inputPath);
            unlink($tempOutputPath);
            
            // Re-extract the PDF to update extraction data after adding page
            $pythonScript = base_path('python/extract_pdf_pymupdf.py');
            $userEmail = auth()->user()->email ?? 'guest';
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                'python3 %s %s %d %s %s 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode !== 0) {
                \Log::warning('Failed to re-extract PDF after adding blank page', [
                    'document_id' => $document->id,
                    'output' => implode("\n", $extractOutput)
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Blank page added successfully',
                'total_pages' => $result['total_pages'] ?? null
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Updated PDF file not found'
        ], 500);
    }
    
    public function rotatePage(Request $request, Document $document)
    {
        try {
            \Log::info('Rotate page request', [
                'document_id' => $document->id,
                'request_data' => $request->all()
            ]);
            
            $validated = $request->validate([
                'page_number' => ['required', 'integer', 'min:1'],
                'rotation' => ['nullable', 'integer', 'in:90,180,270,-90'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Rotation validation failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation error: ' . $e->getMessage()
            ], 500);
        }

        $pageNumber = $validated['page_number'];
        $rotation = $validated['rotation'] ?? 90;

        $inputPath = Storage::path($document->path);
        $tempOutputPath = Storage::path('documents/temp_rotate_page_' . Str::uuid() . '.pdf');

        $pythonScript = base_path('python/rotate_pdf_page.py');

        $command = sprintf(
            'python3 %s %s %s %s %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($inputPath),
            escapeshellarg($tempOutputPath),
            escapeshellarg((string) $pageNumber),
            escapeshellarg((string) $rotation)
        );

        exec($command, $output, $returnCode);
        $outputStr = implode("\n", $output);

        // Check for SUCCESS message
        $success = false;
        foreach ($output as $line) {
            if (strpos($line, 'SUCCESS:') === 0) {
                $success = true;
                break;
            }
        }

        if (!$success || $returnCode !== 0) {
            if (file_exists($tempOutputPath)) {
                unlink($tempOutputPath);
            }
            
            // Check if error message indicates corruption
            $errorMessage = 'Failed to rotate page';
            if (strpos($outputStr, 'corrupted') !== false) {
                $errorMessage = 'PDF file is corrupted. Please re-upload or use a different document.';
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'output' => $outputStr
            ], 500);
        }

        if (file_exists($tempOutputPath)) {
            $backupPath = Storage::path('documents/backup_' . basename($document->path));
            copy($inputPath, $backupPath);
            copy($tempOutputPath, $inputPath);
            unlink($tempOutputPath);
            
            // Re-extract the PDF to update extraction data after rotation
            $pythonScript = base_path('python/extract_pdf_pymupdf.py');
            $userEmail = auth()->user()->email ?? 'guest';
            $currentSessionId = session()->getId();
            
            $extractCommand = sprintf(
                'python3 %s %s %d %s %s 2>&1',
                escapeshellarg($pythonScript),
                escapeshellarg($inputPath),
                $document->id,
                escapeshellarg($userEmail),
                escapeshellarg($currentSessionId)
            );
            
            exec($extractCommand, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode !== 0) {
                \Log::warning('Failed to re-extract PDF after rotating page', [
                    'document_id' => $document->id,
                    'output' => implode("\n", $extractOutput)
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Page rotated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Rotated PDF file not found'
        ], 500);
    }
    
    /**
     * Take a screenshot of the document edit page
     */
    public function takeScreenshot(Request $request, Document $document)
    {
        $validated = $request->validate([
            'suffix' => ['nullable', 'string', 'in:before,after'],
            'page' => ['nullable', 'integer', 'min:1'],
            'edits' => ['nullable', 'array'],
            'url_type' => ['nullable', 'string', 'in:edit,overlay'], // New field
        ]);
        
        $suffix = $validated['suffix'] ?? null;
        $page = $validated['page'] ?? 1;
        $edits = $validated['edits'] ?? [];
        $urlType = $validated['url_type'] ?? 'edit';
        
        // Build the URL - use host.docker.internal if running in Docker, otherwise localhost
        // This allows the headless browser inside the container to reach the Laravel app
        $baseUrl = env('APP_URL', 'http://localhost:8081');
        // If running in Docker, the browser needs to access the host machine
        if (file_exists('/.dockerenv')) {
            $baseUrl = 'http://host.docker.internal:8081';
        }
        
        // Choose URL based on url_type
        $url = $urlType === 'overlay' 
            ? "{$baseUrl}/documents/{$document->id}/overlay-editor"
            : "{$baseUrl}/documents/{$document->id}/edit";
        
        // Path to Python script and venv
        $pythonVenv = base_path('python/venv/bin/python');
        $pythonScript = base_path('python/screenshot_document.py');
        $playwrightPath = base_path('python/.playwright');
        
        // Build command with PLAYWRIGHT_BROWSERS_PATH set
        $command = sprintf(
            'PLAYWRIGHT_BROWSERS_PATH=%s %s %s %d --full-url %s --page %d%s 2>&1',
            escapeshellarg($playwrightPath),
            escapeshellarg($pythonVenv),
            escapeshellarg($pythonScript),
            $document->id,
            escapeshellarg($url),
            $page,
            $suffix ? ' --suffix ' . escapeshellarg($suffix) : ''
        );
        
        \Log::info('Taking screenshot', [
            'document_id' => $document->id,
            'suffix' => $suffix,
            'command' => $command
        ]);
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        \Log::info('Screenshot result', [
            'return_code' => $returnCode,
            'output' => implode("\n", $output)
        ]);
        
        if ($returnCode === 0) {
            // Build the screenshot filename
            $filename = $suffix 
                ? "{$document->id}_page_{$page}_{$suffix}.png"
                : "{$document->id}_page_{$page}.png";
            
            $screenshotPath = base_path("python/screenshots/{$filename}");
            
            // Save edit coordinates alongside screenshot for verification
            if (!empty($edits)) {
                $editsFilename = $suffix 
                    ? "{$document->id}_page_{$page}_{$suffix}_edits.json"
                    : "{$document->id}_page_{$page}_edits.json";
                $editsPath = base_path("python/screenshots/{$editsFilename}");
                file_put_contents($editsPath, json_encode($edits, JSON_PRETTY_PRINT));
                
                \Log::info('Saved edit coordinates for verification', [
                    'document_id' => $document->id,
                    'edits_file' => $editsFilename,
                    'edit_count' => count($edits)
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Screenshot taken successfully',
                'filename' => $filename,
                'path' => "python/screenshots/{$filename}",
                'edits_saved' => !empty($edits),
                'edit_count' => count($edits)
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to take screenshot',
            'output' => implode("\n", $output)
        ], 500);
    }

    public function saveAnnotations(Request $request, Document $document)
    {
        $validated = $request->validate([
            'annotations' => 'required|array',
            'annotations.*.id' => 'nullable|string',
            'annotations.*.type' => 'required|string|in:text,shape',
            'annotations.*.pageIndex' => 'required|integer',
            'session_id' => 'required|string',
            'user_email' => 'nullable|email',
            'annotation_id' => 'nullable|string',
            'state' => 'nullable|string|in:saved,not_saved',
        ]);

        $sessionId = $validated['session_id'];
        $userEmail = $validated['user_email'] ?? null;
        $annotationId = $validated['annotation_id'] ?? null;
        
        // If annotation_id is provided, update/create that specific annotation
        if ($annotationId) {
            $annotation = $validated['annotations'][0];
            
            // Try to find existing annotation by annotation ID
            $existingAnnotation = PdfState::where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                ->first();
            
            if ($existingAnnotation) {
                // Update existing annotation
                $existingAnnotation->update([
                    'annotation_data' => $annotation,
                    'user_email' => $userEmail,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'state' => 'not_saved',
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Annotation updated',
                    'updated' => true,
                ]);
            } else {
                // Create new annotation
                PdfState::create([
                    'document_id' => $document->id,
                    'user_email' => $userEmail,
                    'session_id' => $sessionId,
                    'page_number' => $annotation['pageIndex'] ?? null,
                    'annotation_data' => $annotation,
                    'state' => 'not_saved',
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Annotation created',
                    'created' => true,
                ]);
            }
        }
        
        // Bulk save - update or create each annotation by ID
        $savedCount = 0;
        $updatedCount = 0;
        $createdCount = 0;
        $targetState = $validated['state'] ?? 'not_saved';
        
        \Log::info("Bulk save annotations", [
            'document_id' => $document->id,
            'session_id' => $sessionId,
            'annotation_count' => count($validated['annotations']),
            'target_state' => $targetState
        ]);
        
        foreach ($validated['annotations'] as $annotation) {
            $annotationId = $annotation['id'] ?? null;
            
            if ($annotationId) {
                // Try to find and update existing annotation
                $existingAnnotation = PdfState::where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->first();
                
                if ($existingAnnotation) {
                    $existingAnnotation->update([
                        'annotation_data' => $annotation,
                        'user_email' => $userEmail,
                        'page_number' => $annotation['pageIndex'] ?? null,
                        'state' => $targetState,
                    ]);
                    $savedCount++;
                    $updatedCount++;
                    \Log::info("Updated annotation", ['id' => $annotationId, 'state' => $targetState]);
                    continue;
                }
            }
            
            // Create new annotation if not found
            PdfState::create([
                'document_id' => $document->id,
                'user_email' => $userEmail,
                'session_id' => $sessionId,
                'page_number' => $annotation['pageIndex'] ?? null,
                'annotation_data' => $annotation,
                'state' => $targetState,
            ]);
            $savedCount++;
            $createdCount++;
            \Log::info("Created annotation", ['id' => $annotationId ?? 'no-id', 'state' => $targetState]);
        }

        return response()->json([
            'success' => true,
            'message' => "Saved {$savedCount} annotations (updated: {$updatedCount}, created: {$createdCount})",
            'count' => $savedCount,
            'updated' => $updatedCount,
            'created' => $createdCount,
            'state' => $targetState,
        ]);
    }

    public function markAnnotationsSaved(Request $request, Document $document)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'annotation_ids' => 'nullable|array',
        ]);

        $sessionId = $validated['session_id'];
        $annotationIds = $validated['annotation_ids'] ?? null;

        if ($annotationIds) {
            // Mark specific annotations as saved
            foreach ($annotationIds as $annotationId) {
                PdfState::where('document_id', $document->id)
                    ->where('session_id', $sessionId)
                    ->whereRaw("JSON_EXTRACT(annotation_data, '$.id') = ?", [$annotationId])
                    ->update(['state' => 'saved']);
            }
        } else {
            // Mark all annotations for this session as saved
            PdfState::where('document_id', $document->id)
                ->where('session_id', $sessionId)
                ->update(['state' => 'saved']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Annotations marked as saved',
        ]);
    }
}
