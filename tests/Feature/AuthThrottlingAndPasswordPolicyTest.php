<?php

namespace Tests\Feature;

use App\Auth\AdminLogin;
use App\Models\Admin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production Ready, login P1: limiters that hold under distributed abuse,
 * a honeypot on the public forms, and a twelve-character password floor.
 */
class AuthThrottlingAndPasswordPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Throttle Test',
            'email' => 'throttle-'.uniqid().'@example.com',
            'password' => 'a-long-enough-password-2026',
            'password_confirmation' => 'a-long-enough-password-2026',
        ], $overrides);
    }

    public function test_the_login_limiter_has_three_buckets(): void
    {
        $request = Request::create('/login', 'POST', ['email' => 'Someone@Example.com']);
        $request->server->set('REMOTE_ADDR', '198.51.100.7');

        $limits = RateLimiter::limiter('login')($request);

        $this->assertCount(3, $limits);
        $this->assertSame([5, 20, 50], array_map(fn ($l) => $l->maxAttempts, $limits));
        $this->assertSame([60, 3600, 3600], array_map(fn ($l) => $l->decaySeconds, $limits));
        $this->assertSame('someone@example.com|198.51.100.7', $limits[0]->key);
        $this->assertStringStartsWith('login-email:', $limits[1]->key);
        $this->assertSame('login-ip:198.51.100.7', $limits[2]->key);
    }

    public function test_the_two_factor_limiter_never_keys_on_null(): void
    {
        $request = Request::create('/two-factor-challenge', 'POST');
        $request->setLaravelSession($this->app['session']->driver('array'));
        $request->server->set('REMOTE_ADDR', '198.51.100.8');

        $this->assertSame('anon|198.51.100.8', RateLimiter::limiter('two-factor')($request)->key);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $this->registration())->assertRedirect('/portal');
            auth()->logout();
        }

        $blocked = $this->post('/register', $this->registration());

        $blocked->assertRedirect()->assertSessionHasErrors('email');
        $this->assertNotNull($blocked->headers->get('Retry-After'));
    }

    public function test_a_filled_honeypot_refuses_the_registration(): void
    {
        $email = 'bot-'.uniqid().'@example.com';

        $this->post('/register', $this->registration(['email' => $email, 'website' => 'https://spam.example']))
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_password_reset_requests_are_throttled(): void
    {
        // The password broker already spaces repeat emails to one account by
        // sixty seconds; the per-IP limit is what stops a sweep of accounts.
        $users = User::factory()->count(4)->create();

        foreach ($users->take(3) as $user) {
            $this->post('/forgot-password', ['email' => $user->email])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $users->last()->email])->assertRedirect()->assertSessionHasErrors('email');
    }

    public function test_short_passwords_are_refused_and_twelve_characters_pass(): void
    {
        $this->post('/register', $this->registration(['password' => 'short-pw-11', 'password_confirmation' => 'short-pw-11']))
            ->assertSessionHasErrors('password');

        $this->post('/register', $this->registration(['password' => 'twelve-chars', 'password_confirmation' => 'twelve-chars']))
            ->assertRedirect('/portal');
    }

    public function test_the_admin_login_locks_an_account_after_twenty_failures(): void
    {
        $admin = Admin::create(['name' => 'Locked Admin', 'email' => 'locked-admin-'.uniqid().'@netkit.test', 'password' => bcrypt('a-long-enough-password-2026')]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        RateLimiter::clear('admin-login-email:'.sha1($admin->email));
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('admin-login-email:'.sha1($admin->email), 3600);
        }

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'a-long-enough-password-2026'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('admin');
    }

    public function test_hashing_is_configured_to_rehash_on_login(): void
    {
        $this->assertSame('bcrypt', config('hashing.driver'));
        $this->assertTrue((bool) config('hashing.rehash_on_login'));
    }
}
