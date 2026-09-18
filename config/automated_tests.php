<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin credentials for the Playwright suites
    |--------------------------------------------------------------------------
    |
    | Some editor controls are gated server-side behind the `admin` guard --
    | the Text Options debug mask and annotation ID field, for example. No
    | client-side shim can render those, so a suite that needs to exercise them
    | has to sign in through the real Filament login form.
    |
    | Leave these unset and the suites simply skip their admin-only assertions
    | rather than failing, so the catalogue still runs on a machine that has no
    | QA admin account.
    |
    */

    'admin_email' => env('AUTOMATED_TESTS_ADMIN_EMAIL'),

    'admin_password' => env('AUTOMATED_TESTS_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Regression runner (PdfTestController)
    |--------------------------------------------------------------------------
    |
    | Extra directories searched for a report's artifacts, and the port the
    | app listens on when the runner scripts call back into it (Sail's
    | APP_PORT). Development tooling only.
    |
    */

    'output_dir' => env('PDF_TEST_OUTPUT_DIR'),

    'fallback_output_dir' => env('PDF_TEST_FALLBACK_OUTPUT_DIR'),

    'app_port' => (string) env('APP_PORT', '80'),

];
