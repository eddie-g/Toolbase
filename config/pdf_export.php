<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Annotated PDF export
    |--------------------------------------------------------------------------
    |
    | The editor's Download runs apply_annotations_direct*.py, which can take
    | many seconds on a large PDF. The editor asks for a queued export
    | (X-Export-Mode: queued): the request only stores the payload and
    | enqueues ExportAnnotatedPdfJob, then the client polls a status URL and
    | fetches the result through a signed, expiring link.
    |
    */

    'connection' => env('PDF_EXPORT_QUEUE_CONNECTION', 'redis'),
    'queue' => env('PDF_EXPORT_QUEUE', 'pdf-export'),

    // Whole-job budget. Each Python process inside it has its own, shorter
    // timeout in config/python.php, so a hung script reports cleanly before
    // the worker is killed.
    'job_timeout' => (int) env('PDF_EXPORT_JOB_TIMEOUT', 240),

    // A request without the queued header still renders inside the web
    // request. The QA suites and replay tools rely on that; production must
    // not, so it is off there unless explicitly enabled.
    'allow_sync' => (bool) env('PDF_EXPORT_ALLOW_SYNC', env('APP_ENV', 'production') !== 'production'),

    // How long a finished export (and its signed link) stays downloadable.
    'result_ttl_minutes' => (int) env('PDF_EXPORT_RESULT_TTL_MINUTES', 15),

    // Exports still queued after this long are failed by the cleanup command
    // (no worker picked them up).
    'stale_after_minutes' => (int) env('PDF_EXPORT_STALE_AFTER_MINUTES', 30),

];
