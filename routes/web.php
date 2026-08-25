<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DeveloperChatController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\GeneratedImagePreviewController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\BrowseLogosController;
use App\Http\Controllers\AutomatedTestController;
use App\Http\Controllers\SavedSignatureController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\OverlayEditorTestController;
use App\Http\Controllers\PdfTestController;
use App\Http\Controllers\PdfUploadTestController;
use App\Http\Controllers\ShapeTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/browse-logos', [BrowseLogosController::class, 'index'])->name('browse-logos');

Route::get('/dashboard', function () {
    return redirect('/portal');
})->middleware(['auth', 'verified'])->name('user.dashboard');

Route::get('/livewire/update', function () {
    return redirect()->to('/portal/pdf-generator');
});

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
Route::post('/pdf-state/stamp-preview', [DocumentController::class, 'stampPdfStatePreview'])->name('pdfState.stampPreview');
Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
Route::post('/documents/create-blank', [DocumentController::class, 'createBlank'])->name('documents.createBlank');
Route::post('/documents/create-ai', [DocumentController::class, 'createAi'])->name('documents.createAi');
Route::post('/documents/create-from-template', [DocumentController::class, 'createFromTemplate'])->name('documents.createFromTemplate');
Route::post('/documents/create-simple-invoice', [DocumentController::class, 'createSimpleInvoice'])->name('documents.createSimpleInvoice');
Route::post('/documents/create-guided-template', [DocumentController::class, 'createFromGuidedTemplate'])->name('documents.createFromGuidedTemplate');
Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
Route::get('/documents/{document}/edit-new', [DocumentController::class, 'editNew'])->name('documents.editNew');
Route::get('/documents/{document}/edit-pdfjs', [DocumentController::class, 'editPdfjs'])->name('documents.editPdfjs');
Route::get('/documents/{document}/notes', [DocumentController::class, 'getDocumentNotes'])->name('documents.notes.index');
Route::post('/documents/{document}/notes', [DocumentController::class, 'storeDocumentNote'])->name('documents.notes.store');
Route::patch('/documents/{document}/notes/{note}', [DocumentController::class, 'updateDocumentNote'])->name('documents.notes.update');
Route::delete('/documents/{document}/notes/{note}', [DocumentController::class, 'deleteDocumentNote'])->name('documents.notes.destroy');
Route::post('/documents/{document}/edit-pdfjs/rewrite-tj', [DocumentController::class, 'editPdfjsRewriteTj'])->name('documents.editPdfjsRewriteTj');
Route::post('/documents/{document}/edit-pdfjs/redact-source-text', [DocumentController::class, 'editPdfjsRedactSourceText'])->name('documents.editPdfjsRedactSourceText');
Route::post('/documents/{document}/edit-pdfjs/burn-layer', [DocumentController::class, 'editPdfjsBurnLayer'])->name('documents.editPdfjsBurnLayer');
Route::post('/documents/{document}/edit-pdfjs/move-tj', [DocumentController::class, 'editPdfjsMoveTj'])->name('documents.editPdfjsMoveTj');
Route::post('/documents/{document}/edit-pdfjs/reflow-text', [DocumentController::class, 'editPdfjsReflowText'])->name('documents.editPdfjsReflowText');
Route::get('/documents/{document}/edit2', [DocumentController::class, 'edit2'])->name('documents.edit2');
Route::get('/documents/{document}/ai', [DocumentController::class, 'ai'])->name('documents.ai');
Route::get('/documents/{document}/guided', [DocumentController::class, 'guided'])->name('documents.guided');
Route::get('/documents/{document}/fullscreen', [DocumentController::class, 'fullscreen'])->name('documents.fullscreen');
Route::get('/documents/{document}/edit-extracted', [DocumentController::class, 'editExtractedText'])->name('documents.editExtracted');
Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
Route::get('/documents/{document}/annotation-assets/{filename}', [DocumentController::class, 'annotationAsset'])->name('documents.annotationAsset');
Route::get('/documents/{document}/original-file', [DocumentController::class, 'originalFile'])->name('documents.originalFile');
Route::post('/documents/{document}/save', [DocumentController::class, 'save'])->name('documents.save');
Route::post('/documents/{document}/rename', [DocumentController::class, 'rename'])->name('documents.rename');
Route::post('/documents/{document}/restore-original', [DocumentController::class, 'restoreOriginal'])->name('documents.restoreOriginal');
Route::post('/documents/{document}/flatten-rotations', [DocumentController::class, 'flattenRotations'])->name('documents.flattenRotations');
Route::post('/documents/{document}/apply-rotations', [DocumentController::class, 'applyRotations'])->name('documents.applyRotations');
Route::get('/documents/{document}/apply-rotations', function () {
    return response()->json(['error' => 'This endpoint only accepts POST requests'], 405);
});
Route::post('/documents/{document}/save-annotations', [DocumentController::class, 'saveAnnotations'])->name('documents.saveAnnotations');
Route::get('/documents/{document}/saved-annotations', [DocumentController::class, 'getSavedAnnotations'])->name('documents.getSavedAnnotations');
Route::get('/documents/saved-pdf-options', [DocumentController::class, 'listSavedPdfOptions'])->name('documents.savedPdfOptions');
Route::delete('/documents/{document}/saved-pdf-option', [DocumentController::class, 'deleteSavedPdfOption'])->name('documents.deleteSavedPdfOption');
Route::post('/documents/{document}/load-saved-pdf', [DocumentController::class, 'loadSavedPdf'])->name('documents.loadSavedPdf');
Route::post('/documents/{document}/mark-annotations-saved', [DocumentController::class, 'markAnnotationsSaved'])->name('documents.markAnnotationsSaved');
Route::post('/documents/{document}/delete-annotations', [DocumentController::class, 'deleteAnnotations'])->name('documents.deleteAnnotations');
Route::post('/documents/{document}/annotation-debug', [DocumentController::class, 'saveAnnotationDebug'])->name('documents.annotationDebug.save');
Route::post('/documents/{document}/apply-annotations-direct', [DocumentController::class, 'applyAnnotationsDirect'])->name('documents.applyAnnotationsDirect');
Route::post('/documents/overwrite-annotation-text', [DocumentController::class, 'overwriteAnnotationText'])->name('documents.overwriteAnnotationText');
Route::post('/documents/{document}/save-acro-form-state', [DocumentController::class, 'saveAcroFormState'])->name('documents.saveAcroFormState');
Route::post('/documents/{document}/save-annotation-state', [DocumentController::class, 'saveAnnotationState'])->name('documents.saveAnnotationState');
Route::post('/documents/{document}/download-annotated-pdf', [DocumentController::class, 'downloadAnnotatedPdf'])->name('documents.downloadAnnotatedPdf');
Route::get('/documents/{document}/saved-acro-form-state', [DocumentController::class, 'getSavedAcroFormState'])->name('documents.getSavedAcroFormState');
Route::post('documents/{document}/process-ocr', [DocumentController::class, 'processOcr'])->name('documents.processOcr');
Route::get('documents/{document}/extraction-data', [DocumentController::class, 'getExtractionData'])->name('documents.getExtractionData');
Route::post('documents/{document}/process-fitz', [DocumentController::class, 'processFitz'])->name('documents.processFitz');
Route::get('documents/{document}/fitz-extraction-data', [DocumentController::class, 'getFitzExtractionData'])->name('documents.getFitzExtractionData');
Route::match(['get', 'post'], '/documents/{document}/prepare-overlay', [DocumentController::class, 'prepareOverlay'])->name('documents.prepareOverlay');
Route::get('/documents/{document}/clean-pdf', [DocumentController::class, 'cleanPdf'])->name('documents.cleanPdf');
Route::get('/documents/{document}/baked-pdf', [DocumentController::class, 'bakedPdf'])->name('documents.bakedPdf');
Route::get('/documents/{document}/edit/saved', [DocumentController::class, 'savedEditPreview'])->name('documents.savedEdit');
Route::get('/documents/{document}/edit/saved/image/{variant}', [DocumentController::class, 'savedEditPreviewImage'])->name('documents.savedEditImage');
Route::get('/documents/{document}/fonts', [DocumentController::class, 'getFonts'])->name('documents.getFonts');
Route::post('/documents/{document}/save-edits', [DocumentController::class, 'saveEdits'])->name('documents.saveEdits');
Route::post('/documents/{document}/live-save', [DocumentController::class, 'liveSave'])->name('documents.liveSave');
Route::post('/documents/{document}/create-working-copy-snapshot', [DocumentController::class, 'createWorkingCopySnapshot'])->name('documents.createWorkingCopySnapshot');
Route::post('/documents/{document}/restore-working-copy', [DocumentController::class, 'restoreWorkingCopy'])->name('documents.restoreWorkingCopy');
Route::post('/documents/{document}/discard-working-copy-snapshot', [DocumentController::class, 'discardWorkingCopySnapshot'])->name('documents.discardWorkingCopySnapshot');
Route::post('/documents/{document}/save-image', [DocumentController::class, 'saveImage'])->name('documents.saveImage');
Route::post('/documents/{document}/match-fonts', [DocumentController::class, 'matchFonts'])->name('documents.matchFonts');
Route::post('/documents/{document}/reorder-pages', [DocumentController::class, 'reorderPages'])->name('documents.reorderPages');
Route::post('/documents/{document}/merge-pdfs', [DocumentController::class, 'mergePdfs'])->name('documents.mergePdfs');
Route::post('/documents/{document}/add-blank-page', [DocumentController::class, 'addBlankPage'])->name('documents.addBlankPage');
Route::post('/documents/{document}/rotate-page', [DocumentController::class, 'rotatePage'])->name('documents.rotatePage');
Route::post('/documents/{document}/regenerate-invoice', [DocumentController::class, 'regenerateInvoice'])->name('documents.regenerateInvoice');
Route::post('/documents/{document}/regenerate-template', [DocumentController::class, 'regenerateTemplate'])->name('documents.regenerateTemplate');
Route::post('/documents/{document}/convert-guided-acroform', [DocumentController::class, 'convertGuidedAcroForm'])->name('documents.convertGuidedAcroForm');
Route::post('/documents/{document}/convert-html-to-pdf', [DocumentController::class, 'convertHtmlToPdf'])->name('documents.convertHtmlToPdf');
Route::post('/documents/{document}/save-guided-form', [DocumentController::class, 'saveGuidedFormData'])->name('documents.saveGuidedForm');
Route::post('/documents/{document}/screenshot', [DocumentController::class, 'takeScreenshot'])->name('documents.takeScreenshot');
Route::post('/documents/{document}/convert-to-pdfa', [DocumentController::class, 'convertToPdfA'])->name('documents.convertToPdfA');
Route::post('/documents/{document}/convert-to-word', [DocumentController::class, 'queueWordConversion'])->middleware('auth:web,admin')->name('documents.convertToWord');
Route::post('/documents/{document}/convert-to-excel', [DocumentController::class, 'queueExcelConversion'])->middleware('auth:web,admin')->name('documents.convertToExcel');
Route::get('/documents/{document}/conversions/{conversion}', [DocumentController::class, 'conversionStatus'])
    ->middleware('auth:web,admin')
    ->name('documents.conversions.status');
