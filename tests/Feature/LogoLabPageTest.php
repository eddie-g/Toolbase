<?php

namespace Tests\Feature;

use App\Models\AiLogoRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Logo Lab page: the studio button and the account's own logos on the
 * Generate tab, the showcase on the Browse logos tab, the studio itself full
 * screen at /logo-generator/studio, and the old showcase address still
 * leading to the Browse tab.
 */
class LogoLabPageTest extends TestCase
{
    use RefreshDatabase;

    private function showcaseLogo(array $overrides = []): AiLogoRequest
    {
        return AiLogoRequest::create(array_merge([
            'domain' => 'acme',
            'style' => 'professional',
            'model' => 'fal-ai/flux/schnell',
            'prompt' => 'a bold acme mark',
            'status' => 'completed',
            'image_urls' => ['https://cdn.example.test/logos/acme-0.png'],
            'is_showcase' => true,
        ], $overrides));
    }

    public function test_the_page_carries_both_tabs_and_gates_only_the_generator(): void
    {
        $this->showcaseLogo();

        $response = $this->get('/logo-generator');

        $response->assertOk()
            ->assertSee('data-tab="generate"', false)
            ->assertSee('data-tab="browse"', false)
            ->assertSee('data-login-gate', false)
            ->assertSee('Sign in to generate')
            ->assertSee('/logos/acme-0.png')
            ->assertDontSee('logoGenerator()', false);
    }

    public function test_a_signed_in_user_gets_the_studio_button_and_their_own_logos(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->showcaseLogo(['user_id' => $user->id, 'domain' => 'my-brand', 'is_showcase' => false, 'image_urls' => ['/storage/logos/a.png', '/storage/logos/b.png']]);
        $this->showcaseLogo(['user_id' => $other->id, 'domain' => 'their-brand', 'is_showcase' => false]);
        $this->showcaseLogo(['user_id' => $user->id, 'domain' => 'still-running', 'status' => 'processing', 'is_showcase' => false]);

        $response = $this->actingAs($user)->get('/logo-generator');

        $response->assertOk()
            ->assertSee('max-w-6xl', false)
            ->assertSee('data-action="open-studio"', false)
            ->assertSee(route('domainSearch.logoStudio'), false)
            ->assertSee('Your logos')
            ->assertSee('data-logos-view="grid"', false)
            ->assertSee('data-logos-view="list"', false)
            ->assertSee('my-brand')
            ->assertSee(route('generatedImages.preview', ['logoRequest' => $mine->id, 'index' => 1]), false)
            ->assertSee(route('generatedImages.original', ['logoRequest' => $mine->id, 'index' => 0]), false)
            ->assertSee('data-action="make-more"', false)
            ->assertDontSee('their-brand')
            ->assertDontSee('still-running')
            ->assertDontSee('x-data="logoGenerator()"', false)
            ->assertDontSee('Sign in to generate');

        $this->assertSame(2, substr_count($response->getContent(), 'data-library-item'), 'one card per generated image');
    }

    public function test_the_library_tells_a_new_account_to_open_the_studio(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/logo-generator')
            ->assertOk()
            ->assertSee('data-library-empty', false)
            ->assertSee('Open Logo Studio');
    }

    public function test_the_studio_is_the_whole_generator_full_screen(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/logo-generator/studio');

        $response->assertOk()
            ->assertSee('x-data="logoGenerator()"', false)
            ->assertSee('data-action="generate"', false)
            ->assertSee('data-studio-bar', false)
            ->assertSee('data-action="back-to-logos"', false)
            ->assertSee('Luna')
            ->assertSee('Ray')
            ->assertDontSee('max-w-6xl', false)
            ->assertDontSee('data-tab="browse"', false)
            ->assertDontSee('href="/pdf-editor"', false);
    }

    public function test_the_studio_needs_an_account(): void
    {
        $this->get('/logo-generator/studio')->assertRedirect(route('login'));
    }

    public function test_browse_logos_is_not_in_the_site_navigation(): void
    {
        $html = $this->get('/logo-generator')->assertOk()->getContent();

        $this->assertStringNotContainsString('href="/browse-logos"', $html);
        $this->assertStringContainsString('href="/logo-generator"', $html);
    }

