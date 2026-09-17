<?php

namespace Tests\Feature;

use App\Auth\AdminLogin;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use App\UserPortal\Pages\Profile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Production Ready, login P1/P2: sessions end when the password changes or
 * the user logs out other devices, sensitive profile changes ask for the
 * password, admin access is role based, and every auth event is recorded.
 */
class SessionInvalidationAndAuditTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'a-long-enough-password-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function verifiedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ], $attributes));
    }

    public function test_a_session_carrying_a_stale_password_hash_is_signed_out(): void
    {
        $user = $this->verifiedUser();

        // AuthenticateSession keeps the password hash in the session; a session
        // that still holds the hash from before a change is signed out.
        $this->withSession(['password_hash_web' => $user->password])
            ->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/portal');

        $this->withSession(['password_hash_web' => Hash::make('an-old-password-value')])
            ->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_changing_the_password_rehashes_and_records_the_event(): void
    {
        $user = $this->verifiedUser();
        $before = $user->password;

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => self::PASSWORD,
                'password' => 'another-long-password-2026',
                'password_confirmation' => 'another-long-password-2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotSame($before, $user->refresh()->password);
        $this->assertTrue(Hash::check('another-long-password-2026', $user->password));
    }

    public function test_logging_out_other_devices_needs_the_password_and_rehashes(): void
    {
        $user = $this->verifiedUser();
        $before = $user->password;
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->callAction('logoutOtherSessions', ['password' => 'not-the-password-at-all'])
            ->assertHasActionErrors(['password']);

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->callAction('logoutOtherSessions', ['password' => self::PASSWORD])
            ->assertHasNoActionErrors();

        $user->refresh();
        $this->assertNotSame($before, $user->password, 'the password is re-hashed so other sessions no longer match');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertDatabaseHas('auth_events', ['event' => 'other_devices_logout', 'user_id' => $user->id]);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $user = $this->verifiedUser();
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->fillForm(['name' => $user->name, 'email' => 'changed-'.uniqid().'@example.com'])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $newEmail = 'changed-'.uniqid().'@example.com';
        Livewire::actingAs($user)
            ->test(Profile::class)
            ->fillForm(['name' => $user->name, 'email' => $newEmail, 'current_password' => self::PASSWORD])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($newEmail, $user->refresh()->email);
    }

    public function test_web_logins_failures_and_lockouts_are_recorded(): void
    {
        $user = $this->verifiedUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-here'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('auth_events', ['event' => 'failed', 'guard' => 'web', 'email' => strtolower($user->email)]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertDatabaseHas('auth_events', ['event' => 'login', 'guard' => 'web', 'user_id' => $user->id]);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertSame('127.0.0.1', $user->last_login_ip);

        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseHas('auth_events', ['event' => 'logout', 'guard' => 'web', 'user_id' => $user->id]);

        // Six attempts in a minute: the sixth is refused with 429 and recorded as a lockout.
        $other = $this->verifiedUser();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $other->email, 'password' => 'wrong-password-here']);
        }
        $blocked = $this->post('/login', ['email' => $other->email, 'password' => 'wrong-password-here']);
        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertDatabaseHas('auth_events', ['event' => 'lockout', 'email' => strtolower($other->email)]);
    }

    public function test_a_login_from_a_new_address_emails_the_user(): void
    {
        $user = $this->verifiedUser();

        // First ever login: nothing to compare with, no email.
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        Notification::assertNotSentTo($user, NewDeviceLogin::class);
        $this->post('/logout');

        $user->forceFill(['last_login_ip' => '203.0.113.50'])->save();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        Notification::assertSentTo($user, NewDeviceLogin::class);
    }

    public function test_admin_logins_are_recorded_on_the_admin_guard(): void
    {
        $admin = Admin::create(['name' => 'Audit Admin', 'email' => 'audit-admin-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD), 'role' => Admin::ROLE_ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => self::PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('auth_events', ['event' => 'login', 'guard' => 'admin', 'user_id' => $admin->id]);
        $this->assertNotNull($admin->refresh()->last_login_at);
    }

    public function test_admin_access_is_role_based(): void
    {
        $owner = Admin::create(['name' => 'Owner', 'email' => 'owner-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD), 'role' => Admin::ROLE_OWNER]);
        $support = Admin::create(['name' => 'Support', 'email' => 'support-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD), 'role' => Admin::ROLE_SUPPORT]);
        $noRole = Admin::create(['name' => 'Nobody', 'email' => 'norole-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD)]);

        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($support->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($noRole->canAccessPanel(Filament::getPanel('admin')));

        $this->assertTrue(Gate::forUser($owner)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($support)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($noRole)->allows('viewHorizon'));

        // The panel has no dashboard page, so its root sends an admin on to the first page.
        $this->actingAs($owner, 'admin')->followingRedirects()->get('/admin')->assertOk();
        $this->actingAs($noRole, 'admin')->get('/admin')->assertForbidden();
    }

    public function test_the_role_command_grants_and_revokes_panel_access(): void
    {
        $admin = Admin::create(['name' => 'Cmd', 'email' => 'cmd-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD)]);

        $this->artisan('admin:role', ['email' => $admin->email, 'role' => 'support'])->assertSuccessful();
        $this->assertSame('support', $admin->refresh()->role);

        $this->artisan('admin:role', ['email' => $admin->email, 'role' => 'none'])->assertSuccessful();
        $this->assertNull($admin->refresh()->role);

        $this->artisan('admin:role', ['email' => $admin->email, 'role' => 'god'])->assertFailed();
    }
}
