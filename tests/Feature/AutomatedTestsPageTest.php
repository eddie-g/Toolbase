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

        $this->assertCount(38, $tests, 'Both QA stories\' subtasks should be catalogued');

        $automated = array_values(array_filter($tests, fn (array $test) => $test['automated'] === true));
        $this->assertCount(
            38,
            $automated,
            'Every catalogued subtask is automated; the runner must implement all of them',
        );

        $this->assertCount(
            38,
            array_unique(array_column($tests, 'id')),
            'Each subtask maps to its own runner test',
        );

        // Both stories must actually contribute cases.
        $byStory = array_count_values(array_column($tests, 'story'));
        $this->assertSame(24, $byStory['signature-tool'] ?? 0);
        $this->assertSame(13, $byStory['nk-dev-5'] ?? 0);
        $this->assertSame(1, $byStory['nk-dev-6'] ?? 0);

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

        $this->assertSame(37, $response->json('summary.tests_total'));
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

    public function test_an_expanded_test_offers_a_run_only_this_test_control(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get('/admin/automated-tests')
            ->assertOk()
            ->assertSee('Run only this test', false);

        $html = $response->getContent();

        // Assert the CLICK BINDING, not just that a runOnly() method exists — the
        // method definition alone matches 'runOnly(test)', so a button rewired to
        // run() would still have satisfied that.
        $this->assertStringContainsString('x-on:click="runOnly(test)"', $html,
            'The button must be bound to runOnly(), or it would re-run the whole suite');
        $this->assertStringContainsString('tests: [test.id]', $html,
            'A single run must post just this test id');

        // It must be blocked while any run is in flight, so two runs cannot overlap.
        $this->assertStringContainsString(':disabled="busy"', $html,
            'The button must be disabled while a run is already in progress');

        // Only automated cases can be run; a manual one has nothing to execute.
        $this->assertStringContainsString('x-if="test.automated"', $html,
            'The button must only be offered for automated cases');
    }

    public function test_running_one_test_does_not_clear_the_other_results(): void
    {
        $html = (string) $this->actingAs($this->admin(), 'admin')
            ->get('/admin/automated-tests')
            ->assertOk()
            ->getContent();

        // run() deliberately resets everything; runOnly() must not, or re-running
        // one failing case would wipe the results being compared against it.
        $this->assertMatchesRegularExpression(
            '/async runOnly\(test\)\s*\{(?:(?!async run\(\)).)*?this\.summary = null;/s',
            $html,
            'runOnly() should clear the run summary, whose totals no longer describe the screen',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/async runOnly\(test\)\s*\{(?:(?!async run\(\)).)*?this\.results = \{\};/s',
            $html,
            'runOnly() must NOT clear this.results — that is what separates it from a full run',
        );
    }

    public function test_the_page_offers_a_tab_per_suite(): void
    {
        $suites = (new \App\Filament\Pages\AutomatedTests)->getSuites();
        $keys = array_column($suites, 'key');

        $this->assertContains('signature-tool', $keys);
        $this->assertContains('text-tool', $keys, 'The text tool needs its own tab');
        $this->assertContains('shapes-tool', $keys, 'The shapes tool needs its own tab');
        $this->assertContains('draw-tool', $keys, 'The draw tool needs its own tab');
        $this->assertContains('highlight-tool', $keys, 'The highlight tool needs its own tab');
        $this->assertContains('image-tool', $keys, 'The image tool needs its own tab');

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

        $this->assertCount(33, $tests, 'Every QA subtask should be catalogued');
        $this->assertCount(
            33,
            array_unique(array_column($tests, 'id')),
            'Each subtask maps to its own runner test',
        );
        $this->assertCount(
            33,
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
                '13-font-size-slider',
                '14-colours',
                '15-opacity',
                '16-bold-italic-underline',
                '17-alignment',
                '18-case-transforms',
                '19-inline-formatting',
                '20-hyperlink',
                '21-select-and-menu',
                '22-move-and-resize',
                '23-multi-select',
                '24-order-lock-clipboard',
                '25-undo-redo',
                '26-autosave-and-save',
                '27-reload-fidelity',
                '28-download-and-burn',
                '29-layers-and-debug',
                '30-source-text-editing',
                '31-content-robustness',
                '32-keyboard-and-aria',
                '33-rotate-text-box',
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

    public function test_shapes_tool_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/shapes-tool/suite')->assertUnauthorized();
    }

    public function test_shapes_tool_suite_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/shapes-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.key', 'shapes-tool');

        $tests = $response->json('suite.tests');
        $this->assertCount(32, $tests, 'The shapes story specifies 32 cases');

        foreach ($tests as $test) {
            $this->assertSame('shapes-tool', $test['story']);
            $this->assertNotEmpty($test['gid'], "Case {$test['id']} must carry its Asana subtask gid");
        }

        // Each case maps onto its own Asana subtask; a duplicate gid would
        // silently report one subtask's result against another.
        $gids = array_column($tests, 'gid');
        $this->assertSame(array_unique($gids), $gids, 'Two shapes cases share an Asana gid');
    }

    public function test_every_automated_shapes_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/shapes-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $this->assertNotEmpty($automated, 'At least one shapes case should be automated by now');

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Shapes/run_shapes_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }

        // Numbers are how the admin page labels rows, so a duplicate makes two
        // different cases indistinguishable there.
        $numbers = array_column($catalogue['suite']['tests'], 'number');
        $this->assertSame(array_unique($numbers), $numbers, 'Two shapes cases share a number');
    }

    // ---- Draw tool suite --------------------------------------------------

    public function test_draw_tool_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/draw-tool/suite')->assertUnauthorized();
    }

    public function test_draw_tool_suite_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/draw-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.key', 'draw-tool');

        $tests = $response->json('suite.tests');
        $this->assertCount(26, $tests, 'The draw story specifies 26 cases');

        foreach ($tests as $test) {
            $this->assertSame('draw-tool', $test['story']);
        }

        // Unlike the other suites, the draw cases were specified here rather
        // than mirrored from an Asana task, so they carry no subtask gids yet.
        // Asserted deliberately: once "[QA] - Draw tool" exists and the gids
        // are filled in, this expectation should be tightened to match the
        // shapes one rather than quietly left as it is.
        $gids = array_filter(array_column($tests, 'gid'));
        $this->assertEmpty(
            $gids,
            'Draw cases have started carrying Asana gids -- tighten this test to assert they are unique',
        );
    }

    public function test_every_automated_draw_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/draw-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $this->assertCount(26, $automated, 'Every specified draw case is automated');

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Draw/run_draw_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }

        // The reverse direction too: a case registered in the runner but left
        // out of the catalogue can never be reached from the admin page.
        // Anchored on the number that follows, so this matches the registry
        // entries rather than every object literal that happens to have an id.
        preg_match_all("/\{ id: '([^']+)', number: '/", $runner, $matches);
        $catalogued = array_column($catalogue['suite']['tests'], 'id');
        foreach ($matches[1] as $registered) {
            $this->assertContains(
                $registered,
                $catalogued,
                "The runner registers {$registered}, which the catalogue does not list",
            );
        }

        $numbers = array_column($catalogue['suite']['tests'], 'number');
        $this->assertSame(array_unique($numbers), $numbers, 'Two draw cases share a number');
    }

    // ---- Highlight tool suite ---------------------------------------------

    public function test_highlight_tool_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/highlight-tool/suite')->assertUnauthorized();
    }

    public function test_highlight_tool_suite_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/highlight-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.key', 'highlight-tool');

        $tests = $response->json('suite.tests');
        $this->assertCount(23, $tests, 'The highlight story specifies 23 cases');

        foreach ($tests as $test) {
            $this->assertSame('highlight-tool', $test['story']);
        }

        // Specified here rather than mirrored from an Asana task, so no subtask
        // gids yet. Same expectation as the draw suite: once the task exists and
        // the gids are filled in, tighten this to assert they are unique.
        $gids = array_filter(array_column($tests, 'gid'));
        $this->assertEmpty(
            $gids,
            'Highlight cases have started carrying Asana gids -- tighten this test to assert they are unique',
        );
    }

    public function test_every_automated_highlight_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/highlight-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $this->assertCount(23, $automated, 'Every specified highlight case is automated');

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Highlight/run_highlight_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }

        // Anchored on the number that follows, so this matches the registry
        // entries rather than every object literal that happens to have an id.
        preg_match_all("/\{ id: '([^']+)', number: '/", $runner, $matches);
        $catalogued = array_column($catalogue['suite']['tests'], 'id');
        foreach ($matches[1] as $registered) {
            $this->assertContains(
                $registered,
                $catalogued,
                "The runner registers {$registered}, which the catalogue does not list",
            );
        }

        $numbers = array_column($catalogue['suite']['tests'], 'number');
        $this->assertSame(array_unique($numbers), $numbers, 'Two highlight cases share a number');
    }

    // ---- Image tool suite -------------------------------------------------

    public function test_image_tool_suite_endpoint_requires_admin_authentication(): void
    {
        $this->getJson('/automated-tests/image-tool/suite')->assertUnauthorized();
    }

    public function test_image_tool_suite_returns_the_catalogue(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->getJson('/automated-tests/image-tool/suite')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suite.key', 'image-tool')
            ->assertJsonPath('suite.stories.0.task_gid', '1218072985049651');

        $tests = $response->json('suite.tests');
        $this->assertCount(24, $tests, 'The image story specifies 24 cases');

        foreach ($tests as $test) {
            $this->assertSame('image-tool', $test['story']);
        }

        // Unlike the draw and highlight catalogues, this one was mirrored from
        // a real Asana task, so every case carries its subtask gid and no two
        // cases may point at the same subtask.
        $gids = array_column($tests, 'gid');
        $this->assertCount(24, array_filter($gids), 'Every image case carries its Asana subtask gid');
        $this->assertSame(array_unique($gids), $gids, 'Two image cases point at the same Asana subtask');
    }

    public function test_every_automated_image_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/image-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $this->assertCount(24, $automated, 'Every specified image case is automated');

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Image/run_image_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }

        // Anchored on the number that follows, so this matches the registry
        // entries rather than every object literal that happens to have an id.
        preg_match_all("/\{ id: '([^']+)', number: '/", $runner, $matches);
        $catalogued = array_column($catalogue['suite']['tests'], 'id');
        foreach ($matches[1] as $registered) {
            $this->assertContains(
                $registered,
                $catalogued,
                "The runner registers {$registered}, which the catalogue does not list",
            );
        }

        $numbers = array_column($catalogue['suite']['tests'], 'number');
        $this->assertSame(array_unique($numbers), $numbers, 'Two image cases share a number');
    }

    public function test_every_automated_signature_case_exists_in_the_runner(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/signature-tool.json')),
            true,
        );
        $automated = array_values(array_filter(
            $catalogue['suite']['tests'],
            fn (array $test) => $test['automated'] === true,
        ));

        $runner = (string) file_get_contents(base_path('tests/AutomatedTests/Signature/run_signature_tests.cjs'));

        foreach ($automated as $test) {
            $this->assertStringContainsString(
                "id: '".$test['id']."'",
                $runner,
                "The runner must register {$test['id']}, or the admin page offers a test that cannot run",
            );
        }

        // Numbers are how the admin page labels rows, so a duplicate makes two
        // different cases indistinguishable there.
        $numbers = array_column($catalogue['suite']['tests'], 'number');
        $this->assertSame(
            array_unique($numbers),
            $numbers,
            'Two signature cases share a number',
        );
    }

    public function test_text_tool_run_rejects_ids_outside_the_catalogue(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/automated-tests/text-tool/run', ['tests' => ['99-not-a-real-test']])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_text_tool_allowlist_matches_the_catalogue(): void
    {
        // Every Text tool case is automated now, so there is no half-automated id
        // left to reject. What still matters is that the allowlist is built from
        // the catalogue rather than trusting the client: an unknown id is refused
        // (covered above), and every catalogued id is accepted.
        $catalogue = json_decode(
            (string) file_get_contents(resource_path('automated-tests/text-tool.json')),
            true,
        );
        $ids = array_column($catalogue['suite']['tests'], 'id');

        $this->assertNotEmpty($ids);
        $this->assertSame(
            $ids,
            array_column(
                array_values(array_filter(
                    $catalogue['suite']['tests'],
                    fn (array $test) => $test['automated'] === true,
                )),
                'id',
            ),
            'Every catalogued case should be automated',
        );
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

        $this->assertSame(33, $response->json('summary.tests_total'));

        $failing = [];
        foreach ($response->json('results') as $result) {
            foreach ($result['checks'] as $check) {
                if ($check['result'] !== 'PASS') {
                    $failing[] = $check['item'];
                }
            }
        }

        $this->assertSame(
            [],
            $failing,
            'Unexpected failing checks: '.json_encode($failing),
        );
    }
}