    public function test_the_browse_tab_opens_from_the_query_string(): void
    {
        $response = $this->get('/logo-generator?tab=browse');

        $response->assertOk()->assertSee("tab: 'browse'", false);

        $this->get('/logo-generator?tab=anything-else')->assertOk()->assertSee("tab: 'generate'", false);
    }

    public function test_the_old_showcase_address_opens_the_browse_tab_with_its_filters(): void
    {
        $this->get('/browse-logos?style=professional&search=acme&page=2')
            ->assertRedirect('/logo-generator?tab=browse&search=acme&style=professional&page=2')
            ->assertStatus(301);

        $this->get('/browse-logos')->assertRedirect('/logo-generator?tab=browse');
    }

    public function test_the_showcase_filters_stay_on_the_browse_tab(): void
    {
        $this->showcaseLogo();
        $this->showcaseLogo(['domain' => 'zen', 'style' => 'minimal_geometric']);

        $response = $this->get('/logo-generator?tab=browse&style=professional');

        $response->assertOk()
            ->assertSee('acme')
            ->assertDontSee('>zen<', false)
            ->assertSee('name="tab" value="browse"', false);
    }

    public function test_the_full_screen_layout_is_sidelined_behind_a_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/logo-generator?layout=full')
            ->assertOk()
            ->assertSee('id="subpanel-bar"', false)
            ->assertDontSee('data-tab="browse"', false);
    }

    public function test_the_page_speaks_in_netkit_code_names_and_never_of_ai(): void
    {
        $this->showcaseLogo(['model' => 'recraft-v4-vector', 'domain' => 'ray-made']);
        $this->showcaseLogo(['model' => 'fal-ai/flux/schnell', 'domain' => 'luna-made']);
        $this->showcaseLogo(['model' => 'gpt-image-1.5', 'domain' => 'cosmo-made']);

        $user = User::factory()->create();
        foreach (['/logo-generator', '/logo-generator/studio'] as $path) {
            $response = $this->actingAs($user)->get($path);
            $response->assertOk();

            // What the page shows, not what its script says to itself.
            $visible = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $response->getContent());

            foreach (['Ray', 'Luna', 'Cosmo'] as $name) {
                $this->assertStringContainsString($name, $visible, "{$path} should name {$name}");
            }
            foreach (['Recraft', '>Flux<', 'GPT Image', 'DALL', 'AI Model', 'AI Logo Lab', 'AI picks'] as $word) {
                $this->assertStringNotContainsString($word, $visible, "{$path} must not say '{$word}'");
            }
        }
    }

    public function test_make_your_own_carries_the_exact_prompt_and_settings(): void
    {
        $this->showcaseLogo([
            'model' => 'recraft-v4-vector',
            'style' => 'fantasy_pro',
            'output_format' => 'vector',
            'original_prompt' => 'a lion with a cloak',
            'prompt' => 'ICON ONLY. Minimal geometric ... a lion with a cloak',
            'result_data' => json_encode([
                'style' => 'minimal_geometric', 'image_model' => 'recraft', 'icon_only' => true,
                'bg_color' => 'white', 'logo_shape' => 'circle', 'logo_detail' => 'max',
            ]),
        ]);

        $html = $this->get('/logo-generator?tab=browse')->assertOk()->getContent();

        $this->assertStringContainsString('data-action="make-your-own"', $html);
        $this->assertStringContainsString(route('domainSearch.logoStudio'), $html, 'Make your own opens the studio');
        $this->assertStringContainsString('&quot;prompt&quot;:&quot;a lion with a cloak&quot;', $html, 'the words the maker typed, not the composed prompt');
        $this->assertStringContainsString('&quot;generator_model&quot;:&quot;recraft&quot;', $html);
        $this->assertStringContainsString('&quot;style_id&quot;:&quot;minimal_geometric&quot;', $html);
        $this->assertStringContainsString('&quot;pro&quot;:true', $html);
        $this->assertStringContainsString('&quot;output_format&quot;:&quot;vector&quot;', $html);
        $this->assertStringContainsString('&quot;logo_shape&quot;:&quot;circle&quot;', $html);
    }
}
