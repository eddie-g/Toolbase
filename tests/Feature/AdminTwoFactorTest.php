<?php

namespace Tests\Feature;

use App\Auth\AdminTwoFactor;
use App\Auth\AdminTwoFactorChallenge;
use App\Auth\AdminTwoFactorSetup;
use App\Models\Admin;
use App\Support\ProductionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Admin accounts need a second factor: nothing in the panel, Horizon or the
 * admin-only tools opens on a password alone, an account without one is made
 * to set it up, and a code or a recovery code works once.
 */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.admin_two_factor.required' => true]);
    }

    public function test_an_admin_without_a_second_factor_is_sent_to_set_it_up_and_nowhere_else(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin')->assertRedirect(route('filament.admin.two-factor.setup'));
        $this->actingAs($admin, 'admin')->get('/horizon')->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('filament.admin.two-factor.challenge'))->assertRedirect(route('filament.admin.two-factor.setup'));

        $setup = Livewire::actingAs($admin, 'admin')->test(AdminTwoFactorSetup::class);
        $secret = app(AdminTwoFactor::class)->secret($admin->fresh());
        $this->assertNotNull($secret, 'opening the page starts enrolment');
        $this->assertStringContainsString('<svg', $setup->instance()->qrCode());
        $this->assertStringNotContainsString($secret, (string) $admin->fresh()->getRawOriginal('two_factor_secret'), 'stored encrypted');

        $setup->set('data.code', '000000')->call('confirm')->assertHasErrors(['data.code']);
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);

        $setup->set('data.code', (new Google2FA())->getCurrentOtp($secret))->call('confirm')->assertHasNoErrors();
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->assertCount(8, $setup->get('recoveryCodes'));

        // That session is verified; the panel opens.
        $this->actingAs($admin->fresh(), 'admin')->get('/admin')->assertRedirect();
        $this->assertNotSame(route('filament.admin.two-factor.setup'), $this->actingAs($admin->fresh(), 'admin')->get('/admin')->headers->get('Location'));
    }

    public function test_an_enrolled_admin_must_give_a_code_in_every_new_session(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->actingAs($admin, 'admin')->get('/admin/overview')->assertRedirect(route('filament.admin.two-factor.challenge'));
        $this->actingAs($admin, 'admin')->get('/horizon')->assertForbidden();
        $this->actingAs($admin, 'admin')->get('/pdf-tests/upload-tests')->assertRedirect(route('filament.admin.two-factor.challenge'));
        $this->actingAs($admin, 'admin')->get(route('filament.admin.two-factor.challenge'))->assertOk()->assertSee('authenticator app');

        $challenge = Livewire::actingAs($admin, 'admin')->test(AdminTwoFactorChallenge::class);
        $challenge->set('data.code', '123456')->call('verify')->assertHasErrors(['data.code']);
        $this->assertFalse(app(AdminTwoFactor::class)->isVerified(request(), $admin));

        $code = (new Google2FA())->getCurrentOtp($secret);
        $challenge->set('data.code', $code)->call('verify')->assertHasNoErrors()->assertRedirect();
        $this->assertSame($admin->id, session(AdminTwoFactor::SESSION_KEY));

        // Verified: the panel and Horizon's gate let the operator through.
        $this->actingAs($admin, 'admin')->withSession([AdminTwoFactor::SESSION_KEY => $admin->id])->get('/admin/overview')->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($admin)->allows('viewHorizon'));

        // The same code does not work a second time, in any session.
        $this->assertFalse(app(AdminTwoFactor::class)->verify($admin->fresh(), $code));

        // Another admin's verified flag is not this admin's.
        $other = $this->enrolledAdmin()[0];
        $this->flushSession();   // AuthenticateSession signs out a session that changes account
        $this->actingAs($other, 'admin')->withSession([AdminTwoFactor::SESSION_KEY => $admin->id])
            ->get('/admin/overview')->assertRedirect(route('filament.admin.two-factor.challenge'));
    }

    public function test_a_recovery_code_works_once_and_guessing_is_limited(): void
    {
        [$admin] = $this->enrolledAdmin();
        $twoFactor = app(AdminTwoFactor::class);
        $codes = json_decode(decrypt($admin->fresh()->two_factor_recovery_codes), true);

        $this->assertTrue($twoFactor->verify($admin->fresh(), strtoupper($codes[0])));
        $this->assertFalse($twoFactor->verify($admin->fresh(), $codes[0]), 'used up');
        $this->assertCount(7, json_decode(decrypt($admin->fresh()->two_factor_recovery_codes), true));

        $challenge = Livewire::actingAs($admin, 'admin')->test(AdminTwoFactorChallenge::class);
        foreach (range(1, 5) as $attempt) {
            $challenge->set('data.code', '111111')->call('verify')->assertHasErrors(['data.code']);
        }
        $challenge->set('data.code', $codes[1])->call('verify')->assertHasErrors(['data.code']);
        $this->assertStringContainsString('Too many attempts', $challenge->errors()->first('data.code'));
    }

    public function test_it_is_required_in_production_and_optional_for_the_qa_admin_elsewhere(): void
    {
        config(['security.admin_two_factor.required' => false]);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get('/admin/overview')->assertOk();

        $this->assertContains(
            'ADMIN_TWO_FACTOR_REQUIRED must not be false: admin accounts need a second factor.',
            ProductionConfig::problems()
        );
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Operator',
            'email' => 'operator-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'a-long-enough-password',
            'role' => Admin::ROLE_ADMIN,
        ]);
    }

    /** @return array{0: Admin, 1: string} */
    private function enrolledAdmin(): array
    {
        $admin = $this->admin();
        $twoFactor = app(AdminTwoFactor::class);
        $twoFactor->beginEnrolment($admin);
        $secret = $twoFactor->secret($admin);
        // Enrol with the previous window's code, so the current one is still unused for the test.
        $engine = new Google2FA();
        $this->assertNotNull($twoFactor->confirmEnrolment($admin, $engine->oathTotp($secret, (int) floor(time() / 30) - 1)));

        return [$admin->fresh(), $secret];
    }
}
