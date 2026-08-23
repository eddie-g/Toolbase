<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke coverage for the "Automated Tests" admin page and its suite API.
 *
 * These deliberately do not execute Playwright — the browser run is exercised
 * by tests/AutomatedTests/Signature/run_signature_tests.cjs itself.
 */
class AutomatedTestsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Automated Tests Admin',
            'email' => 'automated-tests@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/signature-tool/suite')->assertUnauthorized();
    }

    public function test_suite_endpoint_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/signature-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.asana.task_gid', '1217747812355475');

        $tests = $response->json('suite.tests');

        $this->assertCount(23, $tests, 'Every Asana subtask should be catalogued');

        $automated = array_values(array_filter($tests, fn (array $test) => $test['automated'] === true));
        $this->assertCount(
            23,
            $automated,
            'Every Asana subtask is now automated; the runner must implement all of them',
        );

        foreach ($tests as $test) {
            $this->assertNotEmpty($test['gid'], 'Each test links back to its Asana subtask');
            $this->assertNotEmpty($test['title']);
            $this->assertNotEmpty($test['area']);
        }
    }

    public function test_unknown_suite_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/nope/suite')
            ->assertNotFound();
    }

    public function test_run_rejects_ids_outside_the_catalogue(): void
    {
        // Only ids the catalogue marks as automated may reach the Playwright
        // process, so a client-supplied id is never trusted.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/signature-tool/run', ['tests' => ['99-not-a-real-test']])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_artifact_route_rejects_path_traversal(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/automated-tests/signature-tool/artifacts/'.urlencode('../../../.env'))
            ->assertNotFound();
    }

    /**
     * Full round trip: the run endpoint actually drives Playwright.
     *
     * Gated because it launches a real browser against the running app and
     * takes ~25s. Enable with RUN_BROWSER_TESTS=1.
     */
    public function test_run_endpoint_executes_the_playwright_suite(): void
    {
        if (getenv('RUN_BROWSER_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_BROWSER_TESTS=1 to run the browser suite.');
        }

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/signature-tool/run')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(23, $response->json('summary.tests_total'));
        $this->assertSame(
            $response->json('summary.checks_total'),
            $response->json('summary.checks_passed'),
            'Every automated check should pass: '.json_encode($response->json('results')),
        );
    }

    public function test_admin_page_renders(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/automated-tests')
            ->assertOk()
            ->assertSee('Automated Tests')
            ->assertSee('Run tests', false)
            ->assertSee('Test plan', false);
    }
}
