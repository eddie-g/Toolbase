<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DeveloperChatController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\BrowseLogosController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\OverlayEditorTestController;
use App\Http\Controllers\ShapeTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/browse-logos', [BrowseLogosController::class, 'index'])->name('browse-logos');

Route::get('/dashboard', function () {
    return redirect('/portal');
})->middleware('auth')->name('user.dashboard');

Route::get('/docs/logo-generator', function () {
    return view('docs.logo-generator');
})->name('docs.logoGenerator');

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
Route::get('/documents/{document}/apply-rotations', function () {
    return response()->json(['error' => 'This endpoint only accepts POST requests'], 405);
});
Route::post('/documents/{document}/save-annotations', [DocumentController::class, 'saveAnnotations'])->name('documents.saveAnnotations');
Route::post('/documents/{document}/mark-annotations-saved', [DocumentController::class, 'markAnnotationsSaved'])->name('documents.markAnnotationsSaved');
Route::post('/documents/{document}/apply-annotations-direct', [DocumentController::class, 'applyAnnotationsDirect'])->name('documents.applyAnnotationsDirect');
Route::post('documents/{document}/process-ocr', [DocumentController::class, 'processOcr'])->name('documents.processOcr');
Route::get('documents/{document}/extraction-data', [DocumentController::class, 'getExtractionData'])->name('documents.getExtractionData');
Route::post('documents/{document}/process-fitz', [DocumentController::class, 'processFitz'])->name('documents.processFitz');
Route::get('documents/{document}/fitz-extraction-data', [DocumentController::class, 'getFitzExtractionData'])->name('documents.getFitzExtractionData');
Route::match(['get', 'post'], '/documents/{document}/prepare-overlay', [DocumentController::class, 'prepareOverlay'])->name('documents.prepareOverlay');
Route::get('/documents/{document}/clean-pdf', [DocumentController::class, 'cleanPdf'])->name('documents.cleanPdf');
Route::get('/documents/{document}/fonts', [DocumentController::class, 'getFonts'])->name('documents.getFonts');
Route::post('/documents/{document}/save-edits', [DocumentController::class, 'saveEdits'])->name('documents.saveEdits');
Route::post('/documents/{document}/save-image', [DocumentController::class, 'saveImage'])->name('documents.saveImage');
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
Route::post('/documents/{document}/convert-to-word', [DocumentController::class, 'convertToWord'])->name('documents.convertToWord');
Route::post('/documents/{document}/convert-to-excel', [DocumentController::class, 'convertToExcel'])->name('documents.convertToExcel');
Route::post('/documents/{document}/split-pdf', [DocumentController::class, 'splitPdf'])->name('documents.splitPdf');
Route::get('/documents/download-pdfa', [DocumentController::class, 'downloadPdfA'])->name('documents.downloadPdfA');
Route::get('/documents/download-converted', [DocumentController::class, 'downloadConverted'])->name('documents.downloadConverted');
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

Route::get('/overlay-editor/test-files', [OverlayEditorTestController::class, 'getTestFiles'])->name('overlayEditor.testFiles');
Route::post('/overlay-editor/run-single-test', [OverlayEditorTestController::class, 'runSingleTest'])->name('overlayEditor.runSingleTest');

Route::get('/shapes/test-files', [ShapeTestController::class, 'getTestFiles'])->name('shapes.testFiles');
Route::post('/shapes/run-single-test', [ShapeTestController::class, 'runSingleTest'])->name('shapes.runSingleTest');
Route::post('/shapes/run-all-tests', [ShapeTestController::class, 'runAllTests'])->name('shapes.runAllTests');

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

