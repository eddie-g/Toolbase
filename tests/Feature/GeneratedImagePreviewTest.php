<?php

namespace Tests\Feature;

use App\Http\Controllers\GeneratedImagePreviewController;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class GeneratedImagePreviewTest extends TestCase
{
    public function test_generated_image_preview_creates_small_jpeg_thumbnail(): void
    {
        Storage::fake('public');

        $source = imagecreatetruecolor(1200, 800);
        $fill = imagecolorallocate($source, 30, 90, 160);
        imagefill($source, 0, 0, $fill);

        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        Storage::disk('public')->put('generated-logos/large.png', $png);

        $controller = app(GeneratedImagePreviewController::class);
        $method = new ReflectionMethod($controller, 'createJpegPreview');
        $method->setAccessible(true);
        $method->invoke($controller, 'generated-logos/large.png', 'generated-image-previews/1/0-test.jpg');

        $files = Storage::disk('public')->files('generated-image-previews/1');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', $files[0]);

        $preview = imagecreatefromstring(Storage::disk('public')->get($files[0]));
        $this->assertNotFalse($preview);
        $this->assertLessThanOrEqual(360, imagesx($preview));
        $this->assertLessThanOrEqual(270, imagesy($preview));
        imagedestroy($preview);
    }
}
