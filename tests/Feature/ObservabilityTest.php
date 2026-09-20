<?php

namespace Tests\Feature;

use App\Http\Middleware\RequestContext;
use App\Models\Admin;
use App\Models\Document;
use App\Models\User;
use App\Observability\ErrorContext;
use App\Observability\HealthChecks;
use App\Support\ProductionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\TestCase;

/**
 * Errors that can be found again (request ids, the editor's reports, context
 * on exceptions), responses that do not leak Python output, a health check
 * that fails when a dependency is down. Backups: DatabaseBackupTest.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_a_request_id_and_so_does_every_log_line(): void
    {
        $response = $this->get('/up');
        $id = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
        $this->assertSame($id, Context::get('request_id'), 'Context puts it on every log line and carries it into queued jobs');

        // The load balancer's id is kept; anything that is not an id is replaced.
        $this->get('/up', ['X-Request-Id' => 'lb-7f3a9c21-0001'])->assertHeader('X-Request-Id', 'lb-7f3a9c21-0001');
        $this->assertNotSame('<script>', $this->get('/up', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id'));
    }

    public function test_python_output_and_traces_stay_out_of_error_responses_unless_debugging(): void
    {
        Route::middleware('web')->get('/_test/failing', fn () => response()->json([
            'success' => false,
            'message' => 'The PDF could not be processed.',
            'output' => 'Traceback (most recent call last): File "/var/www/html/python/x.py", line 3',
            'trace' => '#0 /var/www/html/app/Http/Controllers/DocumentController.php(955)',
        ], 500));
        Route::middleware('web')->get('/_test/fine', fn () => response()->json(['success' => true, 'output' => 'kept: not an error']));

        config(['app.debug' => false]);
        Log::spy();
        $response = $this->getJson('/_test/failing');
        $response->assertStatus(500)->assertJsonMissingPath('output')->assertJsonMissingPath('trace')
            ->assertJson(['success' => false, 'message' => 'The PDF could not be processed.', 'request_id' => $response->headers->get('X-Request-Id')]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'Error details kept out of a response'
            && str_contains($context['details']['output'], 'Traceback'))->once();
        $this->getJson('/_test/fine')->assertOk()->assertJson(['output' => 'kept: not an error']);

        config(['app.debug' => true]);
        $this->getJson('/_test/failing')->assertJsonPath('output', fn ($output) => str_contains($output, 'Traceback'));
    }

    public function test_the_editor_reports_browser_errors_once_redacted_and_bounded(): void
    {
        config(['observability.client_errors.per_minute' => 1000, 'observability.client_errors.per_minute_per_ip' => 1000]);
        $user = User::factory()->create();
        $report = [
            'kind' => 'unhandledrejection',
            'message' => 'TypeError: annotation is undefined (token=abc123secret)',
            'stack' => "TypeError: annotation is undefined\n    at renderOverlay (main.js:120:9)",
            'page' => '/documents/42/edit-pdfjs?share=private-link',
            'document_id' => 42,
            'editor_session' => 'tab-1',
            'build' => 'abc123',
            'document_text' => 'never part of a report',
        ];

        Log::spy();
        $this->actingAs($user)->postJson(route('clientErrors.store'), $report)->assertNoContent();
        $this->actingAs($user)->postJson(route('clientErrors.store'), $report)->assertNoContent();   // a render loop

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($user) {
            return $message === 'Client error'
                && $context['kind'] === 'unhandledrejection'
                && str_contains($context['message'], 'token=[REDACTED]')
                && $context['page'] === '/documents/42/edit-pdfjs'
                && $context['document_id'] === 42 && $context['build'] === 'abc123' && $context['user_id'] === $user->id
                && ! array_key_exists('document_text', $context);
        })->once();

        $this->postJson(route('clientErrors.store'), ['message' => ''])->assertStatus(422);
        $this->postJson(route('clientErrors.store'), ['message' => str_repeat('x', 20 * 1024)])->assertStatus(413);

        // Each test request is a new session, so it is the per-address limit that is reached here.
        config(['observability.client_errors.per_minute_per_ip' => 2]);
        $statuses = collect(range(1, 4))->map(fn ($n) => $this->postJson(route('clientErrors.store'), ['message' => "flood {$n}"])->status());
        $this->assertContains(429, $statuses->all());
    }

    public function test_a_reported_exception_names_the_document_the_account_and_the_job(): void
    {
        $user = User::factory()->create();
        $document = Document::query()->create(['user_id' => $user->id, 'original_name' => 'a.pdf', 'path' => 'documents/a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        $seen = null;
        Route::middleware('web')->get('/_test/documents/{document}/context', function (Document $document) use (&$seen) {
            $seen = ErrorContext::current();

            return 'ok';
        })->name('test.context');

        $this->actingAs($user)->get("/_test/documents/{$document->id}/context")->assertOk();
        $this->assertSame(['route' => 'test.context', 'document_id' => $document->id, 'user_id' => $user->id], \Illuminate\Support\Arr::except($seen, 'request_id'));
        $this->assertNotEmpty($seen['request_id']);

        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->andReturn(\App\Jobs\GenerateDocumentPreviewJob::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['command' => serialize(new \App\Jobs\GenerateDocumentPreviewJob($document->id))]]);
        ErrorContext::rememberJob(new \Illuminate\Queue\Events\JobProcessing('redis', $job));
        $context = ErrorContext::current();
        $this->assertSame(\App\Jobs\GenerateDocumentPreviewJob::class, $context['job']);
        $this->assertSame($document->id, $context['document_id']);

        // What leaves for Sentry has no API key in it.
        $event = \Sentry\Event::createEvent();
        $event->setExceptions([new \Sentry\ExceptionDataBag(new \RuntimeException('GET https://api.example/v1?key=AIzaSyFAKEFAKEFAKEFAKE1234 failed'))]);
        $sent = \App\Observability\SentryScrubber::beforeSend($event);
        $this->assertStringNotContainsString('AIzaSy', $sent->getExceptions()[0]->getValue());
        $this->assertStringContainsString('[REDACTED]', $sent->getExceptions()[0]->getValue());
    }

    public function test_the_deep_health_check_is_private_and_fails_when_a_dependency_is_down(): void
    {
        config(['observability.health.token' => 'monitor-token']);
        Storage::fake('local');
        $running = \Mockery::mock(MasterSupervisorRepository::class);
        $running->shouldReceive('all')->andReturn([(object) ['name' => 'host-1', 'status' => 'running']]);
        $this->app->instance(MasterSupervisorRepository::class, $running);

        $this->getJson(route('health.deep'))->assertNotFound();
        $this->getJson(route('health.deep'), ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $support = Admin::query()->create(['name' => 'S', 'email' => 'support@netkit.test', 'password' => 'a-long-enough-password-2026']);
        $support->forceFill(['role' => 'support'])->save();
        $this->actingAs($support, 'admin')->getJson(route('health.deep'))->assertNotFound();

        $healthy = $this->getJson(route('health.deep'), ['Authorization' => 'Bearer monitor-token']);
        $healthy->assertOk()->assertJson(['ok' => true])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['database', 'redis', 'horizon', 'python', 'storage', 'backups'], array_keys($healthy->json('checks')));

        // Horizon gone: the check says which dependency, and the status is what a monitor alerts on.
        $stopped = \Mockery::mock(MasterSupervisorRepository::class);
        $stopped->shouldReceive('all')->andReturn([]);
        $this->app->instance(MasterSupervisorRepository::class, $stopped);
        $this->app->forgetInstance(HealthChecks::class);
        $down = $this->getJson(route('health.deep'), ['Authorization' => 'Bearer monitor-token']);
        $down->assertStatus(503)->assertJson(['ok' => false, 'checks' => ['horizon' => ['ok' => false, 'message' => 'no Horizon process is running'], 'database' => ['ok' => true]]]);
        $this->artisan('app:health')->assertExitCode(1);

        // Backups on and none taken is a failure too.
        $this->app->instance(MasterSupervisorRepository::class, $running);
        config(['backup.enabled' => true]);
        $this->getJson(route('health.deep'), ['Authorization' => 'Bearer monitor-token'])
            ->assertStatus(503)->assertJsonPath('checks.backups.message', 'backups are on but there is none yet');

        // /up stays what the load balancer asks, with no token.
        $this->get('/up')->assertOk();
    }

    public function test_production_is_warned_about_what_leaves_it_blind_without_being_stopped(): void
    {
        config(['sentry.dsn' => null, 'observability.health.token' => null, 'backup.enabled' => false, 'horizon.alert_email' => null]);
        $warnings = implode("\n", ProductionConfig::warnings());
        foreach (['SENTRY_LARAVEL_DSN', 'HEALTH_CHECK_TOKEN', 'BACKUP_ENABLED', 'HORIZON_ALERT_EMAIL'] as $variable) {
            $this->assertStringContainsString($variable, $warnings);
        }

        config(['sentry.dsn' => 'https://key@o1.ingest.sentry.io/1', 'observability.health.token' => 't', 'backup.enabled' => true, 'backup.disk' => 's3', 'horizon.alert_email' => 'ops@example.test',
            'logging.default' => 'stderr', 'logging.channels.stderr.level' => 'warning']);
        $this->assertSame([], ProductionConfig::warnings());
    }
}
