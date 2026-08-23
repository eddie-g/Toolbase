<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Backs the "Automated Tests" admin page.
 *
 * Each suite pairs a catalogue of manual QA cases (mirrored from the Asana
 * task that specified them) with a Playwright runner that automates a subset
 * of those cases against a throwaway document.
 */
class AutomatedTestController extends Controller
{
    /** Suites keyed by the slug used in the route. */
    private const SUITES = [
        'signature-tool' => [
            'catalogue' => 'automated-tests/signature-tool.json',
            'runner' => 'tests/AutomatedTests/Signature/run_signature_tests.cjs',
            'artifacts' => 'tests/AutomatedTests/Signature/artifacts',
        ],
    ];

    /** Playwright drives a real browser per test — allow generous headroom. */
    private const RUN_TIMEOUT_SECONDS = 1500;

    /**
     * Return the suite catalogue: the Asana task it came from plus every
     * specified test, flagged with whether it is automated yet.
     */
    public function suite(string $suite): JsonResponse
    {
        $config = $this->suiteConfig($suite);
        if ($config === null) {
            return response()->json(['success' => false, 'message' => 'Unknown suite'], 404);
        }

        $path = resource_path($config['catalogue']);
        if (! is_file($path)) {
            return response()->json(['success' => false, 'message' => 'Suite catalogue missing'], 500);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['suite'])) {
            return response()->json(['success' => false, 'message' => 'Suite catalogue is not valid JSON'], 500);
        }

        return response()->json(['success' => true, ...$decoded]);
    }

    /**
     * Execute the automated tests for a suite and return their results.
     *
     * Accepts an optional `tests` array to run a subset; defaults to every
     * test the catalogue marks as automated.
     */
    public function run(Request $request, string $suite): JsonResponse
    {
        $config = $this->suiteConfig($suite);
        if ($config === null) {
            return response()->json(['success' => false, 'message' => 'Unknown suite'], 404);
        }

        $validated = $request->validate([
            'tests' => 'sometimes|array',
            'tests.*' => 'string|max:100',
        ]);

        $runner = base_path($config['runner']);
        if (! is_file($runner)) {
            return response()->json(['success' => false, 'message' => 'Test runner script not found'], 500);
        }

        $requested = array_values(array_filter($validated['tests'] ?? []));
        $automated = $this->automatedTestIds($config);

        // Never let a client-supplied id reach the runner unchecked.
        $ids = $requested === []
            ? $automated
            : array_values(array_intersect($requested, $automated));

        if ($ids === []) {
            return response()->json([
                'success' => false,
                'message' => 'No automated tests selected',
            ], 422);
        }

        $process = new Process(
            ['node', $runner, '--run', implode(',', $ids)],
            base_path(),
            null,
            null,
            self::RUN_TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return response()->json([
                'success' => false,
                'message' => 'Test run timed out after '.self::RUN_TIMEOUT_SECONDS.'s',
            ], 504);
        }

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return response()->json([
                'success' => false,
                'message' => 'Test runner produced no output',
                'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 2000),
            ], 500);
        }

        // The runner prints progress-free JSON on the last line; be tolerant of
        // anything a dependency may have written before it.
        $lines = preg_split('/\R/', $stdout) ?: [];
        $payload = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $candidate = json_decode($lines[$i], true);
            if (is_array($candidate)) {
                $payload = $candidate;
                break;
            }
        }

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Could not parse test runner output',
                'raw' => mb_substr($stdout, 0, 2000),
            ], 500);
        }

        $payload['ran_at'] = now()->toIso8601String();

        return response()->json($payload);
    }

    /** Serve a screenshot produced by a test run. */
    public function artifact(string $suite, string $filename)
    {
        $config = $this->suiteConfig($suite);
        if ($config === null) {
            abort(404, 'Unknown suite');
        }

        // basename() strips any traversal attempt before it reaches the path.
        $safeName = basename($filename);
        if (! preg_match('/^[A-Za-z0-9._-]+\.png$/', $safeName)) {
            abort(404, 'Artifact not found');
        }

        $path = base_path($config['artifacts'].'/'.$safeName);
        if (! is_file($path)) {
            abort(404, 'Artifact not found');
        }

        return response()->file($path, ['Cache-Control' => 'no-cache, must-revalidate']);
    }

    /** @return array{catalogue:string,runner:string,artifacts:string}|null */
    private function suiteConfig(string $suite): ?array
    {
        return self::SUITES[$suite] ?? null;
    }

    /**
     * Ids the catalogue marks as automated — the allowlist for a run request.
     *
     * @param  array{catalogue:string}  $config
     * @return array<int, string>
     */
    private function automatedTestIds(array $config): array
    {
        $path = resource_path($config['catalogue']);
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $tests = $decoded['suite']['tests'] ?? [];

        return collect(is_array($tests) ? $tests : [])
            ->filter(fn ($test) => ($test['automated'] ?? false) === true)
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();
    }
}
