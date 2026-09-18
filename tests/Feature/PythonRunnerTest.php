<?php

namespace Tests\Feature;

use App\Exceptions\PythonServiceBusyException;
use App\Services\PythonRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Production Ready, editor P0: every subprocess runs through PythonRunner
 * with a hard timeout and a concurrency cap, and nothing in app/ calls
 * exec() or shell_exec() directly any more.
 */
class PythonRunnerTest extends TestCase
{
    private function runner(): PythonRunner
    {
        return app(PythonRunner::class);
    }

    public function test_exec_keeps_the_semantics_of_the_function_it_replaced(): void
    {
        $output = ['already-there'];
        $last = $this->runner()->exec('echo first; echo second', $output, $code);

        $this->assertSame('second', $last);
        $this->assertSame(['already-there', 'first', 'second'], $output);
        $this->assertSame(0, $code);

        $this->assertFalse($this->runner()->exec('true', $empty, $emptyCode));
        $this->assertSame([], $empty);
        $this->assertSame(0, $emptyCode);
    }

    public function test_shell_exec_returns_the_output_or_null(): void
    {
        $this->assertSame("hello\n", $this->runner()->shellExec('echo hello'));
        $this->assertNull($this->runner()->shellExec('true'));
    }

    public function test_stderr_is_kept_even_without_a_redirect(): void
    {
        $result = $this->runner()->shell('echo out; echo err 1>&2; exit 3');

        $this->assertSame(3, $result->exitCode);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('err', $result->output);
        $this->assertSame("err\n", $result->stderr);
    }

    public function test_a_hung_process_is_killed_at_its_timeout_and_reports_it(): void
    {
        $started = microtime(true);
        $last = $this->runner()->exec('sleep 20', $output, $code, ['timeout' => 1]);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(5, $elapsed, 'the process must not run to completion');
        $this->assertSame(PythonRunner::TIMEOUT_EXIT_CODE, $code);
        $this->assertStringContainsString('timed out after 1s', (string) $last);
    }

    public function test_timeouts_follow_the_script_named_in_the_command(): void
    {
        $this->assertSame(180, $this->runner()->timeoutFor("/usr/bin/python3 '/app/python/pdf-editor/apply_annotations_direct_new.py' in.pdf"));
        $this->assertSame(120, $this->runner()->timeoutFor("python3 extract_pdf_pymupdf.py x.pdf 1"));
        $this->assertSame(30, $this->runner()->timeoutFor("python3 render_page_preview.py a b 320 58"));
        $this->assertSame((int) config('python.timeouts.default'), $this->runner()->timeoutFor('python3 something_else.py'));
    }

    public function test_the_concurrency_cap_refuses_the_extra_process_with_503(): void
    {
        config(['python.max_concurrent' => 1, 'python.slot_wait_seconds' => 0.2]);
        $held = Cache::lock('python-runner:slot:0', 30);
        $this->assertTrue($held->get(), 'the test holds the only slot');

        try {
            try {
                $this->runner()->shell('echo never');
                $this->fail('expected PythonServiceBusyException');
            } catch (PythonServiceBusyException $e) {
                $this->assertSame(5, $e->retryAfterSeconds);
            }

            Route::get('/__test/python-busy', fn () => app(PythonRunner::class)->shellExec('echo never'));
            $this->getJson('/__test/python-busy')
                ->assertStatus(503)
                ->assertHeader('Retry-After', '5')
                ->assertJsonPath('success', false);
        } finally {
            $held->release();
        }

        // Slot free again: the same call runs.
        $this->assertSame("ran\n", $this->runner()->shellExec('echo ran'));
    }

    public function test_a_background_launcher_returns_at_once_without_holding_a_slot(): void
    {
        $started = microtime(true);
        $pid = (int) trim((string) $this->runner()->shellExec('nohup sleep 3 > /dev/null 2>&1 & echo $!', ['slot' => false, 'timeout' => 5]));

        $this->assertGreaterThan(0, $pid);
        $this->assertLessThan(2, microtime(true) - $started);
        posix_kill($pid, 9);
    }

    public function test_the_interpreter_it_resolves_can_import_pymupdf(): void
    {
        $python = $this->runner()->interpreter('fitz');
        $probe = $this->runner()->run([$python, '-c', 'import fitz; print(fitz.__doc__ is not None)']);

        $this->assertTrue($probe->ok(), $probe->stderrTail());
        $this->assertSame($python, $this->runner()->interpreter('fitz'), 'memoised per process');
    }

    public function test_nothing_in_app_calls_exec_or_shell_exec_directly(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'PythonRunner.php')) {
                continue;
            }
            $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file->getPathname()));
            if (preg_match('/(?<![\w>$])(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', $code)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'process calls must go through App\Services\PythonRunner');
    }
}