Route::post('/documents/{document}/pdf-password/unlock', [DocumentController::class, 'unlockPdfPassword'])
    ->middleware('throttle:10,1')
    ->name('documents.unlockPdfPassword');
Route::post('/documents/{document}/encrypt-pdf', [DocumentController::class, 'encryptPdf'])->name('documents.encryptPdf');
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

Route::get('/overlay-editor/test-files', [OverlayEditorTestController::class, 'getTestFiles'])->name('overlayEditor.testFiles');
Route::post('/overlay-editor/run-single-test', [OverlayEditorTestController::class, 'runSingleTest'])->name('overlayEditor.runSingleTest');

Route::get('/shapes/test-files', [ShapeTestController::class, 'getTestFiles'])->name('shapes.testFiles');
Route::post('/shapes/run-single-test', [ShapeTestController::class, 'runSingleTest'])->name('shapes.runSingleTest');
Route::post('/shapes/run-all-tests', [ShapeTestController::class, 'runAllTests'])->name('shapes.runAllTests');

// Account-scoped saved signatures for the signature modal (NK_Dev_4).
// The controller resolves the web/admin guard itself and answers guests with
// a 401 + message rather than a redirect, since these are fetched by JS.
Route::get('/saved-signatures', [SavedSignatureController::class, 'index'])->name('savedSignatures.index');
Route::post('/saved-signatures', [SavedSignatureController::class, 'store'])->name('savedSignatures.store');
Route::patch('/saved-signatures/{savedSignature}', [SavedSignatureController::class, 'update'])->name('savedSignatures.update');
Route::delete('/saved-signatures/{savedSignature}', [SavedSignatureController::class, 'destroy'])->name('savedSignatures.destroy');

