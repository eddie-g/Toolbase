<?php

namespace App\Http\Controllers;

use App\Models\ComplianceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ComplianceController extends Controller
{
    /**
     * Return the list of test PDF files.
     */
    public function getTestFiles(Request $request)
    {
        $level = $request->input('level', '1b');
        $pythonScript = base_path('python/test_helpers/run_compliance_tests.py');
        $testDir = base_path('tests/Compliance/PDFA-1b');

        if (!is_dir($testDir)) {
            return response()->json(['success' => false, 'message' => 'Test directory not found'], 404);
        }

        $command = sprintf(
            'python3 %s --test-dir %s --list-files 2>/dev/null',
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
     * Run a single compliance test, store the result, and return it.
     */
    public function runSingleTest(Request $request)
    {
        $request->validate([
            'file_path' => 'required|string',
            'run_id' => 'required|string',
            'level' => 'sometimes|string|in:1b,2b,3b,2u',
        ]);

        $filePath = $request->input('file_path');
        $runId = $request->input('run_id');
        $level = $request->input('level', '1b');
        $pythonScript = base_path('python/test_helpers/run_compliance_tests.py');

        if (!file_exists($filePath)) {
            return response()->json(['success' => false, 'message' => 'Test file not found'], 404);
        }

        $command = sprintf(
            'python3 %s --single-file %s --level %s 2>/dev/null',
            escapeshellarg($pythonScript),
            escapeshellarg($filePath),
            escapeshellarg($level)
        );

        $output = shell_exec($command);
        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Test script produced no output'], 500);
        }

        $result = json_decode($output, true);
        if (!$result) {
            return response()->json(['success' => false, 'message' => 'Failed to parse test result'], 500);
        }

        // Store the result in database
        $report = ComplianceReport::create([
            'run_id' => $runId,
            'filename' => $result['filename'] ?? 'unknown',
            'description' => $result['description'] ?? '',
            'test_category' => $result['test_category'] ?? '',
            'section_name' => $result['section_name'] ?? '',
            'status' => $result['status'] ?? 'error',
            'conversion_success' => $result['conversion_success'] ?? false,
            'checks' => $result['checks'] ?? [],
            'checks_passed' => $result['checks_passed'] ?? 0,
            'checks_total' => $result['checks_total'] ?? 0,
            'compliance_status' => $result['compliance_status'] ?? null,
            'error' => $result['error'] ?? null,
            'warnings' => $result['warnings'] ?? [],
            'file_size_input' => $result['file_size_input'] ?? 0,
            'file_size_output' => $result['file_size_output'] ?? 0,
            'level' => $level,
        ]);

        return response()->json([
            'success' => true,
            'result' => array_merge($result, ['id' => $report->id]),
        ]);
    }

    /**
     * Run the full PDF/A compliance test suite (batch mode) and store results.
     */
    public function runTests(Request $request)
    {
        $level = $request->input('level', '1b');
        $runId = Str::uuid()->toString();
        $pythonScript = base_path('python/test_helpers/run_compliance_tests.py');
        $testDir = base_path('tests/Compliance/PDFA-1b');

        if (!is_dir($testDir)) {
            return response()->json(['success' => false, 'message' => 'Test directory not found'], 404);
        }

        // Run the Python script
        $command = sprintf(
            'python3 %s --test-dir %s --level %s --json 2>/dev/null',
            escapeshellarg($pythonScript),
            escapeshellarg($testDir),
            escapeshellarg($level)
        );

        $output = shell_exec($command);

        if (!$output) {
            return response()->json(['success' => false, 'message' => 'Compliance test script produced no output'], 500);
        }

        $data = json_decode($output, true);
        if (!$data || !isset($data['results'])) {
            return response()->json(['success' => false, 'message' => 'Failed to parse test results', 'raw' => substr($output, 0, 500)], 500);
        }

        // Store each result in the database
        $stored = 0;
        foreach ($data['results'] as $result) {
            ComplianceReport::create([
                'run_id' => $runId,
                'filename' => $result['filename'] ?? 'unknown',
                'description' => $result['description'] ?? '',
                'test_category' => $result['test_category'] ?? '',
                'section_name' => $result['section_name'] ?? '',
                'status' => $result['status'] ?? 'error',
                'conversion_success' => $result['conversion_success'] ?? false,
                'checks' => $result['checks'] ?? [],
                'checks_passed' => $result['checks_passed'] ?? 0,
                'checks_total' => $result['checks_total'] ?? 0,
                'compliance_status' => $result['compliance_status'] ?? null,
                'error' => $result['error'] ?? null,
                'warnings' => $result['warnings'] ?? [],
                'file_size_input' => $result['file_size_input'] ?? 0,
                'file_size_output' => $result['file_size_output'] ?? 0,
                'level' => $level,
            ]);
            $stored++;
        }

        return response()->json([
            'success' => true,
            'run_id' => $runId,
            'total' => $data['total'] ?? $stored,
            'passed' => $data['passed'] ?? 0,
            'failed' => $data['failed'] ?? 0,
            'errors' => $data['errors'] ?? 0,
        ]);
    }
}
