<?php

namespace Tests\Unit;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class DocumentEmbeddedFontsCacheTest extends TestCase
{
    public function test_cached_fonts_are_returned_without_resolving_python(): void
    {
        Storage::fake('local');

        $document = $this->makeDocument();
        $embeddedFontsPath = $this->embeddedFontsPath($document);
        $embeddedFonts = [
            'HelveticaNeueLTStd-Roman' => [
                'clean_name' => 'Helvetica Neue',
                'pdf_font_name' => 'HelveticaNeueLTStd-Roman',
                'family' => 'Helvetica Neue',
                'file_path' => '/tmp/HelveticaNeueLTStd-Roman.otf',
                'file_ext' => 'otf',
                'css_weight' => '400',
                'css_style' => 'normal',
                'css_stretch' => 'normal',
                'xref' => 12,
            ],
        ];

        $this->writeFontCache($embeddedFontsPath, $embeddedFonts);

        $controller = new FontResolverTrackingDocumentController();

        try {
            $response = $controller->getFonts($document);
        } finally {
            @unlink($embeddedFontsPath);
        }

        $payload = $response->getData(true);

        $this->assertSame(0, $controller->pythonResolverCalls);
        $this->assertTrue($payload['success']);
        $this->assertSame($embeddedFonts, $payload['embedded_fonts']);
        $this->assertSame('Helvetica Neue', $payload['fonts'][0]['name']);
        $this->assertSame(12, $payload['fonts'][0]['xref']);
    }

    public function test_empty_valid_font_cache_does_not_resolve_python(): void
    {
        Storage::fake('local');

        $document = $this->makeDocument();
        $embeddedFontsPath = $this->embeddedFontsPath($document);
        $this->writeFontCache($embeddedFontsPath, []);

        $controller = new FontResolverTrackingDocumentController();

        try {
            $response = $controller->getFonts($document);
        } finally {
            @unlink($embeddedFontsPath);
        }

        $this->assertSame(0, $controller->pythonResolverCalls);
        $this->assertSame([], $response->getData(true)['fonts']);
    }

    public function test_invalid_font_cache_resolves_python_before_extraction(): void
    {
        Storage::fake('local');

        $document = $this->makeDocument();
        $embeddedFontsPath = $this->embeddedFontsPath($document);
        if (!is_dir(dirname($embeddedFontsPath))) {
            mkdir(dirname($embeddedFontsPath), 0755, true);
        }
        file_put_contents($embeddedFontsPath, '{invalid-json');

        $controller = new FontResolverTrackingDocumentController();
        $controller->throwWhenResolving = true;

        try {
            $controller->getFonts($document);
            $this->fail('An invalid font cache should enter the Python extraction path.');
        } catch (LogicException $exception) {
            $this->assertSame('Python resolver invoked', $exception->getMessage());
        } finally {
            @unlink($embeddedFontsPath);
        }

        $this->assertSame(1, $controller->pythonResolverCalls);
    }

    private function makeDocument(): Document
    {
        $document = new Document();
        $document->id = random_int(900000000, 999999999);
        $document->path = 'documents/cached-fonts-' . $document->id . '.pdf';
        $document->mime_type = 'application/pdf';
        $document->original_name = 'cached-fonts.pdf';

        Storage::put($document->path, "%PDF-1.4\n%%EOF\n");

        return $document;
    }

    private function embeddedFontsPath(Document $document): string
    {
        return storage_path("app/temp/embedded_fonts_{$document->id}.json");
    }

    private function writeFontCache(string $path, array $fonts): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($fonts, JSON_THROW_ON_ERROR));
    }
}

class FontResolverTrackingDocumentController extends DocumentController
{
    public int $pythonResolverCalls = 0;

    public bool $throwWhenResolving = false;

    protected function resolvePythonBinaryForPdfEditor(string|array|null $requiredModule = null): string
    {
        $this->pythonResolverCalls++;

        if ($this->throwWhenResolving) {
            throw new LogicException('Python resolver invoked');
        }

        return '/bin/false';
    }
}