Route::middleware('auth:admin')
    ->prefix('/automated-tests')
    ->name('automatedTests.')
    ->group(function () {
        Route::get('/{suite}/suite', [AutomatedTestController::class, 'suite'])->name('suite');
        Route::post('/{suite}/run', [AutomatedTestController::class, 'run'])->name('run');
        Route::get('/{suite}/artifacts/{filename}', [AutomatedTestController::class, 'artifact'])->name('artifact');
    });

Route::get('/pdf-tests/test-files', [PdfTestController::class, 'getTestFiles'])->name('pdfTests.testFiles');
Route::post('/pdf-tests/run-single-test', [PdfTestController::class, 'runSingleTest'])->name('pdfTests.runSingleTest');
Route::match(['GET', 'POST'], '/pdf-tests/create-blank', [PdfTestController::class, 'createBlank'])->name('pdfTests.createBlank');
Route::get('/pdf-tests/artifacts/{filename}', [PdfTestController::class, 'artifact'])->name('pdfTests.artifact');
Route::get('/pdf-tests/document/{document}/info', [PdfTestController::class, 'documentInfo'])->name('pdfTests.documentInfo');
Route::get('/pdf-tests/document/{document}/annotation-debug', [PdfTestController::class, 'annotationDebug'])->name('pdfTests.annotationDebug');
Route::post('/pdf-tests/document/{document}/flag-annotation', [PdfTestController::class, 'flagAnnotation'])->name('pdfTests.flagAnnotation');
Route::post('/pdf-tests/document/{document}/render-annotations', [PdfTestController::class, 'renderAnnotations'])->name('pdfTests.renderAnnotations');

