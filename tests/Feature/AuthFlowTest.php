<?php

namespace Tests\Feature;

use App\Auth\AdminLogin;
use App\Models\Admin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The sign-in flows end to end, through the real routes: what the session
 * does on login and logout, a password reset from request to new password,
 * the second factor, and a refused admin. (Throttling, lockouts and the audit
 * trail: AuthThrottlingAndPasswordPolicyTest, SessionInvalidationAndAuditTest.)
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password-2026';

    public function test_signing_in_changes_the_session_id_and_signing_out_ends_the_session(): void
    {
        $user = $this->verifiedUser();

        // A session id an attacker planted before sign-in must not survive it.
        $this->get('/login')->assertOk();
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password-2026'])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user, 'web');
        $signedIn = session()->getId();
        $this->assertNotSame($before, $signedIn, 'the session id is regenerated on sign-in');

        session()->put('left-behind', 'by the signed-in user');
        $this->post('/logout')->assertRedirect();
        $this->assertGuest('web');
        $this->assertNotSame($signedIn, session()->getId(), 'the session is invalidated on sign-out');
        $this->assertNull(session('left-behind'));
    }

    public function test_a_password_reset_goes_from_request_to_signing_in_with_the_new_password(): void
    {
        Notification::fake();
        $user = $this->verifiedUser();

        // The same answer whether or not the address has an account.
        $answers = [];
        foreach ([$user->email, 'nobody-'.uniqid().'@example.test'] as $email) {
            $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => $email]);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Location'), session('status'), session('errors')?->all() ?? []];
        }
        $this->assertSame($answers[0], $answers[1], 'the form must not say which addresses have an account');
        $this->assertSame([], $answers[0][3]);
        $this->assertNotEmpty($answers[0][2]);
        // Asking again at once is throttled for a real account only: that must not show either.
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $new = 'an-even-longer-password-2027';
        $this->post('/reset-password', ['token' => 'not-the-token', 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
            ->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password), 'nothing changed yet');

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check($new, $user->fresh()->password));

        // The token works once.
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => $new])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user->fresh(), 'web');
    }

    public function test_an_account_with_a_second_factor_is_not_signed_in_until_the_code_is_given(): void
    {
        $user = $this->verifiedUser();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $secret = decrypt($user->fresh()->two_factor_secret);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest('web');
        $this->get('/portal')->assertRedirect();   // the password alone opens nothing
        $this->assertGuest('web');

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('web');

        // A wrong code ends the challenge: the password is asked for again.
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user->fresh(), 'web');

        // A recovery code works once.
        $this->post('/logout');
        $recovery = $user->fresh()->recoveryCodes()[0];
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/portal');
        $this->assertNotContains($recovery, $user->fresh()->recoveryCodes());
    }

    public function test_the_admin_panel_refuses_a_wrong_password_a_user_account_and_an_admin_without_a_role(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Admin::create(['name' => 'Flow Admin', 'email' => 'flow-admin-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD), 'role' => Admin::ROLE_ADMIN]);
        $noRole = Admin::create(['name' => 'No Role', 'email' => 'flow-norole-'.uniqid().'@netkit.test', 'password' => bcrypt(self::PASSWORD)]);
        $user = $this->verifiedUser();

        $this->get('/admin')->assertRedirect();
        foreach ([[$admin->email, 'not-the-password-2026'], [$user->email, self::PASSWORD], [$noRole->email, self::PASSWORD]] as [$email, $password]) {
            Livewire::test(AdminLogin::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate')->assertHasFormErrors(['email']);
            $this->assertGuest('admin');
        }

        Livewire::test(AdminLogin::class)->fillForm(['email' => $admin->email, 'password' => self::PASSWORD])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin, 'admin');
        // The admin guard is not the web guard: the portal is still closed.
        $this->assertGuest('web');
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()]);
    }
}
