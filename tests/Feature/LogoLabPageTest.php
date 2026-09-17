<?php

namespace Tests\Feature;

use App\Models\AiLogoRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Logo Lab page: the generator and the showcase as two tabs of one
 * contained page, and the old showcase address still leading there.
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

    public function test_a_signed_in_user_gets_the_generator_inside_the_container(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/logo-generator');

        $response->assertOk()
            ->assertSee('x-data="logoGenerator()"', false)
            ->assertSee('data-action="generate"', false)
            ->assertSee('Luna')
            ->assertSee('Ray')
            ->assertSee('max-w-6xl', false)
            ->assertDontSee('data-login-gate', false);
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
}
