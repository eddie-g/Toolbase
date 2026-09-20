<?php

/*
|--------------------------------------------------------------------------
| Rate limits for the PDF editor
|--------------------------------------------------------------------------
|
| Every editor route that forks Python or writes state belongs to one of the
| classes below (App\Http\Middleware\ThrottleEditorRoutes looks the route name
| up here). An account is limited as itself. A guest is limited per session
| and address, and again per address alone at guest_ip_factor times the limit,
| because a script can simply drop its cookies.
|
| RateLimitCoverageTest fails when a new state-changing editor route is in
| neither "routes" nor "own_limiter": classify it when you add it.
|
*/

return [

    // Multiplies every limit. The QA suites upload a fixture per case and
    // export after most of them, so local development runs far above the
    // production numbers.
    'scale' => (float) env('EDITOR_RATE_LIMIT_SCALE', env('APP_ENV', 'production') === 'local' ? 20 : 1),

    'guest_ip_factor' => (int) env('EDITOR_RATE_LIMIT_GUEST_IP_FACTOR', 4),

    'classes' => [
        // New documents: each one queues an extraction and takes disk.
        'upload' => ['per_minute' => 5, 'per_day' => 50, 'what' => 'new documents'],
        // Whole-document output: one to three Python processes or a paid conversion.
        'export' => ['per_minute' => 5, 'what' => 'downloads and conversions'],
        // Whole-document processing: extraction, overlay preparation, merges, restores.
        'process' => ['per_minute' => 10, 'what' => 'document processing requests'],
        // One edit applied to the PDF or its pages. Far above what a person can do by hand.
        'edit' => ['per_minute' => 60, 'what' => 'edits'],
        // Pages and files that may render through Python when not cached.
        'render' => ['per_minute' => 60, 'what' => 'requests'],
        // Small state writes: notes, names, flags, trash, signatures.
        'write' => ['per_minute' => 120, 'what' => 'changes'],
        // The documents page: previews for up to eight documents per view.
        'listing' => ['per_minute' => 30, 'what' => 'page loads'],
    ],

    'routes' => [
        'documents.store' => 'upload',
        'documents.createBlank' => 'upload',
        'documents.createAi' => 'upload',
        'documents.createFromTemplate' => 'upload',
        'documents.createSimpleInvoice' => 'upload',
        'documents.createFromGuidedTemplate' => 'upload',
        'forms.fill' => 'upload',
        'pdfTests.createBlank' => 'upload',

        'documents.downloadAnnotatedPdf' => 'export',
        'documents.convertToPdfA' => 'export',
        'documents.convertToWord' => 'export',
        'documents.convertToExcel' => 'export',
        'documents.encryptPdf' => 'export',
        'documents.splitPdf' => 'export',
        'documents.takeScreenshot' => 'export',

        'documents.processFitz' => 'process',
        'documents.processOcr' => 'process',
        'documents.prepareOverlay' => 'process',
        'documents.applyAnnotationsDirect' => 'process',
        'documents.mergePdfs' => 'process',
        'documents.restoreOriginal' => 'process',
        'documents.loadSavedPdf' => 'process',
        'documents.regenerateInvoice' => 'process',
        'documents.regenerateTemplate' => 'process',
        'documents.convertGuidedAcroForm' => 'process',
        'documents.convertHtmlToPdf' => 'process',
        'documents.matchFonts' => 'process',
        'documents.flattenRotations' => 'process',
        'documents.applyRotations' => 'process',
        'documents.restoreWorkingCopy' => 'process',
        'pdfTests.renderAnnotations' => 'process',
        'pdfTests.runSingleTest' => 'process',

        'documents.uploadAnnotationAsset' => 'edit',
        'documents.editPdfjsRewriteTj' => 'edit',
        'documents.editPdfjsRedactSourceText' => 'edit',
        'documents.editPdfjsBurnLayer' => 'edit',
        'documents.editPdfjsMoveTj' => 'edit',
        'documents.editPdfjsReflowText' => 'edit',
        'documents.overwriteAnnotationText' => 'edit',
        'pdfState.stampPreview' => 'edit',
        'documents.addBlankPage' => 'edit',
        'documents.rotatePage' => 'edit',
        'documents.reorderPages' => 'edit',
        'documents.saveImage' => 'edit',
        'documents.liveSave' => 'edit',
        'documents.saveEdits' => 'edit',
        'documents.save' => 'edit',
        'documents.createWorkingCopySnapshot' => 'edit',

        'documents.cleanPdf' => 'render',
        'documents.bakedPdf' => 'render',
        'documents.getFonts' => 'render',
        'documents.savedEdit' => 'render',
        'documents.savedEditImage' => 'render',
        'pdfTests.documentInfo' => 'render',

        'documents.saveAnnotations' => 'write',
        'documents.markAnnotationsSaved' => 'write',
        'documents.deleteAnnotations' => 'write',
        'documents.annotationDebug.save' => 'write',
        'documents.saveAcroFormState' => 'write',
        'documents.saveGuidedForm' => 'write',
        'documents.notes.store' => 'write',
        'documents.notes.update' => 'write',
        'documents.notes.destroy' => 'write',
        'documents.rename' => 'write',
        'documents.logExport' => 'write',
        'documents.trash' => 'write',
        'documents.restore' => 'write',
        'documents.destroy' => 'write',
        'documents.bulkDestroy' => 'write',
        'documents.emptyTrash' => 'write',
        'documents.deleteSavedPdfOption' => 'write',
        'documents.discardWorkingCopySnapshot' => 'write',
        'savedSignatures.store' => 'write',
        'savedSignatures.update' => 'write',
        'savedSignatures.destroy' => 'write',
        'pdfTests.flagAnnotation' => 'write',

        'documents.index' => 'listing',
    ],

    // Routes that carry a limiter of their own in routes/web.php.
    'own_limiter' => [
        'documents.saveAnnotationState',   // throttle:editor-autosave
        'documents.processing.retry',      // throttle:6,1
        'documents.unlockPdfPassword',     // throttle:10,1
    ],

];