Route::middleware('auth:admin')
    ->prefix('/pdf-tests/upload-tests')
    ->name('pdfTests.uploadTests.')
    ->group(function () {
        Route::get('/', [PdfUploadTestController::class, 'index'])->name('index');
        Route::post('/', [PdfUploadTestController::class, 'store'])->name('store');
        Route::get('/{pdfUploadTest}/review', [PdfUploadTestController::class, 'review'])->name('review');
        Route::patch('/{pdfUploadTest}', [PdfUploadTestController::class, 'update'])->name('update');
        Route::patch('/{pdfUploadTest}/paragraph-grouping', [PdfUploadTestController::class, 'updateParagraphGrouping'])
            ->name('paragraphGrouping');
        Route::delete('/{pdfUploadTest}', [PdfUploadTestController::class, 'destroy'])->name('destroy');
        Route::get('/{pdfUploadTest}/original', [PdfUploadTestController::class, 'original'])->name('original');
    });

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
Route::get('/domain-search/saved-domains/refresh-status', [DomainSearchController::class, 'savedDomainsRefreshStatus'])->middleware('auth:web,admin')->name('domainSearch.savedDomainsRefreshStatus');
Route::post('/domain-search/saved-domains/refresh', [DomainSearchController::class, 'refreshSavedDomains'])->middleware('auth:web,admin')->name('domainSearch.refreshSavedDomains');
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
Route::get('/domain-search/logo-generator-settings', [DomainSearchController::class, 'getLogoGeneratorSettings'])->middleware('auth:web,admin')->name('domainSearch.logoSettings.get');
Route::post('/domain-search/logo-generator-settings', [DomainSearchController::class, 'saveLogoGeneratorSettings'])->middleware('auth:web,admin')->name('domainSearch.logoSettings.save');
Route::get('/domain-search/user-logos', [DomainSearchController::class, 'userLogos'])->middleware('auth:web,admin')->name('domainSearch.userLogos');
Route::get('/generated-images/{logoRequest}/preview/{index}', GeneratedImagePreviewController::class)->whereNumber('index')->middleware('auth:web,admin')->name('generatedImages.preview');
Route::get('/generated-images/{logoRequest}/original/{index}', [GeneratedImagePreviewController::class, 'original'])->whereNumber('index')->middleware('auth:web,admin')->name('generatedImages.original');
Route::post('/domain-search/save-processed-svg', [DomainSearchController::class, 'saveProcessedSvg'])->middleware('auth:web,admin')->name('domainSearch.saveProcessedSvg');

// Stripe Credits
Route::post('/credits/checkout', [CreditController::class, 'createCheckout'])->middleware('auth')->name('credits.checkout');
Route::get('/credits/checkout/success', [CreditController::class, 'checkoutSuccess'])->middleware('auth')->name('credits.checkout.success');
Route::post('/subscription/checkout', [CreditController::class, 'createSubscriptionCheckout'])->middleware('auth')->name('subscription.checkout');
Route::post('/subscription/cancel', [CreditController::class, 'cancelSubscription'])->middleware('auth')->name('subscription.cancel');
Route::post('/stripe/webhook', [CreditController::class, 'handleWebhook'])->name('stripe.webhook');
