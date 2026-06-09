<?php

namespace App\UserPortal\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->getDeleteAccountAction(),
        ];
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