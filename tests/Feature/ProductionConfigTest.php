<?php

namespace Tests\Feature;

use App\Exceptions\ProductionConfigException;
use App\Providers\AppServiceProvider;
use App\Support\ProductionConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A deploy from .env.example lands on Redis everywhere, the template documents
 * every variable the code reads, nothing outside config/ calls env() (it
 * returns null once config is cached), and production refuses to start with
 * unsafe settings or a missing secret.
 */
class ProductionConfigTest extends TestCase
{
    /** Framework variables for drivers this app does not use; not worth a line in the template. */
    private const UNDOCUMENTED_ON_PURPOSE = [
        'ARGON_MEMORY', 'ARGON_THREADS', 'ARGON_TIME', 'BCRYPT_LIMIT', 'HASH_DRIVER', 'HASH_VERIFY',
        'AUTH_GUARD', 'AUTH_MODEL', 'AUTH_PASSWORD_BROKER', 'AUTH_PASSWORD_RESET_TOKEN_TABLE', 'AUTH_PASSWORD_TIMEOUT',
        'BEANSTALKD_QUEUE', 'BEANSTALKD_QUEUE_HOST', 'BEANSTALKD_QUEUE_RETRY_AFTER',
        'SQS_PREFIX', 'SQS_QUEUE', 'SQS_SUFFIX', 'DYNAMODB_CACHE_TABLE', 'DYNAMODB_ENDPOINT',
        'MEMCACHED_HOST', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID', 'MEMCACHED_PORT', 'MEMCACHED_USERNAME',
        'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE', 'DB_CACHE_TABLE',
        'DB_QUEUE', 'DB_QUEUE_CONNECTION', 'DB_QUEUE_RETRY_AFTER', 'DB_QUEUE_TABLE', 'SESSION_TABLE', 'SESSION_STORE',
        'DB_CHARSET', 'DB_COLLATION', 'DB_ENCRYPT', 'DB_FOREIGN_KEYS', 'DB_SOCKET', 'DB_SSLMODE',
        'DB_TRUST_SERVER_CERTIFICATE', 'DB_URL',
        'LOG_DEPRECATIONS_TRACE', 'LOG_PAPERTRAIL_HANDLER', 'LOG_SLACK_EMOJI', 'LOG_SLACK_USERNAME',
        'LOG_SYSLOG_FACILITY', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL',
        'MAIL_EHLO_DOMAIN', 'MAIL_LOG_CHANNEL', 'MAIL_SENDMAIL_PATH', 'MAIL_URL',
        'POSTMARK_API_KEY', 'POSTMARK_MESSAGE_STREAM_ID', 'SLACK_BOT_USER_DEFAULT_CHANNEL', 'SLACK_BOT_USER_OAUTH_TOKEN',
        'REDIS_BACKOFF_ALGORITHM', 'REDIS_BACKOFF_BASE', 'REDIS_BACKOFF_CAP', 'REDIS_MAX_RETRIES', 'REDIS_CLUSTER',
        'REDIS_CACHE_CONNECTION', 'REDIS_CACHE_LOCK_CONNECTION', 'REDIS_LIMITER_CONNECTION', 'REDIS_QUEUE_CONNECTION',
        'SESSION_PARTITIONED_COOKIE',
        // Legacy lower-case spelling still honoured by config/pdf_editor.php.
        'PDF_mode',
    ];

    public function test_the_template_is_a_production_template_on_redis(): void
    {
        $template = $this->templateValues();

        $this->assertSame('production', $template['APP_ENV']);
        $this->assertSame('false', $template['APP_DEBUG']);
        $this->assertStringStartsWith('https://', $template['APP_URL']);
        $this->assertSame('redis', $template['SESSION_DRIVER']);
        $this->assertSame('redis', $template['CACHE_STORE']);
        $this->assertSame('redis', $template['QUEUE_CONNECTION']);
        $this->assertSame('true', $template['SESSION_SECURE_COOKIE']);
        $this->assertSame('true', $template['SESSION_ENCRYPT']);
        $this->assertNotSame('debug', $template['LOG_LEVEL']);
        $this->assertNotSame('sqlite', $template['DB_CONNECTION']);
        $this->assertNotSame('log', $template['MAIL_MAILER']);
    }

