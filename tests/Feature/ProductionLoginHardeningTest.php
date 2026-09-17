<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Production Ready, login P0: no artisan-invoking routes, CSRF on every
 * session endpoint but Stripe's webhook, security headers on every web
 * response, and a proxy's X-Forwarded-Proto trusted so https is seen.
 */
class ProductionLoginHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_migration_route_is_gone(): void
    {
        $this->get('/fix-migration-3')->assertNotFound();
    }

    public function test_only_the_stripe_webhook_is_exempt_from_csrf(): void
    {
        // bootstrap/app.php's validateCsrfTokens(except: [...]) lands in the
        // middleware's static neverVerify list.
        $property = new \ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
        $except = $property->getValue();

        $this->assertSame(['/stripe/webhook'], $except);

        // The ones that used to be exempt are session endpoints the app's
        // own pages call with X-CSRF-TOKEN; none of them may be listed.
        foreach ([
            '/ai/chat', '/ai/sections', '/ai/sections/*', '/domain-search/ai-generate',
            '/pdf-state/stamp-preview', '/documents/overwrite-annotation-text',
        ] as $path) {
            $this->assertNotContains($path, $except);
        }
    }

    public function test_every_web_response_carries_the_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS must not be sent over plain http');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_a_proxy_forwarding_https_is_trusted(): void
    {
        // Plain http from the proxy, but X-Forwarded-Proto says the client
        // used https: with trusted proxies the app treats it as secure.
        $this->get('/', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_csp_can_be_enforced_or_switched_off_from_config(): void
    {
        config(['security.headers.csp.mode' => 'enforce']);
        $this->get('/')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

        config(['security.headers.csp.mode' => 'off']);
        $this->get('/')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
