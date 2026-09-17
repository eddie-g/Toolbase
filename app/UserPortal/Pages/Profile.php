<?php

namespace App\UserPortal\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Profile extends EditProfile
{
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $record->email;

        if ($emailChanged && $record instanceof MustVerifyEmail) {
            $data['email_verified_at'] = null;
        }

        $record->forceFill($data)->save();

        if ($emailChanged && $record instanceof MustVerifyEmail) {
            $record->sendEmailVerificationNotification();
        }

        return $record;
    }

    protected function getSavedNotification(): ?Notification
    {
        $notification = parent::getSavedNotification();

        if (! $notification) {
            return null;
        }

        $user = $this->getUser();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $notification->body('Check your email address for a verification link.');
        }

        return $notification;
    }

    /**
     * Changing the email address or the password asks for the current
     * password first, so a stolen session cannot take the account over.
     */
    public function form(Form $form): Form
    {
        $needsPassword = fn (Get $get): bool => filled($get('password'))
            || (string) $get('email') !== (string) $this->getUser()->email;

        return $form->schema([
            ...$form->getComponents(withHidden: true),
            TextInput::make('current_password')
                ->label('Current password')
                ->password()
                ->revealable()
                ->autocomplete('current-password')
                ->dehydrated(false)
                ->visible($needsPassword)
                ->required($needsPassword)
                ->rule('current_password:web', $needsPassword)
                ->helperText('Needed to change your email address or password.'),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->getLogoutOtherSessionsAction(),
            $this->getDeleteAccountAction(),
        ];
    }

    /**
     * Re-hashes the password (same value, new salt) and cycles the remember
     * token; AuthenticateSession then signs every other session out on its
     * next request, and database-backed sessions are removed outright.
     */
    protected function getLogoutOtherSessionsAction(): Action
    {
        return Action::make('logoutOtherSessions')
            ->label('Log out other devices')
            ->color('gray')
            ->icon('heroicon-o-device-phone-mobile')
            ->requiresConfirmation()
            ->modalHeading('Log out other browser sessions')
            ->modalDescription('Every other browser or device signed in to your account will be logged out. This one stays signed in.')
            ->modalSubmitActionLabel('Log out other devices')
            ->form([
                TextInput::make('password')
                    ->label('Current password')
                    ->password()
                    ->required()
                    ->rule('current_password:web'),
            ])
            ->action(function (array $data): void {
                $user = $this->getUser();

                Auth::guard('web')->logoutOtherDevices((string) $data['password']);

                if (config('session.driver') === 'database' && request()->hasSession()) {
                    DB::table(config('session.table', 'sessions'))
                        ->where('user_id', $user->getAuthIdentifier())
                        ->where('id', '!=', request()->session()->getId())
                        ->delete();
                }

                Notification::make()->title('Other devices logged out')->success()->send();
            });
    }

    protected function getDeleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->label('Delete account')
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('Delete account')
            ->modalDescription('This will permanently delete your account and sign you out of the portal.')
            ->modalSubmitActionLabel('Delete account')
            ->form([
                TextInput::make('password')
                    ->label('Current password')
                    ->password()
                    ->required(),
            ])
            ->action(function (array $data): mixed {
                $user = $this->getUser();

                if (! Hash::check((string) $data['password'], (string) $user->password)) {
                    throw ValidationException::withMessages([
                        'password' => __('The provided password does not match your current password.'),
                    ]);
                }

                Auth::guard('web')->logout();

                $user->delete();

                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }

                return redirect('/')->with('status', 'Account deleted.');
            });
    }
}