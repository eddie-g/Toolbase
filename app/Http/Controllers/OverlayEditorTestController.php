<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OverlayEditorTestController extends Controller
{
    /**
     * Return the list of test PDF files from tests/OverlayEditor/.
     */
    public function getTestFiles(Request $request)
    {
        $testDir = base_path('tests/OverlayEditor');
        $pythonScript = base_path('python/validate_overlay_extraction.py');

        if (!is_dir($testDir)) {
            return response()->json(['success' => false, 'message' => 'Test directory not found: tests/OverlayEditor/'], 404);
        }

        $command = sprintf(
            'python3 %s --list-files --test-dir %s 2>/dev/null',
            escapeshellarg($pythonScript),
            escapeshellarg($testDir)
        );

        $output = shell_exec($command);
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Could not list test files'], 500);
        }

        $data = json_decode($output, true);
        if (!$data || !isset($data['files'])) {
            return response()->json(['success' => false, 'message' => 'Failed to parse test file list'], 500);
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
     * Run validation on a single PDF and return results.
     */
    public function runSingleTest(Request $request)
    {
        $request->validate([
            'file_path' => 'required|string',
            'run_id' => 'required|string',
        ]);

        $filePath = $request->input('file_path');
        $runId = $request->input('run_id');
        $pythonScript = base_path('python/validate_overlay_extraction.py');

        if (!file_exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'Test file not found'], 404);
        }

        $command = sprintf(
            'python3 %s --single-file %s 2>&1',
            escapeshellarg($pythonScript),
            escapeshellarg($filePath)
        );

        $output = shell_exec($command);
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Validation script produced no output'], 500);
        }

        $result = json_decode($output, true);
        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse validation result',
                'raw' => substr($output, 0, 1000),
            ], 500);
        }

        // Add test category and section for display
        $result['test_category'] = $result['test_category'] ?? 'Overlay Editor';
        $result['section_name'] = $result['section_name'] ?? 'Extraction Validation';

        return response()->json([
            'success' => true,
            'result' => $result,
        ]);
    }
}