// Domain Search
Route::get('/domain-search', [DomainSearchController::class, 'index'])->name('domainSearch.index');
Route::get('/logo-generator', [DomainSearchController::class, 'logoGenerator2'])->name('domainSearch.logoGenerator');
Route::get('/logo-generator-classic', [DomainSearchController::class, 'logoGenerator'])->name('domainSearch.logoGeneratorClassic');
Route::get('/domain-search/faq', function () {
    return view('domain-search-faq');
})->name('domainSearch.faq');
Route::post('/domain-search/check', [DomainSearchController::class, 'check'])->name('domainSearch.check');
Route::post('/domain-search/check-start', [DomainSearchController::class, 'checkStart'])->middleware(['throttle:10,1'])->name('domainSearch.checkStart');
Route::get('/domain-search/check-poll', [DomainSearchController::class, 'checkPoll'])->name('domainSearch.checkPoll');
Route::post('/domain-search/generate', [DomainSearchController::class, 'generate'])->name('domainSearch.generate');
Route::post('/domain-search/toggle-saved', [DomainSearchController::class, 'toggleSavedDomain'])->middleware('auth:web,admin')->name('domainSearch.toggleSaved');
Route::get('/domain-search/toggle-saved', fn () => response()->json(['error' => 'POST method required.'], 405));
Route::post('/domain-search/generate-and-check', [DomainSearchController::class, 'generateAndCheck'])->name('domainSearch.generateAndCheck');
Route::post('/domain-search/ai-generate', [DomainSearchController::class, 'aiGenerate'])->name('domainSearch.aiGenerate');
Route::get('/domain-search/ai-status/{jobId}', [DomainSearchController::class, 'aiStatus'])->name('domainSearch.aiStatus');
Route::post('/domain-search/record-file-upload', [DomainSearchController::class, 'recordFileUpload'])->middleware(['throttle:20,1'])->name('domainSearch.recordFileUpload');
Route::post('/domain-search/generate-logo', [DomainSearchController::class, 'generateLogo'])->middleware('json.response')->name('domainSearch.generateLogo');
Route::get('/domain-search/generate-logo', fn () => response()->json(['error' => 'POST method required.'], 405));
Route::get('/domain-search/logo-status/{logoRequest}', [DomainSearchController::class, 'logoStatus'])->middleware('json.response')->name('domainSearch.logoStatus');
Route::post('/domain-search/logo-similar-ideas', [DomainSearchController::class, 'logoSimilarIdeas'])->name('domainSearch.logoSimilarIdeas');
Route::post('/domain-search/generate-pro-logo', [DomainSearchController::class, 'generateProLogo'])->name('domainSearch.generateProLogo');
Route::post('/domain-search/estimate-logo-price', [DomainSearchController::class, 'estimateLogoPrice'])->middleware('json.response')->name('domainSearch.estimateLogoPrice');
Route::post('/domain-search/describe-logo', [DomainSearchController::class, 'describeLogo'])->name('domainSearch.describeLogo');
Route::post('/domain-search/upscale-logo', [DomainSearchController::class, 'upscaleLogo'])->name('domainSearch.upscaleLogo');
Route::get('/domain-search/upscale-logo', fn () => response()->json(['error' => 'POST method required.'], 405));
Route::post('/domain-search/remove-logo-bg', [DomainSearchController::class, 'removeLogoBg'])->name('domainSearch.removeLogoBg');
Route::get('/domain-search/remove-logo-bg', fn () => response()->json(['error' => 'POST method required.'], 405));
Route::get('/domain-search/logo-palettes', [DomainSearchController::class, 'listLogoPalettes'])->middleware('auth:web,admin')->name('domainSearch.logoPalettes.list');
Route::post('/domain-search/logo-palettes', [DomainSearchController::class, 'saveLogoPalette'])->middleware('auth:web,admin')->name('domainSearch.logoPalettes.save');
Route::delete('/domain-search/logo-palettes/{palette}', [DomainSearchController::class, 'deleteLogoPalette'])->middleware('auth:web,admin')->name('domainSearch.logoPalettes.delete');
Route::get('/logos/{logoRequest}/edit', [DomainSearchController::class, 'editLogo'])->name('logos.edit');
Route::post('/logos/{logoRequest}/save-edited', [DomainSearchController::class, 'saveEditedLogo'])->name('logos.saveEdited');

// Stripe Credits
Route::post('/credits/checkout', [CreditController::class, 'createCheckout'])->middleware('auth')->name('credits.checkout');
Route::post('/subscription/checkout', [CreditController::class, 'createSubscriptionCheckout'])->middleware('auth')->name('subscription.checkout');
Route::post('/subscription/cancel', [CreditController::class, 'cancelSubscription'])->middleware('auth')->name('subscription.cancel');
Route::post('/stripe/webhook', [CreditController::class, 'handleWebhook'])->name('stripe.webhook');
