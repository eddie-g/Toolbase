<?php

namespace App\UserPortal\Pages;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

/**
 * Account settings for portal users: name, email, password, timezone, other
 * sessions and account deletion. Each section is its own small form so a
 * mistake in one never blocks another; the markup is the portal's nk-* theme
 * rather than a Filament form.
 */
class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $activeNavigationIcon = 'heroicon-s-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'user-portal.pages.settings';

    public string $name = '';

    public string $newEmail = '';

    public string $emailPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $timezone = '';

    public string $logoutPassword = '';

    public string $deletePassword = '';

    public function mount(): void
    {
        $user = $this->user();

        $this->name = (string) $user->name;
        $this->timezone = $user->displayTimezone();
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->user()->forceFill(['name' => trim($this->name)])->save();

        Notification::make()->title('Name saved')->success()->send();
    }

    /**
     * A new address is unverified until its link is clicked; the current
     * password is required so a stolen session cannot take the account over.
     */
    public function changeEmail(): void
    {
        $user = $this->user();

        $this->validate([
            'newEmail' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::notIn([$user->email]),
            ],
            'emailPassword' => ['required', 'current_password:web'],
        ], [
            'newEmail.not_in' => 'That is already your email address.',
            'emailPassword.current_password' => 'The password does not match your current password.',
        ]);

        $user->forceFill([
            'email' => strtolower(trim($this->newEmail)),
            'email_verified_at' => null,
        ])->save();

        if ($user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        $this->reset('newEmail', 'emailPassword');

        Notification::make()
            ->title('Email address changed')
            ->body('Check your new inbox for a verification link.')
            ->success()
            ->send();
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password:web'],
            'newPassword' => ['required', 'string', Password::defaults(), 'same:newPasswordConfirmation'],
            'newPasswordConfirmation' => ['required'],
        ], [
            'currentPassword.current_password' => 'The password does not match your current password.',
            'newPassword.same' => 'The new password and its confirmation do not match.',
        ]);

        $this->user()->forceFill(['password' => Hash::make($this->newPassword)])->save();

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        Notification::make()->title('Password changed')->success()->send();
    }

    public function saveTimezone(): void
    {
        $this->validate([
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ], [
            'timezone.in' => 'Pick a timezone from the list.',
        ]);

        $this->user()->forceFill(['timezone' => $this->timezone])->save();

        Notification::make()->title('Timezone saved')->success()->send();
    }

    /**
     * Re-hashes the password (same value, new salt) and cycles the remember
     * token; AuthenticateSession then signs every other session out on its
     * next request, and database-backed sessions are removed outright.
     */
    public function logoutOtherDevices(): void
    {
        $this->validate([
            'logoutPassword' => ['required', 'current_password:web'],
        ], [
            'logoutPassword.current_password' => 'The password does not match your current password.',
        ]);

        $user = $this->user();

        Auth::guard('web')->logoutOtherDevices($this->logoutPassword);

        if (config('session.driver') === 'database' && request()->hasSession()) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', request()->session()->getId())
                ->delete();
        }

        $this->reset('logoutPassword');

        Notification::make()->title('Other devices logged out')->success()->send();
    }

    public function deleteAccount(): mixed
    {
        $this->validate([
            'deletePassword' => ['required', 'current_password:web'],
        ], [
            'deletePassword.current_password' => 'The password does not match your current password.',
        ]);

        $user = $this->user();

        // A recurring plan would keep billing a deleted account otherwise.
        // Stripe failing here is logged, not fatal: the row goes with the user.
        foreach ($user->subscriptions()->current()->whereNotNull('stripe_subscription_id')->get() as $subscription) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                StripeSubscription::retrieve($subscription->stripe_subscription_id)->cancel();
            } catch (\Throwable $e) {
                Log::error('Account deletion: Stripe subscription cancel failed', [
                    'user_id' => $user->id,
                    'stripe_subscription_id' => $subscription->stripe_subscription_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Auth::guard('web')->logout();

        $user->delete();

        if (request()->hasSession()) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return redirect('/')->with('status', 'Account deleted.');
    }

    /**
     * IANA zones grouped by region for the select.
     *
     * @return array<string, list<string>>
     */
    public function timezoneGroups(): array
    {
        $groups = [];
        foreach (timezone_identifiers_list() as $zone) {
            $region = str_contains($zone, '/') ? strstr($zone, '/', true) : 'Other';
            $groups[$region][] = $zone;
        }
        ksort($groups);

        return $groups;
    }

    public function getViewData(): array
    {
        $user = $this->user();

        return [
            'email' => $user->email,
            'emailVerified' => $user instanceof MustVerifyEmail ? $user->hasVerifiedEmail() : true,
            'timezoneGroups' => $this->timezoneGroups(),
            'now' => now()->timezone($this->user()->displayTimezone()),
            'lastLogin' => $user->last_login_at?->timezone($user->displayTimezone()),
            'memberSince' => $user->created_at?->timezone($user->displayTimezone()),
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
