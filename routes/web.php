<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DocumentController::class, 'index'])->name('documents.index');
Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
Route::get('/documents/{document}/fullscreen', [DocumentController::class, 'fullscreen'])->name('documents.fullscreen');
Route::get('/documents/{document}/edit-extracted', [DocumentController::class, 'editExtractedText'])->name('documents.editExtracted');
Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
Route::post('/documents/{document}/save', [DocumentController::class, 'save'])->name('documents.save');
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
Route::post('/documents/{document}/screenshot', [DocumentController::class, 'takeScreenshot'])->name('documents.takeScreenshot');
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
Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
