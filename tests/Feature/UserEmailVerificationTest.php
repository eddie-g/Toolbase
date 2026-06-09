<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserPortal\Pages\Profile;
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
            'password' => 'password',
            'password_confirmation' => 'password',
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
            ->get('/portal/profile')
            ->assertOk()
            ->assertSee('Profile')
            ->assertSee('Name')
            ->assertSee('Email')
            ->assertSee('Password');
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
            ->test(Profile::class)
            ->fillForm([
                'name' => 'New Name',
                'email' => $newEmail,
            ])
            ->call('save')
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
            ->test(Profile::class)
            ->fillForm([
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'new-password',
                'passwordConfirmation' => 'new-password',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_users_can_delete_account_from_filament_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('user'));

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->callAction('deleteAccount', [
                'password' => 'password',
            ])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }
}