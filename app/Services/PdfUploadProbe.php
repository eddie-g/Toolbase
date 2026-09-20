<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Is this upload a PDF the editor can work with? Asked before the file is
 * stored or anything is queued: a truncated file, one that needs a password,
 * or a 2,000-page scan would otherwise go straight to extraction and fail (or
 * grind) there, minutes later.
 *
 * check() returns null when the file is fine, or the refusal for the user.
 */
class PdfUploadProbe
{
    public function __construct(private PythonRunner $python)
    {
    }

    /** @return array{code:string, message:string}|null */
    public function check(string $path): ?array
    {
        // A PDF says so in its first kilobyte. Costs nothing, and keeps
        // renamed files away from the parser altogether.
        $head = (string) @file_get_contents($path, false, null, 0, 1024);
        if (! str_contains($head, '%PDF-')) {
            return ['code' => 'not_a_pdf', 'message' => 'This file is not a PDF.'];
        }

        $result = $this->python->run([
            $this->python->interpreter('fitz'),
            base_path('python/pdf-editor/probe_pdf.py'),
            $path,
        ]);
        if ($result->timedOut) {
            return ['code' => 'too_complex', 'message' => 'This PDF is too complex to open here. Try saving it again from your PDF program, or upload a smaller part of it.'];
        }

        $probe = json_decode(trim($result->stdout), true);
        if (! is_array($probe)) {
            Log::warning('PDF upload probe gave no answer', ['exit_code' => $result->exitCode, 'stderr' => $result->stderrTail(500)]);

            return ['code' => 'unreadable', 'message' => 'This PDF could not be read. It may be damaged; try saving it again from your PDF program.'];
        }

        if (! ($probe['ok'] ?? false)) {
            return match ($probe['reason'] ?? '') {
                'needs_password' => ['code' => 'needs_password', 'message' => 'This PDF is protected with a password. Remove the password in your PDF program, then upload it again.'],
                'no_pages' => ['code' => 'no_pages', 'message' => 'This PDF has no pages.'],
                default => ['code' => 'unreadable', 'message' => 'This PDF could not be read. It may be damaged; try saving it again from your PDF program.'],
            };
        }

        $maxPages = max(1, (int) config('pdf_editor.uploads.max_pages', 500));
        if ((int) $probe['pages'] > $maxPages) {
            return [
                'code' => 'too_many_pages',
                'message' => sprintf('This PDF has %s pages; the editor takes up to %s. Split it and upload the part you need.', number_format((int) $probe['pages']), number_format($maxPages)),
            ];
        }

        return null;
    }
}
