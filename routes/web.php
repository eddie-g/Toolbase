<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DeveloperChatController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\ComplianceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/fix-migration-3', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    return 'Migrated: ' . \Illuminate\Support\Facades\Artisan::output();
});

Route::get('auth/google', [\App\Http\Controllers\SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('auth/google/callback', [\App\Http\Controllers\SocialAuthController::class, 'handleGoogleCallback']);



Route::get('/pdf-editor', [DocumentController::class, 'index'])->name('documents.index');
Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::post('/documents/create-ai', [DocumentController::class, 'createAi'])->name('documents.createAi');
Route::post('/documents/create-from-template', [DocumentController::class, 'createFromTemplate'])->name('documents.createFromTemplate');
Route::post('/documents/create-simple-invoice', [DocumentController::class, 'createSimpleInvoice'])->name('documents.createSimpleInvoice');
Route::post('/documents/create-guided-template', [DocumentController::class, 'createFromGuidedTemplate'])->name('documents.createFromGuidedTemplate');
Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
Route::get('/documents/{document}/ai', [DocumentController::class, 'ai'])->name('documents.ai');
Route::get('/documents/{document}/guided', [DocumentController::class, 'guided'])->name('documents.guided');
Route::get('/documents/{document}/fullscreen', [DocumentController::class, 'fullscreen'])->name('documents.fullscreen');
Route::get('/documents/{document}/edit-extracted', [DocumentController::class, 'editExtractedText'])->name('documents.editExtracted');
Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
Route::post('/documents/{document}/save', [DocumentController::class, 'save'])->name('documents.save');
Route::post('/documents/{document}/flatten-rotations', [DocumentController::class, 'flattenRotations'])->name('documents.flattenRotations');
Route::post('/documents/{document}/apply-rotations', [DocumentController::class, 'applyRotations'])->name('documents.applyRotations');
Route::post('/documents/{document}/save-annotations', [DocumentController::class, 'saveAnnotations'])->name('documents.saveAnnotations');
Route::post('/documents/{document}/mark-annotations-saved', [DocumentController::class, 'markAnnotationsSaved'])->name('documents.markAnnotationsSaved');
Route::post('documents/{document}/process-ocr', [DocumentController::class, 'processOcr'])->name('documents.processOcr');
Route::get('documents/{document}/extraction-data', [DocumentController::class, 'getExtractionData'])->name('documents.getExtractionData');
Route::post('documents/{document}/process-fitz', [DocumentController::class, 'processFitz'])->name('documents.processFitz');
Route::get('documents/{document}/fitz-extraction-data', [DocumentController::class, 'getFitzExtractionData'])->name('documents.getFitzExtractionData');
Route::post('/documents/{document}/prepare-overlay', [DocumentController::class, 'prepareOverlay'])->name('documents.prepareOverlay');
Route::get('/documents/{document}/clean-pdf', [DocumentController::class, 'cleanPdf'])->name('documents.cleanPdf');
Route::get('/documents/{document}/fonts', [DocumentController::class, 'getFonts'])->name('documents.getFonts');
Route::post('/documents/{document}/save-edits', [DocumentController::class, 'saveEdits'])->name('documents.saveEdits');
Route::post('/documents/{document}/match-fonts', [DocumentController::class, 'matchFonts'])->name('documents.matchFonts');
Route::post('/documents/{document}/reorder-pages', [DocumentController::class, 'reorderPages'])->name('documents.reorderPages');
Route::post('/documents/{document}/add-blank-page', [DocumentController::class, 'addBlankPage'])->name('documents.addBlankPage');
Route::post('/documents/{document}/rotate-page', [DocumentController::class, 'rotatePage'])->name('documents.rotatePage');
Route::post('/documents/{document}/regenerate-invoice', [DocumentController::class, 'regenerateInvoice'])->name('documents.regenerateInvoice');
Route::post('/documents/{document}/regenerate-template', [DocumentController::class, 'regenerateTemplate'])->name('documents.regenerateTemplate');
Route::post('/documents/{document}/convert-html-to-pdf', [DocumentController::class, 'convertHtmlToPdf'])->name('documents.convertHtmlToPdf');
Route::post('/documents/{document}/save-guided-form', [DocumentController::class, 'saveGuidedFormData'])->name('documents.saveGuidedForm');
Route::post('/documents/{document}/screenshot', [DocumentController::class, 'takeScreenshot'])->name('documents.takeScreenshot');
Route::post('/documents/{document}/convert-to-pdfa', [DocumentController::class, 'convertToPdfA'])->name('documents.convertToPdfA');
Route::get('/documents/download-pdfa', [DocumentController::class, 'downloadPdfA'])->name('documents.downloadPdfA');
Route::post('/documents/{document}/log-export', [DocumentController::class, 'logExportActivity'])->name('documents.logExport');
Route::get('/loaded-fonts.css', function() {
    $path = storage_path('app/public/loaded_fonts.css');
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path, [
        'Content-Type' => 'text/css',
        'Cache-Control' => 'no-cache, must-revalidate'
    ]);
})->name('loadedFonts');
Route::post('/compliance/run-tests', [ComplianceController::class, 'runTests'])->name('compliance.runTests');
Route::get('/compliance/test-files', [ComplianceController::class, 'getTestFiles'])->name('compliance.testFiles');
Route::post('/compliance/run-single-test', [ComplianceController::class, 'runSingleTest'])->name('compliance.runSingleTest');
Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
Route::post('/documents/bulk-destroy', [DocumentController::class, 'bulkDestroy'])->name('documents.bulkDestroy');

Route::post('/developer-chat', [DeveloperChatController::class, 'chat'])->name('developerChat.chat');
Route::post('/developer-chat/file-analyze', [DeveloperChatController::class, 'geminiFileAnalyze'])->name('developerChat.fileAnalyze');

// AI Routes
Route::post('/ai/chat', [AIController::class, 'chat'])->name('ai.chat');
Route::post('/ai/sections', [AIController::class, 'saveSections'])->name('ai.saveSections');
Route::get('/ai/sections/{documentId}', [AIController::class, 'getSections'])->name('ai.getSections');
Route::delete('/ai/sections/{documentId}', [AIController::class, 'deleteSections'])->name('ai.deleteSections');
Route::get('/ai/images/{responseId}', [AIController::class, 'getImages'])->name('ai.getImages');
Route::get('/api/ai-images/{imageId}', [AIController::class, 'getImageById'])->name('ai.getImageById');
Route::get('/ai/price-log', [AIController::class, 'getPriceLog'])->name('ai.getPriceLog');
Route::post('/ai/add-to-pdf', [AIController::class, 'addToPdf'])->name('ai.addToPdf');
Route::get('/ai/add-to-pdf', function() {
    return response()->json([
        'error' => 'This endpoint only accepts POST requests with image data'
    ], 405);
});
