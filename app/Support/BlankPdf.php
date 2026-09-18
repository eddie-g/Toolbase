<?php

namespace App\Support;

/**
 * A one-page blank PDF, written by hand. "Create blank document" used to fork
 * Python inside the web request for this; the file is forty lines of text.
 */
class BlankPdf
{
    public static function make(float $widthPt, float $heightPt): string
    {
        $width = self::number($widthPt);
        $height = self::number($heightPt);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Resources << >> /Contents 4 0 R >>",
            "<< /Length 0 >>\nstream\n\nendstream",
        ];

        // The second line is the binary marker that tells transfer tools the file is not text.
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format(max(1.0, $value), 2, '.', ''), '0'), '.');
    }
}
