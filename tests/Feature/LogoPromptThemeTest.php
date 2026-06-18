<?php

namespace Tests\Feature;

use App\Http\Controllers\DomainSearchController;
use App\Jobs\GenerateLogoJob;
use ReflectionMethod;
use Tests\TestCase;

class LogoPromptThemeTest extends TestCase
{
    public function test_default_real_estate_ray_vector_prompt_uses_property_language_without_roof_peaks(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'buildLogoPromptText');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs(
            $controller,
            [
                null,
                'default',
                'logo',
                '1:1',
                true,
                false,
                '',
                'real_estate',
                'white',
                'recraft',
                'vector',
                null,
                'none',
                'medium',
                'modern_sans',
            ],
        );

        $this->assertStringStartsWith('ICON ONLY.', $prompt);
        $this->assertStringContainsString('modern', strtolower($prompt));
        $this->assertStringContainsString('premium property or real estate', $prompt);
        $this->assertStringContainsString('property or real estate', $prompt);
        $this->assertStringContainsString('simplified building silhouettes', $prompt);
        $this->assertStringContainsString('window grids', $prompt);
        $this->assertStringContainsString('map pins', $prompt);
        $this->assertStringContainsString('generous white space', $prompt);
        $this->assertStringContainsString('Do not create company names', $prompt);
        $this->assertStringContainsString('fake words', $prompt);
        $this->assertStringNotContainsString('swoosh', strtolower($prompt));
        $this->assertStringNotContainsString('roof peak', strtolower($prompt));
        $this->assertStringNotContainsString('pitched roof', strtolower($prompt));
        $this->assertStringNotContainsString('red roof', strtolower($prompt));
        $this->assertStringNotContainsString('houses', strtolower($prompt));
        $this->assertStringNotContainsString('Logo mark.', $prompt);
    }

    public function test_default_real_estate_prompt_keeps_roofs_and_swoosh_when_user_asks_for_them(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'buildLogoPromptText');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs(
            $controller,
            [
                null,
                'default',
                'logo',
                '1:1',
                true,
                false,
                'with a blue swoosh under pitched roof peaks and a warm sunburst',
                'real_estate',
                'white',
                'recraft',
                'vector',
                null,
                'none',
                'medium',
                'modern_sans',
            ],
        );

        $this->assertStringContainsString('swoosh', strtolower($prompt));
        $this->assertStringContainsString('pitched roof peaks', $prompt);
        $this->assertStringContainsString('sleek angular roofline', $prompt);
        $this->assertStringContainsString('warm orange sunburst', $prompt);
        $this->assertStringContainsString('smooth blue baseline stroke', $prompt);
    }

    public function test_default_nature_luna_raster_prompt_for_trees_and_sun_is_not_generic_eco_symbol(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'buildLogoPromptText');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs(
            $controller,
            [
                null,
                'default',
                'logo',
                '1:1',
                true,
                false,
                'trees and the sun rising in the background',
                'nature',
                'white',
                'flux',
                'raster',
                null,
                'none',
                'medium',
                'modern_sans',
            ],
        );

        $this->assertStringContainsString('Nature logo icon', $prompt);
        $this->assertStringContainsString('actual trees or forest silhouettes', $prompt);
        $this->assertStringContainsString('visible rising sun', $prompt);
        $this->assertStringContainsString('Avoid light bulbs', $prompt);
        $this->assertStringContainsString('water droplets', $prompt);
        $this->assertStringContainsString('ICON ONLY.', $prompt);
        $this->assertStringNotContainsString('A unique abstract symbol', $prompt);
        $this->assertStringNotContainsString('A premium corporate icon mark', $prompt);
    }

    public function test_default_nature_prompt_preserves_requested_seasons(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'buildLogoPromptText');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs(
            $controller,
            [
                null,
                'default',
                'logo',
                '1:1',
                true,
                false,
                'winter spring and autumn forest trees',
                'nature',
                'white',
                'flux',
                'raster',
                null,
                'none',
                'medium',
                'modern_sans',
            ],
        );

        $this->assertStringContainsString('Season requirement: visibly represent each requested season', $prompt);
        $this->assertStringContainsString('winter cues', $prompt);
        $this->assertStringContainsString('spring cues', $prompt);
        $this->assertStringContainsString('autumn cues', $prompt);
        $this->assertStringContainsString('Preserve any requested season', $prompt);
        $this->assertStringContainsString('Do not collapse the design into a generic summer landscape', $prompt);
        $this->assertStringContainsString('summer uses lush green foliage and bright warm sun only when summer is requested', $prompt);
    }

    public function test_recraft_v4_raster_uses_supported_landscape_and_portrait_sizes(): void
    {
        $controller = app(DomainSearchController::class);
        $controllerMethod = new ReflectionMethod($controller, 'recraftRequestSize');
        $controllerMethod->setAccessible(true);

        $job = new GenerateLogoJob(1, 1, 1, []);
        $jobMethod = new ReflectionMethod($job, 'recraftRequestSize');
        $jobMethod->setAccessible(true);

        foreach ([$controllerMethod, $jobMethod] as $method) {
            $target = $method === $controllerMethod ? $controller : $job;

            $this->assertSame('1344x768', $method->invoke($target, 'raster', false, '16:9'));
            $this->assertSame('768x1344', $method->invoke($target, 'raster', false, '9:16'));
            $this->assertSame('1024x1024', $method->invoke($target, 'raster', false, '1:1'));
            $this->assertSame('2688x1536', $method->invoke($target, 'raster', true, '16:9'));
            $this->assertSame('1536x2688', $method->invoke($target, 'raster', true, '9:16'));
            $this->assertSame('2048x2048', $method->invoke($target, 'raster', true, '1:1'));
            $this->assertSame('1:1', $method->invoke($target, 'vector', false, '16:9'));
        }
    }

    public function test_logo_generator_settings_sanitizer_preserves_prompt_theme_and_sidebar_options(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'sanitizeLogoGeneratorSettings');
        $method->setAccessible(true);

        $settings = $method->invoke($controller, [
            'selected_model' => 'recraft',
            'logo_count' => 4,
            'logo_domain' => 'Horizon Homes',
            'logo_prompt' => 'winter spring and autumn forest trees',
            'logo_style' => 'modern_sans',
            'logo_theme' => 'nature',
            'logo_color_palette' => 'custom',
            'logo_custom_colors' => ['#112233', '#aabbcc', '#D4AF37'],
            'background_color' => 'white',
            'background_custom_color' => '#4f46e5',
            'logo_mode' => 'text_only',
            'pro_mode' => false,
            'pro_size' => 1536,
            'detail_level' => 'max',
            'shape_container' => 'hexagon',
            'work_mode' => 'image',
            'output_format' => 'raster',
            'image_format' => 'bmp',
            'gen_mode' => 'image',
            'image_size' => '16:9',
        ]);

        $this->assertSame('recraft', $settings['selected_model']);
        $this->assertSame(4, $settings['logo_count']);
        $this->assertSame('Horizon Homes', $settings['logo_domain']);
        $this->assertSame('winter spring and autumn forest trees', $settings['logo_prompt']);
        $this->assertSame('modern_sans', $settings['logo_style']);
        $this->assertSame('nature', $settings['logo_theme']);
        $this->assertSame('custom', $settings['logo_color_palette']);
        $this->assertSame(['#112233', '#AABBCC', '#D4AF37'], $settings['logo_custom_colors']);
        $this->assertSame('white', $settings['background_color']);
        $this->assertSame('#4F46E5', $settings['background_custom_color']);
        $this->assertSame('text_only', $settings['logo_mode']);
        $this->assertFalse($settings['pro_mode']);
        $this->assertSame(1536, $settings['pro_size']);
        $this->assertSame('max', $settings['detail_level']);
        $this->assertSame('hexagon', $settings['shape_container']);
        $this->assertSame('image', $settings['work_mode']);
        $this->assertSame('raster', $settings['output_format']);
        $this->assertSame('bmp', $settings['image_format']);
        $this->assertSame('image', $settings['gen_mode']);
        $this->assertSame('16:9', $settings['image_size']);
    }
}
