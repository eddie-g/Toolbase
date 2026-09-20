<?php

namespace Tests\Feature;

use App\Support\ProductionConfig;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The address the app sees does not depend on how the app happens to be
 * reachable: X-Forwarded-For is only believed from the named proxies and read
 * from their end. (The old '*' believed whoever connected directly: right
 * behind exactly one proxy, forgeable when reached any other way, and the
 * first hop's address for everyone behind two.)
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/whoami', fn () => response()->json(['ip' => request()->ip(), 'secure' => request()->isSecure()]));
    }

    public function test_a_forged_header_from_the_internet_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/_test/whoami', ['X-Forwarded-For' => '203.0.113.77', 'X-Forwarded-Proto' => 'https'])
            ->assertExactJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_behind_the_ingress_the_address_it_appended_wins_over_one_the_client_sent(): void
    {
        // The client sent "203.0.113.77"; the ingress (10.0.0.4) appended who it really was.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->getJson('/_test/whoami', ['X-Forwarded-For' => '203.0.113.77, 198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->assertExactJson(['ip' => '198.51.100.7', 'secure' => true]);

        // Two trusted hops (a front door, then the ingress): both are skipped.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->getJson('/_test/whoami', ['X-Forwarded-For' => '203.0.113.77, 198.51.100.7, 172.16.3.9'])
            ->assertJsonPath('ip', '198.51.100.7');
    }

    public function test_the_limits_per_address_follow_the_real_address(): void
    {
        $key = fn (string $forged) => $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/_test/whoami', ['X-Forwarded-For' => $forged])->json('ip');

        $this->assertSame($key('203.0.113.1'), $key('203.0.113.2'), 'rotating the header must not make a new visitor');
    }

    public function test_the_bootstrap_does_not_trust_everyone_and_production_refuses_to(): void
    {
        $this->assertDoesNotMatchRegularExpression('/->trustProxies\(/', (string) file_get_contents(base_path('bootstrap/app.php')), 'proxies are configured in config/trustedproxy.php only');
        $this->assertNotContains('*', array_map('trim', explode(',', (string) config('trustedproxy.proxies'))));

        config(['trustedproxy.proxies' => '*']);
        $this->assertStringContainsString('TRUSTED_PROXIES', implode("\n", ProductionConfig::problems()));
        config(['trustedproxy.proxies' => '10.0.0.0/23']);
        $this->assertStringNotContainsString('TRUSTED_PROXIES', implode("\n", ProductionConfig::problems()));
    }
}
