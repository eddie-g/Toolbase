<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserPortal\Pages\Settings;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class UserEmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registration_sends_email_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'New User',
            'email' => 'new-user-'.uniqid().'@example.com',
            'password' => 'a-long-enough-password-2026',
            'password_confirmation' => 'a-long-enough-password-2026',
        ])->assertRedirect('/portal');

        $user = User::where('name', 'New User')->latest('id')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        if (Schema::hasColumn('users', 'credit_balance')) {
            $this->assertSame('0.0000', $user->credit_balance);
        }
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_users_are_sent_to_the_verification_prompt_from_the_portal(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/portal')
            ->assertRedirect('/email/verify');

        $this->actingAs($user)
            ->get('/email/verify')
            ->assertOk()
            ->assertSee('Confirm your email address');
    }

    public function test_verified_users_can_access_the_portal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/portal')
            ->assertOk()
            ->assertSee('Settings');
    }

    public function test_verified_users_can_access_filament_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/portal/settings')
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('Name')
            ->assertSee('Email address')
            ->assertSee('Password');

        $this->actingAs($user)->get('/portal/profile')->assertRedirect('/portal/settings');
    }

    public function test_users_can_update_email_from_filament_profile(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old-'.uniqid().'@example.com',
        ]);

        $newEmail = 'new-'.uniqid().'@example.com';

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('name', 'New Name')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->set('newEmail', $newEmail)
            ->set('emailPassword', 'password')
            ->call('changeEmail')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame($newEmail, $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_users_can_update_password_from_filament_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password')
            ->set('newPasswordConfirmation', 'new-password')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_users_can_delete_account_from_filament_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Settings::class)
            ->set('deletePassword', 'password')
            ->call('deleteAccount')
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }
}