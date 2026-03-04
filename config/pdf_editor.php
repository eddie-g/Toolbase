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

    /*
    |--------------------------------------------------------------------------
    | Enforce Overlay Geometry
    |--------------------------------------------------------------------------
    |
    | When enabled, overlay text saves are forced through surgical mode to
    | preserve user-defined overlay bbox geometry exactly.
    |
    | DISABLED by default: surgical_save redacts the full outer block bbox which
    | can sweep up neighbouring text (e.g. a subheader whose y-range overlaps the
    | headline block) causing silent data-loss.  full_page_save already respects
    | the edit bbox geometry, so overriding to surgical_save is not necessary.
    |
    */
    'enforce_overlay_geometry' => env('PDF_ENFORCE_OVERLAY_GEOMETRY', false),
];
