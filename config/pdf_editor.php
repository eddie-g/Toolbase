<?php

return [
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
];
