<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Python subprocesses
    |--------------------------------------------------------------------------
    |
    | Every Python (and other shell) call the app makes goes through
    | App\Services\PythonRunner: one interpreter resolver, a hard timeout on
    | every process, and a concurrency cap so a few slow PDFs cannot pin
    | every PHP worker. Timeouts are matched against the command line by
    | script name; the first key found wins, otherwise "default" applies.
    |
    */

    // First candidate tried when resolving the interpreter; the usual
    // virtualenv and system locations follow.
    'binary' => env('PYTHON_BINARY'),

    'timeouts' => [
        'default' => (int) env('PYTHON_TIMEOUT_SECONDS', 120),
        'apply_annotations_direct' => (int) env('PYTHON_TIMEOUT_EXPORT', 180),
        'extract_pdf_pymupdf' => (int) env('PYTHON_TIMEOUT_EXTRACT', 120),
        'render_page_preview' => 30,
        'create_blank_pdf' => 30,
        'merge_pdf_documents' => (int) env('PDF_MERGE_TIMEOUT_SECONDS', 120),
        'convert_pdf_to_word' => 300,
        'convert_pdf_to_excel' => 300,
        'pdfinfo' => 15,
    ],

    // Seconds without any output before a process is killed (0 = off).
    'idle_timeout' => (int) env('PYTHON_IDLE_TIMEOUT_SECONDS', 0),

    // Address-space cap per process in MB via ulimit -v (Linux only, 0 = off).
    // Off by default: torch and OpenCV reserve large virtual ranges.
    'memory_limit_mb' => (int) env('PYTHON_MEMORY_LIMIT_MB', 0),

    // At most this many Python processes at once per app server. A request
    // that cannot get a slot within slot_wait_seconds is answered 503 with
    // Retry-After instead of forking anyway.
    'max_concurrent' => max(1, (int) env('PYTHON_MAX_CONCURRENT', 4)),
    'slot_wait_seconds' => (float) env('PYTHON_SLOT_WAIT_SECONDS', 8),
    'lock_store' => env('PYTHON_LOCK_STORE'),

];
