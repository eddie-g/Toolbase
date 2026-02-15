<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PDF Save Mode
    |--------------------------------------------------------------------------
    |
    | full_page_save: rebuild from clean PDF + extraction data
    | surgical_save: redact and replace only edited blocks
    |
    */
    'save_mode' => env('PDF_SAVE_MODE', 'full_page_save'),
];

