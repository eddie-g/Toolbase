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
            ->assertJsonPath('suite.stories.0.task_gid', '1217747812355475')
            ->assertJsonPath('suite.stories.1.task_gid', '1217754901732372');

        $tests = $response->json('suite.tests');

        $this->assertCount(36, $tests, 'Both QA stories\' subtasks should be catalogued');

        $automated = array_values(array_filter($tests, fn (array $test) => $test['automated'] === true));
        $this->assertCount(
            36,
            $automated,
            'Every catalogued subtask is automated; the runner must implement all of them',
        );

        $this->assertCount(
            36,
            array_unique(array_column($tests, 'id')),
            'Each subtask maps to its own runner test',
        );

        // Both stories must actually contribute cases.
        $byStory = array_count_values(array_column($tests, 'story'));
        $this->assertSame(23, $byStory['signature-tool'] ?? 0);
        $this->assertSame(13, $byStory['nk-dev-5'] ?? 0);

        foreach ($tests as $test) {
            $this->assertNotEmpty($test['gid'], 'Each test links back to its Asana subtask');
            $this->assertNotEmpty($test['title']);
            $this->assertNotEmpty($test['area']);
            $this->assertNotEmpty($test['story'], 'Each test records which QA story specified it');
        }
    }

    public function test_the_retired_nk_dev_5_suite_is_gone(): void
    {
        // Its cases were merged into the signature-tool suite.
        $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/signature-modal-improvements/suite')
            ->assertNotFound();
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

        $this->assertSame(36, $response->json('summary.tests_total'));
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

    public function test_the_page_offers_a_tab_per_suite(): void
    {
        $suites = (new \App\Filament\Pages\AutomatedTests)->getSuites();
        $keys = array_column($suites, 'key');

        $this->assertContains('signature-tool', $keys);
        $this->assertContains('text-tool', $keys, 'The text tool needs its own tab');

        // The switcher only renders with more than one suite, so the labels
        // have to reach the page for the tabs to be usable.
        $response = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/automated-tests')
            ->assertOk();

        foreach ($suites as $suite) {
            $response->assertSee($suite['label'], false);
        }
    }

    // ---- Text tool suite --------------------------------------------------

    public function test_text_tool_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/text-tool/suite')->assertUnauthorized();
    }

    public function test_text_tool_suite_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/text-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.key', 'text-tool')
            ->assertJsonPath('suite.stories.0.task_gid', '1217747812355479');

        $tests = $response->json('suite.tests');

        $this->assertCount(32, $tests, 'Every QA subtask should be catalogued');
        $this->assertCount(
            32,
            array_unique(array_column($tests, 'id')),
            'Each subtask maps to its own runner test',
        );
        $this->assertCount(
            32,
            array_unique(array_column($tests, 'gid')),
            'Each test links back to a distinct Asana subtask',
        );

        foreach ($tests as $test) {
            $this->assertNotEmpty($test['gid'], 'Each test links back to its Asana subtask');
            $this->assertNotEmpty($test['title']);
            $this->assertNotEmpty($test['summary']);
            $this->assertNotEmpty($test['area']);
            $this->assertSame('text-tool', $test['story']);
        }
    }

    public function test_text_tool_catalogue_marks_the_implemented_cases_as_automated(): void
    {
        $tests = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/text-tool/suite')
            ->assertOk()
            ->json('suite.tests');

        $automated = array_values(array_filter($tests, fn (array $test) => $test['automated'] === true));

        // Implementation is deliberately partial; this pins which cases claim
        // to be covered, so a catalogue edit cannot quietly overstate it.
        $this->assertSame(
            [
                '01-blank-project-loads',
                '02-add-text-mode-toggle',
                '03-click-to-place-defaults',
                '04-drag-to-size',
                '05-page-clamping',
                '06-zoom-accuracy',
                '07-rotated-pages',
                '08-edit-mode-ready',
                '09-typing-and-wrap',
                '10-commit-and-empty',
                '11-panel-mirrors-selection',
                '12-font-family',
            ],
            array_column($automated, 'id'),
        );
    }

    public function test_every_automated_text_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/text-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Text/run_text_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }
    }

    public function test_text_tool_run_rejects_ids_outside_the_catalogue(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/text-tool/run', ['tests' => ['99-not-a-real-test']])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_text_tool_run_rejects_a_case_that_is_not_automated_yet(): void
    {
        // 13 is catalogued but not implemented; asking for it must not reach
        // the runner.
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/text-tool/run', ['tests' => ['13-font-size-slider']])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_text_tool_artifact_route_rejects_path_traversal(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/automated-tests/text-tool/artifacts/'.urlencode('../../../.env'))
            ->assertNotFound();
    }

    /**
     * Full round trip for the text suite. Gated like the signature one: it
     * launches a real browser against the running app.
     */
    public function test_text_tool_run_endpoint_executes_the_playwright_suite(): void
    {
        if (getenv('RUN_BROWSER_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_BROWSER_TESTS=1 to run the browser suite.');
        }

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/text-tool/run')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(12, $response->json('summary.tests_total'));

        $failing = [];
        foreach ($response->json('results') as $result) {
            foreach ($result['checks'] as $check) {
                if ($check['result'] !== 'PASS') {
                    $failing[] = $check['item'];
                }
            }
        }

        // One known product defect: box-level bold does not survive a re-render
        // of the annotation layer. Everything else must pass. When that is
        // fixed, this expectation should drop to an empty array.
        $this->assertSame(
            ['a-bold-again'],
            $failing,
            'Unexpected failing checks: '.json_encode($failing),
        );
    }
}
