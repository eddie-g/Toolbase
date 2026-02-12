<?php

namespace App\Http\Controllers;

use App\Models\OverlayEditorTest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShapeTestController extends Controller
{
    /**
     * Return the list of shape test types.
     * Calls the Playwright-based Node.js script with --list-files.
     */
    public function getTestFiles(Request $request)
    {
        $nodeScript = base_path('tests/OverlayEditor/Shapes/run_shape_tests.cjs');

        $command = sprintf('node %s --list-files 2>/dev/null', escapeshellarg($nodeScript));
        $output = shell_exec($command);

        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Could not list shape tests'], 500);
        }

        $data = json_decode($output, true);
        if (!$data || !isset($data['files'])) {
            return response()->json(['success' => false, 'message' => 'Failed to parse shape test list'], 500);
        }

        $runId = Str::uuid()->toString();

        return response()->json([
            'success' => true,
            'run_id' => $runId,
            'total' => $data['total'],
            'files' => $data['files'],
        ]);
    }

    /**
     * Run a single shape test via Playwright browser automation and return + save results.
     * The Node.js script opens the actual editor, draws the shape via UI, saves,
     * then validates the saved PDF with PyMuPDF.
     */
    public function runSingleTest(Request $request)
    {
        $request->validate([
            'shape_type' => 'required|string',
            'run_id' => 'required|string',
        ]);

        $shapeType = $request->input('shape_type');
        $runId = $request->input('run_id');
        $nodeScript = base_path('tests/OverlayEditor/Shapes/run_shape_tests.cjs');

        // Run the Playwright test with a generous timeout (browser tests are slower)
        $command = sprintf(
            'timeout 120 node %s --single-shape %s 2>&1',
            escapeshellarg($nodeScript),
            escapeshellarg($shapeType)
        );

        $output = shell_exec($command);
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Shape test script produced no output'], 500);
        }

        $result = json_decode($output, true);
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse shape test result',
                'raw' => substr($output, 0, 2000),
            ], 500);
        }

        // Save to database
        $report = OverlayEditorTest::create([
            'run_id' => $runId,
            'test_type' => 'shapes',
            'filename' => $result['filename'] ?? "{$shapeType}_test.pdf",
            'description' => $result['description'] ?? '',
            'test_category' => $result['test_category'] ?? 'Shapes',
            'section_name' => $result['section_name'] ?? ucfirst($shapeType),
            'status' => $result['status'] ?? 'error',
            'checks' => $result['checks'] ?? [],
            'checks_passed' => $result['checks_passed'] ?? 0,
            'checks_total' => $result['checks_total'] ?? 0,
            'page_count' => $result['page_count'] ?? 0,
            'file_size' => $result['file_size'] ?? 0,
            'error' => $result['error'] ?? null,
            'warnings' => $result['warnings'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'result' => array_merge($result, ['id' => $report->id]),
        ]);
    }

    /**
     * Run ALL shape tests in a single Playwright session (much faster).
     * Uses --run-all which shares one browser instance across all 8 shapes.
     */
    public function runAllTests(Request $request)
    {
        $request->validate([
            'run_id' => 'required|string',
        ]);

        $runId = $request->input('run_id');
        $nodeScript = base_path('tests/OverlayEditor/Shapes/run_shape_tests.cjs');

        // Generous timeout: all 8 shapes in one browser (~60-90s expected)
        $command = sprintf(
            'timeout 300 node %s --run-all 2>&1',
            escapeshellarg($nodeScript)
        );

        $output = shell_exec($command);
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Shape test script produced no output'], 500);
        }

        $results = json_decode($output, true);
        if (!$results || !is_array($results)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse batch shape test results',
                'raw' => substr($output, 0, 2000),
            ], 500);
        }

        // Save each result to database
        $savedResults = [];
        foreach ($results as $result) {
            $report = OverlayEditorTest::create([
                'run_id' => $runId,
                'test_type' => 'shapes',
                'filename' => $result['filename'] ?? 'unknown.pdf',
                'description' => $result['description'] ?? '',
                'test_category' => $result['test_category'] ?? 'Shapes',
                'section_name' => $result['section_name'] ?? 'Unknown',
                'status' => $result['status'] ?? 'error',
                'checks' => $result['checks'] ?? [],
                'checks_passed' => $result['checks_passed'] ?? 0,
                'checks_total' => $result['checks_total'] ?? 0,
                'page_count' => $result['page_count'] ?? 0,
                'file_size' => $result['file_size'] ?? 0,
                'error' => $result['error'] ?? null,
                'warnings' => $result['warnings'] ?? [],
            ]);

            $savedResults[] = array_merge($result, ['id' => $report->id]);
        }

        return response()->json([
            'success' => true,
            'results' => $savedResults,
        ]);
    }
}
