<?php

namespace Tests\Feature;

use App\Http\Controllers\DomainSearchController;
use App\Jobs\GenerateLogoJob;
use App\Services\RecraftPromptBuilder;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class LogoPromptThemeTest extends TestCase
{
    public function test_each_generated_logo_gets_a_unique_local_id_and_keeps_its_provider_id(): void
    {
        $job = new GenerateLogoJob(1, 2, 3, []);
        $method = new ReflectionMethod($job, 'identifyGeneratedImages');
        $method->setAccessible(true);

        $images = $method->invoke($job, [
            ['url' => 'https://example.test/one.svg', 'image_id' => 'recraft-one'],
            ['url' => 'https://example.test/two.svg', 'image_id' => 'recraft-two'],
        ], null);

        $this->assertNotSame($images[0]['generation_id'], $images[1]['generation_id']);
        $this->assertTrue(Str::isUuid($images[0]['generation_id']));
        $this->assertTrue(Str::isUuid($images[1]['generation_id']));
        $this->assertSame('recraft-one', $images[0]['provider_image_id']);
        $this->assertSame('recraft-two', $images[1]['provider_image_id']);
    }

    public function test_public_logo_payload_exposes_generation_and_provider_ids(): void
    {
        $controller = app(DomainSearchController::class);
        $method = new ReflectionMethod($controller, 'publicLogoResultPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, [
            'images' => [[
                'stored_url' => '/storage/logos/example.svg',
                'generation_id' => '329edaa9-05d7-4f23-ad44-aa999a409cdf',
                'provider_image_id' => 'recraft-image-id',
            ]],
        ]);

        $this->assertSame('329edaa9-05d7-4f23-ad44-aa999a409cdf', $payload['images'][0]['generation_id']);
        $this->assertSame('recraft-image-id', $payload['images'][0]['provider_image_id']);
    }

    public function test_recraft_minimal_geometric_vector_prompt_requests_an_airy_lightweight_mark(): void
    {
        $prompt = RecraftPromptBuilder::build(
            style: 'minimal_geometric',
            logoDetail: 'max',
            logoShape: 'none',
            iconOnly: true,
            textOnly: false,
            subject: 'Several flowing lines that merge into an N.',
            brandUpper: '',
            colorDesc: '#1E3A5F, #000000, #E2621D',
            bgDesc: '#FFFFFF',
            outputFormat: 'vector',
            fontStyle: 'modern_sans',
        );

        $this->assertLessThanOrEqual(1000, mb_strlen($prompt));
        $this->assertStringContainsString('a lightweight, airy mark with generous negative space', $prompt);
        $this->assertStringContainsString('stay separate and slender while converging', $prompt);
        $this->assertStringContainsString('never fuse them into a thick monogram or heavy block', $prompt);
        $this->assertStringContainsString('consistent thin-to-medium widths', $prompt);
        $this->assertStringContainsString('Avoid heavy slabs, bulbous shapes, oversized fills', $prompt);
        $this->assertStringContainsString('never enlarge thin parts to fill the frame', $prompt);
        $this->assertStringContainsString('Colors: #1E3A5F, #000000, #E2621D.', $prompt);
        $this->assertStringContainsString('Background: #FFFFFF.', $prompt);
        $this->assertStringContainsString('ICON ONLY.', $prompt);
        $this->assertStringNotContainsString('one solid, continuous', $prompt);
        $this->assertStringNotContainsString('strong clear outline', $prompt);
    }

    public function test_logo_generator_renders_the_technology_theme_card(): void
    {
        $html = view('logo-generator-2', [
            'logoUser' => (object) ['credit_balance' => 0],
            'logoGeneratorSettings' => [],
        ])->render();

        $this->assertStringContainsString("selectTheme('technology')", $html);
        $this->assertStringContainsString('>Technology</div>', $html);
        $this->assertStringContainsString('Software, AI, hardware, networks, cybersecurity, and clean digital geometry.', $html);
    }

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

    public function test_technology_theme_builds_a_generic_vector_prompt_that_follows_the_requested_concept(): void
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
                'a secure cloud developer platform',
                'technology',
                'white',
                'recraft',
                'vector',
                null,
                'none',
                'max',
                'modern_sans',
            ],
        );

        $this->assertStringContainsString('Technology theme:', $prompt);
        $this->assertStringContainsString('represent the user requested technical concept literally', $prompt);
        $this->assertStringContainsString('connected nodes and data paths for software, cloud, or networks', $prompt);
        $this->assertStringContainsString('shields and locks for cybersecurity', $prompt);
        $this->assertStringContainsString('code brackets or terminal forms for developer tools', $prompt);
        $this->assertStringContainsString('crisp, scalable, and vector-friendly', $prompt);
        $this->assertStringContainsString('Do not default every design to the same chip', $prompt);
        $this->assertStringContainsString('Icon only.', $prompt);
        $this->assertStringContainsString('Do not render words, letters, initials, numbers, captions, or text.', $prompt);
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
            'logo_theme' => 'technology',
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
        $this->assertSame('technology', $settings['logo_theme']);
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
