<?php

namespace Tests\Feature;

use App\FilamentPages\AuthEvents;
use App\Listeners\SendTwoFactorCodeListener;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One-time codes are sent, never logged; a code that cannot be texted is
 * e-mailed; operators can see who signed in; the hash cost can be measured.
 */
class LoginFollowupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_text_message_goes_to_twilio_and_its_body_is_never_logged(): void
    {
        config(['services.twilio' => ['sid' => 'AC123', 'token' => 'secret-token', 'from' => '+15550100']]);
        Http::fake(['api.twilio.com/*' => Http::sequence()->push(['sid' => 'SM1'], 201)->push(['code' => 21211, 'message' => 'Invalid To'], 400)]);
        Log::spy();
        $sms = app(SmsService::class);

        $this->assertTrue($sms->send('+1 (555) 010-1234', 'Your Netkit sign-in code is 482913'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request['To'] === '+1 (555) 010-1234' && $request['From'] === '+15550100' && str_contains($request['Body'], '482913')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret-token')));

        $this->assertFalse($sms->send('+15550101234', 'Your Netkit sign-in code is 771204'), 'Twilio refused it');

        config(['services.twilio.token' => null]);
        $this->assertFalse($sms->isConfigured());
        $this->assertFalse($sms->send('+15550101234', 'Your Netkit sign-in code is 990011'));

        foreach (['info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$arguments) => str_contains(json_encode($arguments), '482913')
                || str_contains(json_encode($arguments), '771204') || str_contains(json_encode($arguments), '990011')
                || str_contains(json_encode($arguments), '5550101234'));
        }
        $this->assertSame('•••••••1234', SmsService::mask('+1 555 010 1234'));
    }

    public function test_a_code_that_cannot_be_texted_is_emailed_instead(): void
    {
        config(['services.twilio' => ['sid' => null, 'token' => null, 'from' => null]]);
        $user = User::factory()->create(['phone' => '+15550101234']);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_channel' => 'sms', 'two_factor_confirmed_at' => now()])->save();

        app(SendTwoFactorCodeListener::class)->handle(new TwoFactorAuthenticationChallenged($user->fresh()));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame($user->email, $email->getTo()[0]->getAddress());
        $this->assertMatchesRegularExpression('/sign-in code is \d{6}/', $email->getTextBody());

        // An account on the authenticator app gets nothing sent at all.
        $appUser = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($appUser);
        app(SendTwoFactorCodeListener::class)->handle(new TwoFactorAuthenticationChallenged($appUser->fresh()));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_operators_can_see_sign_in_activity_and_support_cannot(): void
    {
        AuthEvent::create(['guard' => 'web', 'event' => 'failed', 'email' => 'someone@example.test', 'ip' => '203.0.113.7', 'user_agent' => 'Firefox']);
        AuthEvent::create(['guard' => 'admin', 'event' => 'login', 'user_id' => 1, 'email' => 'boss@example.test', 'ip' => '198.51.100.4', 'user_agent' => 'Chrome']);
        $operator = Admin::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'a-long-enough-password', 'role' => Admin::ROLE_ADMIN]);
        $support = Admin::create(['name' => 'Help', 'email' => 'help@example.test', 'password' => 'a-long-enough-password', 'role' => Admin::ROLE_SUPPORT]);

        $this->actingAs($operator, 'admin')->get('/admin/sign-in-activity')->assertOk()
            ->assertSee('someone@example.test')->assertSee('203.0.113.7')->assertSee('boss@example.test');
        Livewire::actingAs($operator, 'admin')->test(AuthEvents::class)
            ->assertCanSeeTableRecords(AuthEvent::all())
            ->filterTable('event', 'failed')
            ->assertCanSeeTableRecords(AuthEvent::where('event', 'failed')->get())
            ->assertCanNotSeeTableRecords(AuthEvent::where('event', 'login')->get());

        $this->flushSession();
        $this->actingAs($support, 'admin')->get('/admin/sign-in-activity')->assertForbidden();
    }

    public function test_the_hash_benchmark_reports_a_cost_for_this_cpu(): void
    {
        $this->artisan('auth:benchmark-hash', ['--target' => 100000, '--samples' => 1])
            ->expectsOutputToContain('Highest cost under 100000 ms here: 14')
            ->assertExitCode(0);
    }
}
