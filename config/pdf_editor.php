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
        'timeout_seconds' => (int) env('PDF_MERGE_TIMEOUT_SECONDS', 120),
    ],
];
