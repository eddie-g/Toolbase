<?php

namespace Tests\Unit;

use App\Services\RecraftPricing;
use PHPUnit\Framework\TestCase;

/**
 * NK_41: Ray PRO raster is Recraft's plain V4 model, which only accepts 1024x1024, 1344x768 and
 * 768x1344. Sending the V4 Pro tier sizes (2048x2048 etc.) made every Ray PRO request fail with
 * "Recraft V4 doesn't support 2048x2048 image size".
 */
class RecraftRequestSizeTest extends TestCase
{
    /** Sizes taken from Recraft's "Image sizes" appendix per model. */
    private const V4_SIZES = ['1024x1024', '1344x768', '768x1344'];

    private const V4_PRO_TIER_SIZES = ['2048x2048', '2688x1536', '1536x2688'];

    private const V2_SIZES = [
        '1024x1024', '1365x1024', '1024x1365', '1536x1024', '1024x1536', '1820x1024', '1024x1820',
        '1024x2048', '2048x1024', '1434x1024', '1024x1434', '1024x1280', '1280x1024', '1024x1707', '1707x1024',
    ];

    public function test_ray_pro_square_sends_a_size_recraft_v4_accepts(): void
    {
        $this->assertSame('1024x1024', RecraftPricing::requestSize('raster', true, '1:1'));
    }

    public function test_ray_pro_never_sends_a_v4_pro_tier_size(): void
    {
        foreach (['1:1', '16:9', '9:16'] as $imageSize) {
            $size = RecraftPricing::requestSize('raster', true, $imageSize);
            $this->assertContains($size, self::V4_SIZES, "Ray PRO {$imageSize} sends {$size}, which recraftv4 rejects");
            $this->assertNotContains($size, self::V4_PRO_TIER_SIZES);
        }
    }

    public function test_ray_pro_landscape_and_portrait_map_to_the_v4_sizes(): void
    {
        $this->assertSame('1344x768', RecraftPricing::requestSize('raster', true, '16:9'));
        $this->assertSame('768x1344', RecraftPricing::requestSize('raster', true, '9:16'));
    }

    public function test_regular_ray_sends_sizes_recraft_v2_accepts(): void
    {
        $this->assertSame('1024x1024', RecraftPricing::requestSize('raster', false, '1:1'));
        $this->assertSame('1820x1024', RecraftPricing::requestSize('raster', false, '16:9'));
        $this->assertSame('1024x1820', RecraftPricing::requestSize('raster', false, '9:16'));
        foreach (['1:1', '16:9', '9:16'] as $imageSize) {
            $this->assertContains(RecraftPricing::requestSize('raster', false, $imageSize), self::V2_SIZES);
        }
    }

    public function test_vector_logos_are_requested_square_as_an_aspect_ratio(): void
    {
        foreach (['1:1', '16:9', '9:16'] as $imageSize) {
            $this->assertSame('1:1', RecraftPricing::requestSize('vector', true, $imageSize));
            $this->assertSame('1:1', RecraftPricing::requestSize('vector', false, $imageSize));
        }
    }
}
