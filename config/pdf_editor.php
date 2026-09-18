<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PDF Materialization Mode
    |--------------------------------------------------------------------------
    |
    | fitz_extraction: use raw Fitz extraction JSON as the redraw/export source
    | annotation_base: materialize Fitz extraction into pdf_state/pdf_groups and
    |                  reconstruct redraw/export blocks from those tables
    |
    */
    'mode' => env('PDF_MODE', env('PDF_mode', 'fitz_extraction')),

    /*
    |--------------------------------------------------------------------------
    | PDF Save Mode
    |--------------------------------------------------------------------------
    |
    | full_page_save: rebuild from clean PDF + extraction data
    | live_save: surgical single-edit redaction/rewrite pipeline
    | surgical_save: legacy redact-and-reinsert pipeline
    | new_save_mode: strict surgical save
    | targeted_save: only remove/rewrite explicitly changed fields
    |
    */
    'save_mode' => env('PDF_SAVE_MODE', 'full_page_save'),

    /*
    |--------------------------------------------------------------------------
    | Editor Layout Mode
    |--------------------------------------------------------------------------
    |
    | default: existing promoted-annotation/editor behavior
    | bounding_box_edit: render the source PDF first, then place extraction
    |                    bounding boxes over it and edit/persist one box at a time
    |
    */
    'layout_mode' => env('LAYOUT_MODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | How many documents an account or a guest may create (App\Services\
    | UploadQuota), and what a PDF must look like to be accepted at all
    | (App\Services\PdfUploadProbe), checked before anything is stored or
    | queued. The HTTP rate limits in config/editor_limits.php count attempts;
    | these count documents.
    |
    */
    'uploads' => [
        'max_kb' => (int) env('PDF_UPLOAD_MAX_KB', 20480),
        'max_pages' => (int) env('PDF_UPLOAD_MAX_PAGES', 500),
        // Accounts without the PDF editor plan, per calendar month.
        'monthly_limit' => (int) env('PDF_UPLOAD_MONTHLY_LIMIT', 100),
        // Without an account: per session and address, and per address alone
        // (several people behind one address; scripts that drop cookies).
        // Local development is effectively unlimited: the QA suites upload a
        // fixture per case as a guest.
        'guest_daily_limit' => (int) env('PDF_UPLOAD_GUEST_DAILY_LIMIT', env('APP_ENV', 'production') === 'local' ? 5000 : 5),
        'guest_daily_limit_per_ip' => (int) env('PDF_UPLOAD_GUEST_DAILY_LIMIT_PER_IP', env('APP_ENV', 'production') === 'local' ? 20000 : 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload processing (text extraction)
    |--------------------------------------------------------------------------
    |
    | ProcessUploadedDocumentJob extracts a new document's text into editable
    | paragraphs. It has its own queue and Horizon supervisor so a slow PDF
    | never holds up mail or other jobs, and documents.processing_status tells
    | the editor where it is (App\Services\DocumentProcessing).
    |
    */
    'extraction' => [
        'connection' => env('PDF_EXTRACTION_QUEUE_CONNECTION', 'redis'),
        'queue' => env('PDF_EXTRACTION_QUEUE', 'pdf-extraction'),
        // Whole-job budget; each Python step inside has its own shorter
        // timeout (config/python.php), so a hung script reports cleanly first.
        'job_timeout' => (int) env('PDF_EXTRACTION_JOB_TIMEOUT', 300),
        // A document still "queued" after this long was never picked up (no
        // worker, lost job): the editor is told it failed and offers a retry.
        'stale_queued_seconds' => (int) env('PDF_EXTRACTION_STALE_QUEUED_SECONDS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autosave limits
    |--------------------------------------------------------------------------
    |
    | The editor posts its whole annotation state a couple of seconds after
    | every change. These bound one such request, so a runaway client or a
    | crafted one cannot make the server decode and store an unbounded body.
    | A refused save answers 413 or 422 with a message the editor shows.
    |
    */
    'autosave' => [
        'max_body_kb' => (int) env('PDF_AUTOSAVE_MAX_BODY_KB', 20480),
        'max_annotations' => (int) env('PDF_AUTOSAVE_MAX_ANNOTATIONS', 3000),
        // Characters of text in one annotation; rich-text HTML gets four times this.
        'max_text_length' => (int) env('PDF_AUTOSAVE_MAX_TEXT_LENGTH', 50000),
        // Saves per minute for one editor (account, or guest session) on one document.
        'saves_per_minute' => (int) env('PDF_AUTOSAVE_SAVES_PER_MINUTE', 60),
        // Backstop for clients that drop their cookies to dodge the limit above.
        'saves_per_minute_per_ip' => (int) env('PDF_AUTOSAVE_SAVES_PER_MINUTE_PER_IP', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Split Paragraph Fully In Edit Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, multi-line positioned overlay paragraphs keep their layout
    | locked on edit entry and are expanded into individually-positioned
    | character spans instead of being normalized into flowing text.
    |
    */
    'split_paragraph_fully' => env('SPLIT_PARAGRAPH_FULLY', false),

    /*
    |--------------------------------------------------------------------------
    | Line Grouping Editor Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, promoted extraction blocks may be split into smaller
    | line-level editor annotations. When disabled, the editor keeps the
    | original extracted paragraph/block grouping.
    |
    */
    'line_grouping_editor_mode' => env('LINE_GROUPING_EDITOR_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Editor Debug Controls
    |--------------------------------------------------------------------------
    |
    | Exposes debug-only toolbar actions in the PDF editor, including direct
    | links to original/redacted files and restore-preview controls.
    |
    */
    'editor_debug' => env('EDITOR_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | PDF Merge Limits
    |--------------------------------------------------------------------------
    |
    | Merge inputs are inspected again by PyMuPDF on the server. These values
    | bound both the HTTP upload and the generated document.
    |
    */
    'merge' => [
        'max_files' => (int) env('PDF_MERGE_MAX_FILES', 10),
        'max_file_kb' => (int) env('PDF_MERGE_MAX_FILE_KB', 20480),
        'max_total_bytes' => (int) env('PDF_MERGE_MAX_TOTAL_BYTES', 104857600),
        'max_pages' => (int) env('PDF_MERGE_MAX_PAGES', 1000),
        'max_file_pages' => (int) env('PDF_MERGE_MAX_FILE_PAGES', 100),
        'timeout_seconds' => (int) env('PDF_MERGE_TIMEOUT_SECONDS', 120),
    ],
];
