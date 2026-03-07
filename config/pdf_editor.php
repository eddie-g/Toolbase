<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PDF Save Mode
    |--------------------------------------------------------------------------
    |
    | full_page_save: rebuild from clean PDF + extraction data
    | surgical_save: redact and replace only edited blocks
    | new_save_mode: strict surgical save
    |                only matched edited text is removed / redrawn
    |
    */
    'save_mode' => env('PDF_SAVE_MODE', 'full_page_save'),
];
