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
];
