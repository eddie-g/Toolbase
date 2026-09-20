<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The production image is built from tracked files, and the limits spread
 * over nginx, PHP and the app stay in order: a request the app accepts must
 * not be cut off by a layer in front of it, and the layer that gives up first
 * must be the one that can still answer the client.
 */
class ProductionImageTest extends TestCase
{
    public function test_both_images_build_from_tracked_files_only(): void
    {
        $prod = file_get_contents(base_path('docker/Dockerfile.prod'));
        $this->assertDoesNotMatchRegularExpression('/^\s*(COPY|ADD)\s+(?!--from)\S*vendor\//m', $prod, 'the production image copies nothing from the untracked vendor/ directory');
        $this->assertStringNotContainsString('pcov', strtolower(preg_replace('/#.*$/m', '', $prod)));
        $this->assertStringNotContainsString('xdebug', strtolower(preg_replace('/#.*$/m', '', $prod)));
        $this->assertStringContainsString('requirements-prod.txt', $prod);
        $this->assertStringContainsString('check-python-imports.py', $prod, 'the build proves the Python packages cover the scripts the app runs');

        $compose = file_get_contents(base_path('compose.yaml'));
        $this->assertStringContainsString("dockerfile: './docker/Dockerfile.dev'", $compose);
        $this->assertStringNotContainsString('vendor/laravel/sail/runtimes/8.5/Dockerfile', $compose, 'composer update must not be able to change the development image');

        foreach (['docker/Dockerfile.prod', 'docker/Dockerfile.dev', 'docker/entrypoint.sh', 'docker/php/php.ini', 'docker/php/php-cli.ini',
            'docker/php/fpm-pool.conf.template', 'docker/nginx/nginx.conf', 'docker/nginx/site.conf', 'docker/supervisor/web.conf',
            'python/requirements-prod.txt', '.dockerignore', 'compose.prod.yaml'] as $file) {
            $this->assertFileExists(base_path($file));
        }
    }

    public function test_body_size_limits_grow_from_the_app_outwards(): void
    {
        $appKb = max((int) config('pdf_editor.uploads.max_kb', 20480), (int) config('pdf_editor.autosave.max_body_kb', 20480));
        $ini = parse_ini_file(base_path('docker/php/php.ini'), false, INI_SCANNER_RAW);
        $uploadKb = $this->kilobytes($ini['upload_max_filesize']);
        $postKb = $this->kilobytes($ini['post_max_size']);
        preg_match('/client_max_body_size\s+(\S+);/', file_get_contents(base_path('docker/nginx/site.conf')), $nginx);
        $nginxKb = $this->kilobytes($nginx[1]);

        $this->assertGreaterThan($appKb, $uploadKb, 'PHP accepts the largest upload the app allows');
        $this->assertGreaterThanOrEqual($uploadKb, $postKb);
        $this->assertGreaterThanOrEqual($postKb, $nginxKb, 'nginx lets through what PHP accepts');
    }

    public function test_timeouts_give_up_from_the_inside_out(): void
    {
        $python = max(array_map('intval', (array) config('python.timeouts', [120])));
        preg_match('/fastcgi_read_timeout\s+(\d+)s;/', file_get_contents(base_path('docker/nginx/site.conf')), $nginx);
        preg_match('/request_terminate_timeout\s*=\s*(\d+)s/', file_get_contents(base_path('docker/php/fpm-pool.conf.template')), $fpm);

        // The web request's own Python steps are bounded by the runner; the
        // long conversions run on the queue, not under nginx.
        $longestInRequest = max((int) config('python.timeouts.apply_annotations_direct', 180), (int) config('python.timeouts.default', 120));
        $this->assertGreaterThan($longestInRequest, (int) $nginx[1], 'nginx waits longer than the longest Python step of a request');
        $this->assertGreaterThan((int) $nginx[1], (int) $fpm[1], 'php-fpm kills a request only after nginx has answered the client');
        $this->assertGreaterThan(0, $python);
    }

    public function test_the_fpm_pool_passes_the_environment_and_opcache_never_stats_files(): void
    {
        $pool = file_get_contents(base_path('docker/php/fpm-pool.conf.template'));
        $this->assertMatchesRegularExpression('/^clear_env\s*=\s*no$/m', $pool, 'the container environment is the configuration');
        foreach (['PHP_FPM_MAX_CHILDREN', 'PHP_FPM_START_SERVERS', 'PHP_FPM_MIN_SPARE', 'PHP_FPM_MAX_SPARE'] as $variable) {
            $this->assertStringContainsString('${'.$variable.'}', $pool);
            $this->assertStringContainsString('${'.$variable.'}', file_get_contents(base_path('docker/entrypoint.sh')));
            $this->assertStringContainsString($variable.'=', file_get_contents(base_path('docker/Dockerfile.prod')));
        }

        foreach (['docker/php/php.ini', 'docker/php/php-cli.ini'] as $file) {
            $ini = parse_ini_file(base_path($file), false, INI_SCANNER_RAW);
            $this->assertSame('0', $ini['opcache.validate_timestamps'], $file);
            $this->assertSame('EGPCS', $ini['variables_order'], $file);
        }
    }

    private function kilobytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => intdiv($number, 1024),
        };
    }

    public function test_nginx_does_not_hand_out_annotation_assets_left_on_the_public_disk(): void
    {
        $site = (string) file_get_contents(base_path('docker/nginx/site.conf'));

        $this->assertMatchesRegularExpression('#location \^~ /storage/annotation-assets/ \{\s+return 404;#', $site);
        // Before the image rule, which would otherwise serve the file.
        $this->assertLessThan(strpos($site, 'location ~* \\.(?:ico|png'), strpos($site, '/storage/annotation-assets/'));
    }
}