    public function test_every_variable_the_config_reads_is_in_the_template(): void
    {
        $documented = $this->templateVariableNames();
        $read = [];
        foreach (Finder::create()->files()->in(config_path())->name('*.php') as $file) {
            preg_match_all('/\benv\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $file->getContents(), $matches);
            $read = array_merge($read, $matches[1]);
        }
        $read = array_unique($read);
        $this->assertGreaterThan(100, count($read), 'the scan found the config files');

        $missing = array_values(array_diff($read, $documented, self::UNDOCUMENTED_ON_PURPOSE));
        sort($missing);
        $this->assertSame([], $missing, 'Add these to .env.example (commented with their default if optional)');

        // The per-connection Redis overrides are built from a name, not written out.
        foreach (['REDIS_SESSION_DB', 'REDIS_LIMITER_DB', 'REDIS_CACHE_DB'] as $variable) {
            $this->assertContains($variable, $documented);
        }
        $this->assertStringContainsString('REDIS_<CACHE|SESSION|LIMITER>_HOST', file_get_contents(base_path('.env.example')));
    }

    public function test_nothing_outside_config_calls_env(): void
    {
        $offenders = [];
        $finder = Finder::create()->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), resource_path('views'), database_path()])
            ->exclude('cache');
        foreach ($finder as $file) {
            foreach (explode("\n", $file->getContents()) as $number => $line) {
                if (preg_match('/(?<![A-Za-z0-9_>:$])env\(\s*[\'"]/', $line)) {
                    $offenders[] = $file->getRelativePathname().':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'env() returns null once config is cached; read config() instead');
    }

    public function test_a_boot_from_the_template_lands_on_redis_with_split_connections(): void
    {
        $booted = $this->bootFromTemplate();

        $this->assertSame('production', $booted['env']);
        $this->assertFalse($booted['debug']);
        $this->assertSame(['redis', 'redis', 'redis'], [$booted['cache'], $booted['session'], $booted['queue']]);
        $this->assertSame('session', $booted['session_connection']);
        $this->assertSame('limiter', $booted['limiter_store']);
        $this->assertSame('limiter', $booted['limiter_connection']);
        $this->assertSame('default', $booted['horizon_use']);
        $this->assertSame('default', $booted['queue_redis_connection']);
        $this->assertSame(
            ['default' => '0', 'cache' => '1', 'session' => '2', 'limiter' => '3'],
            $booted['redis_databases'],
            'one database index per concern'
        );
        $this->assertTrue($booted['session_secure']);
        $this->assertTrue($booted['session_encrypt']);

        // The template has no secrets yet: that, and only that, is what is left to fix.
        $this->assertContains('APP_KEY is missing.', $booted['problems']);
        $this->assertContains('STRIPE_WEBHOOK_SECRET is missing.', $booted['problems']);
        $this->assertContains('RESEND_API_KEY is missing (MAIL_MAILER=resend).', $booted['problems']);
        $this->assertContains('APP_URL is still the placeholder from .env.example.', $booted['problems']);
        foreach ($booted['problems'] as $problem) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(APP_DEBUG|CACHE_STORE|SESSION_DRIVER|SESSION_SECURE_COOKIE|QUEUE_CONNECTION|DB_CONNECTION|MAIL_MAILER) /',
                $problem
            );
        }
    }

    public function test_valid_production_settings_pass_and_each_unsafe_one_is_named(): void
    {
        $this->useValidProductionConfig();
        $this->assertSame([], ProductionConfig::problems());

        $cases = [
            'APP_DEBUG' => ['app.debug' => true],
            'APP_URL' => ['app.url' => 'http://netkit.test'],
            'CACHE_STORE' => ['cache.default' => 'database'],
            'SESSION_DRIVER' => ['session.driver' => 'database'],
            'SESSION_SECURE_COOKIE' => ['session.secure' => null],
            'QUEUE_CONNECTION' => ['queue.default' => 'sync'],
            'DB_CONNECTION' => ['database.default' => 'sqlite'],
            'MAIL_MAILER' => ['mail.default' => 'log'],
            'RESEND_API_KEY' => ['services.resend.key' => ''],
            'STRIPE_WEBHOOK_SECRET' => ['services.stripe.webhook_secret' => null],
            'APP_KEY' => ['app.key' => ''],
            'NAMECHEAP_API_KEY' => ['services.domain_lookup' => 'namecheap', 'services.namecheap.api_key' => ''],
        ];
        foreach ($cases as $variable => $override) {
            $this->useValidProductionConfig();
            config($override);
            $problems = ProductionConfig::problems();
            $this->assertCount(1, $problems, $variable);
            $this->assertStringStartsWith($variable.' ', $problems[0]);
        }

        // A deployment that runs without a feature says so.
        $this->useValidProductionConfig();
        config(['services.recraft.key' => null]);
        $this->assertSame(['RECRAFT_KEY is missing.'], ProductionConfig::problems());
        config(['production.optional' => ['services.recraft.key']]);
        $this->assertSame([], ProductionConfig::problems());
    }

    public function test_production_refuses_to_boot_and_says_what_to_fix(): void
    {
        $this->useValidProductionConfig();
        config(['services.stripe.secret' => null, 'app.debug' => true]);
        $this->app['env'] = 'production';
        $provider = new AppServiceProvider($this->app);

        // Web requests and the long-running workers are refused...
        $this->assertTrue(ProductionConfig::appliesTo(false, null));
        foreach (['horizon', 'horizon:work', 'queue:work', 'schedule:run'] as $command) {
            $this->assertTrue(ProductionConfig::appliesTo(true, $command), $command);
        }
        // ...the commands that build and deploy the image are not.
        foreach (['config:cache', 'migrate', 'app:check-config', 'package:discover', null] as $command) {
            $this->assertFalse(ProductionConfig::appliesTo(true, $command), (string) $command);
        }

        try {
            ProductionConfig::assertValid();
            $this->fail('expected the production check to throw');
        } catch (ProductionConfigException $exception) {
            $this->assertCount(2, $exception->problems);
            $this->assertStringContainsString('STRIPE_SECRET is missing.', $exception->getMessage());
            $this->assertStringContainsString('APP_DEBUG must be false', $exception->getMessage());
            $this->assertStringContainsString('php artisan app:check-config', $exception->getMessage());
        }

        // PHPUnit is a console run of "phpunit", not a worker: the provider boots.
        $provider->boot();

        $this->artisan('app:check-config')
            ->expectsOutputToContain('STRIPE_SECRET is missing.')
            ->assertExitCode(1);

        // The switch is for diagnosis only, and other environments are never checked.
        $this->useValidProductionConfig();
        $this->artisan('app:check-config')->assertExitCode(0);
    }

    public function test_cache_clear_leaves_sessions_and_login_throttles_alone(): void
    {
        // Scratch database indexes, so the developer's own Redis data is not touched.
        config([
            'cache.default' => 'redis',
            'cache.limiter' => 'limiter',
            'cache.prefix' => 'nk-test-'.bin2hex(random_bytes(4)).'-',
            'database.redis.cache.database' => '11',
            'database.redis.session.database' => '12',
            'database.redis.limiter.database' => '13',
            'session.driver' => 'redis',
            'session.connection' => 'session',
        ]);
        try {
            Redis::connection('cache')->ping();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Redis is not reachable: '.$exception->getMessage());
        }
        foreach (['cache', 'session', 'limiter'] as $connection) {
            Redis::purge($connection);
            Redis::connection($connection)->flushdb();
        }
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        Cache::clearResolvedInstances();
        RateLimiter::clearResolvedInstances();

        try {
            Cache::put('page-fragment', 'cached', 600);
            RateLimiter::hit('login|someone@example.com|203.0.113.7', 600);
            RateLimiter::hit('login|someone@example.com|203.0.113.7', 600);

            $session = $this->app->make('session')->driver('redis');
            $session->setId($sessionId = \Illuminate\Support\Str::random(40));
            $session->put('login_web', 42);
            $session->save();

            $this->assertSame(1, (int) Redis::connection('cache')->dbsize());
            $this->assertSame(1, (int) Redis::connection('session')->dbsize());
            $this->assertGreaterThanOrEqual(1, (int) Redis::connection('limiter')->dbsize());

            $this->artisan('cache:clear')->assertExitCode(0);

            $this->assertNull(Cache::get('page-fragment'));
            $this->assertSame(0, (int) Redis::connection('cache')->dbsize());
            $this->assertSame(2, (int) RateLimiter::attempts('login|someone@example.com|203.0.113.7'), 'throttle counters survive cache:clear');

            $reopened = $this->app->make('session')->driver('redis');
            $reopened->setId($sessionId);
            $reopened->start();
            $this->assertSame(42, $reopened->get('login_web'), 'signed-in sessions survive cache:clear');
        } finally {
            foreach (['cache', 'session', 'limiter'] as $connection) {
                Redis::connection($connection)->flushdb();
            }
        }
    }

    private function useValidProductionConfig(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://netkit.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'session.secure' => true,
            'queue.default' => 'redis',
            'database.default' => 'mysql',
            'mail.default' => 'resend',
            'services.resend.key' => 're_test',
            'services.domain_lookup' => 'whois',
            'production.optional' => [],
        ]);
        foreach (array_keys(config('production.required')) as $key) {
            if ($key !== 'app.key') {
                config([$key => 'set']);
            }
        }
    }

    /** @return array<string, string> variables that have a value line in the template */
    private function templateValues(): array
    {
        $values = [];
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $line, $match)) {
                $values[$match[1]] = trim($match[2], '"');
            }
        }

        return $values;
    }

    /** @return string[] every variable named in the template, set or commented out */
    private function templateVariableNames(): array
    {
        preg_match_all('/^#? ?([A-Za-z0-9_]+)=/m', file_get_contents(base_path('.env.example')), $matches);

        return array_unique($matches[1]);
    }

    /** Boots the framework in a clean process whose only environment is .env.example. */
    private function bootFromTemplate(): array
    {
        $directory = sys_get_temp_dir().'/netkit_env_'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        copy(base_path('.env.example'), $directory.'/.env');
        $script = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->useEnvironmentPath($argv[2]);
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo json_encode([
                'env' => $app->environment(),
                'debug' => config('app.debug'),
                'cache' => config('cache.default'),
                'session' => config('session.driver'),
                'queue' => config('queue.default'),
                'session_connection' => config('session.connection'),
                'session_secure' => config('session.secure'),
                'session_encrypt' => config('session.encrypt'),
                'limiter_store' => config('cache.limiter'),
                'limiter_connection' => config('cache.stores.limiter.connection'),
                'horizon_use' => config('horizon.use'),
                'queue_redis_connection' => config('queue.connections.redis.connection'),
                'redis_databases' => array_map(
                    fn ($name) => (string) config("database.redis.{$name}.database"),
                    ['default' => 'default', 'cache' => 'cache', 'session' => 'session', 'limiter' => 'limiter']
                ),
                'problems' => App\Support\ProductionConfig::problems(),
            ]);
            PHP;

        try {
            // env -i: none of PHPUnit's APP_ENV=testing / CACHE_STORE=array leaks in.
            $process = new Process(
                ['env', '-i', 'PATH='.getenv('PATH'), PHP_BINARY, '-r', $script, base_path(), $directory],
                base_path(),
                null,
                null,
                60
            );
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $booted = json_decode($process->getOutput(), true);
            $this->assertIsArray($booted, $process->getOutput());

            return $booted;
        } finally {
            @unlink($directory.'/.env');
            @rmdir($directory);
        }
    }
}
