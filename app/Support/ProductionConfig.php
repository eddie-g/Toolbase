<?php

namespace App\Support;

use App\Exceptions\ProductionConfigException;

/**
 * What must be true before the app serves production traffic: Redis-backed
 * state, safe cookie and debug settings, and every secret a live feature
 * needs. problems() names the variable to fix and never a value.
 */
class ProductionConfig
{
    /** Artisan commands that run for as long as the deployment does. */
    private const LONG_RUNNING_COMMANDS = [
        'horizon',
        'horizon:supervisor',
        'horizon:work',
        'queue:work',
        'queue:listen',
        'schedule:run',
        'schedule:work',
    ];

    /** @return string[] */
    public static function problems(): array
    {
        $problems = [];

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false: debug pages print environment variables and stack traces.';
        }

        $url = (string) config('app.url');
        if (! str_starts_with($url, 'https://')) {
            $problems[] = 'APP_URL must be the public https:// address of the site.';
        } elseif (str_ends_with((string) parse_url($url, PHP_URL_HOST), '.example')) {
            $problems[] = 'APP_URL is still the placeholder from .env.example.';
        }

        if (in_array(config('cache.default'), ['array', 'file', 'database', 'null', null], true)) {
            $problems[] = 'CACHE_STORE must be redis: the login throttle and the Python concurrency cap are shared through it.';
        }
        if (in_array(config('session.driver'), ['array', 'file', 'cookie', 'database', null], true)) {
            $problems[] = 'SESSION_DRIVER must be redis: file sessions break with more than one web container, database sessions put every request on MySQL.';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true.';
        }
        if (in_array(config('queue.default'), ['sync', 'null', null], true)) {
            $problems[] = 'QUEUE_CONNECTION must be redis: sync runs PDF extraction and exports inside web requests.';
        }
        if (config('database.default') === 'sqlite') {
            $problems[] = 'DB_CONNECTION must not be sqlite.';
        }

        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array', null], true)) {
            $problems[] = 'MAIL_MAILER must be a real mailer: verification, password reset and sign-in codes are e-mailed.';
        } elseif ($mailer === 'resend' && blank(config('services.resend.key'))) {
            $problems[] = 'RESEND_API_KEY is missing (MAIL_MAILER=resend).';
        }

        if (config('services.domain_lookup') === 'namecheap') {
            foreach (['api_user' => 'NAMECHEAP_API_USER', 'api_key' => 'NAMECHEAP_API_KEY'] as $key => $variable) {
                if (blank(config("services.namecheap.{$key}"))) {
                    $problems[] = "{$variable} is missing (DOMAIN_LOOKUP=namecheap).";
                }
            }
        }

        $optional = (array) config('production.optional', []);
        foreach ((array) config('production.required', []) as $key => $variable) {
            if (! in_array($key, $optional, true) && blank(config($key))) {
                $problems[] = "{$variable} is missing.";
            }
        }

        return $problems;
    }

    /**
     * What does not stop the app but leaves it blind or unprotected. Printed
     * by app:check-config; never a reason to refuse traffic.
     *
     * @return string[]
     */
    public static function warnings(): array
    {
        $warnings = [];

        if (blank(config('sentry.dsn'))) {
            $warnings[] = 'SENTRY_LARAVEL_DSN is not set: server and editor errors are only in the log.';
        }
        if (config('logging.channels.'.config('logging.default').'.level') === 'debug') {
            $warnings[] = 'LOG_LEVEL is debug: use warning (or info) in production.';
        }
        if (blank(config('horizon.alert_email'))) {
            $warnings[] = 'HORIZON_ALERT_EMAIL is not set: nobody is told when a job fails for good or a queue backs up.';
        }
        if (blank(config('observability.health.token'))) {
            $warnings[] = 'HEALTH_CHECK_TOKEN is not set: a monitor cannot call /health/deep.';
        }
        if (! config('backup.enabled')) {
            $warnings[] = 'BACKUP_ENABLED is off: make sure the database service takes its own backups and that a restore has been tried.';
        } elseif (config('backup.disk') === 'local') {
            $warnings[] = 'BACKUP_DISK is local: the backups sit on the same volume as the documents. Use s3.';
        }

        return $warnings;
    }

    /** True for web requests and for the workers and scheduler, false for every other artisan command. */
    public static function appliesTo(bool $runningInConsole, ?string $command): bool
    {
        return ! $runningInConsole || in_array($command, self::LONG_RUNNING_COMMANDS, true);
    }

    public static function assertValid(): void
    {
        $problems = self::problems();
        if ($problems !== []) {
            throw new ProductionConfigException($problems);
        }
    }
}
