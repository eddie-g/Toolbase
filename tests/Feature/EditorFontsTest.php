<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The editor hosts its own web fonts (public/fonts/editor, written by
 * scripts/fetch-editor-fonts.mjs): nothing on the editor page asks Google for
 * a font, every file the stylesheets name is there, and the font picker only
 * offers families that are.
 */
class EditorFontsTest extends TestCase
{
    public function test_the_editor_and_its_modules_ask_no_third_party_for_fonts(): void
    {
        $sources = [
            resource_path('views/documents/edit-new-pdfjs.blade.php'),
            resource_path('js/edit-new/signature/font-loader.js'),
            ...File::glob(resource_path('js/edit-new-pdfjs/*.js')),
        ];
        foreach ($sources as $source) {
            $this->assertDoesNotMatchRegularExpression('/fonts\.(googleapis|gstatic)\.com|fonts\.bunny\.net/', File::get($source), basename($source));
        }

        $view = File::get(resource_path('views/documents/edit-new-pdfjs.blade.php'));
        $this->assertStringContainsString("asset('fonts/editor/editor-fonts.css')", $view);
    }

    public function test_every_file_the_stylesheets_name_is_there_and_nothing_points_outside(): void
    {
        $root = public_path('fonts/editor');
        $sheets = [$root.'/editor-fonts.css', ...File::glob($root.'/signature/*.css')];
        $this->assertCount(13, $sheets, 'the editor stylesheet and twelve signature families');

        $files = 0;
        foreach ($sheets as $sheet) {
            $css = File::get($sheet);
            $this->assertDoesNotMatchRegularExpression('#https?://#', $css, basename($sheet).' must only name local files');
            $this->assertStringContainsString('font-display: swap', $css);
            preg_match_all('/url\(([^)]+)\)/', $css, $matches);
            $this->assertNotEmpty($matches[1], basename($sheet));
            foreach (array_unique($matches[1]) as $url) {
                $path = realpath(dirname($sheet).'/'.$url);
                $this->assertNotFalse($path, basename($sheet)." names {$url}, which is not there");
                $this->assertStringStartsWith($root.'/files/', $path);
                $this->assertSame('wOF2', File::get($path, false) ? substr(File::get($path), 0, 4) : '', $url);
                $files++;
            }
        }
        $this->assertGreaterThan(400, $files);
    }

    public function test_the_picker_offers_only_hosted_or_system_families_and_each_has_its_licence(): void
    {
        $manifest = json_decode(File::get(resource_path('fonts/editor-fonts.json')), true);
        $css = File::get(public_path('fonts/editor/editor-fonts.css'));

        foreach (array_keys($manifest['editor']) as $family) {
            $this->assertStringContainsString("font-family: '{$family}'", $css, "{$family} has no @font-face");
        }

        // Every quoted family in the picker is a web font and must be hosted;
        // the unquoted ones (Arial, Helvetica, Times...) are the visitor's own.
        preg_match_all("/font-family: '([^']+)'/", File::get(resource_path('views/documents/edit-new/_format-bar.blade.php')), $picker);
        $missing = array_diff(array_unique($picker[1]), array_keys($manifest['editor']));
        $this->assertSame([], array_values($missing), 'the font picker offers a web font that is not hosted');

        foreach ([...array_keys($manifest['editor']), ...$manifest['signature']] as $family) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
            $licence = public_path("fonts/editor/licenses/{$slug}.txt");
            $this->assertFileExists($licence, "{$family} is hosted without its licence");
            $this->assertMatchesRegularExpression('/SIL Open Font License|Apache License|Ubuntu Font Licence/i', File::get($licence), $family);
        }
    }
}
