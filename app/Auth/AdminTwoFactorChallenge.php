<?php

namespace App\Auth;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The step between an admin's password and the panel: the code from their
 * authenticator app, or a recovery code. Five tries a minute per account.
 */
class AdminTwoFactorChallenge extends SimplePage
{
    protected static string $view = 'filament.admin-two-factor.challenge';

    use InteractsWithFormActions;

    public ?array $data = [];

    public function mount(AdminTwoFactor $twoFactor): void
    {
        $admin = Filament::auth()->user();
        if (! $twoFactor->isEnrolled($admin)) {
            $this->redirect(route('filament.admin.two-factor.setup'));

            return;
        }
        if ($twoFactor->isVerified(request(), $admin)) {
            $this->redirect(Filament::getUrl());

            return;
        }
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Two-factor authentication';
    }

    public function getSubheading(): ?string
    {
        return 'Enter the code from your authenticator app, or one of your recovery codes.';
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            TextInput::make('code')->label('Code')->required()->autofocus()->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'text']),
        ]);
    }

    public function verify(AdminTwoFactor $twoFactor): void
    {
        $admin = Filament::auth()->user();
        $key = 'admin-2fa:'.$admin->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['data.code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $code = (string) ($this->form->getState()['code'] ?? '');
        if (! $twoFactor->verify($admin, $code)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['data.code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);
        $twoFactor->markVerified(request(), $admin);
        session()->regenerate();
        $this->redirectIntended(Filament::getUrl());
    }

    /** @return Action[] */
    protected function getFormActions(): array
    {
        return [Action::make('verify')->label('Continue')->submit('verify')];
    }

    public function signOutUrl(): string
    {
        return Filament::getLogoutUrl();
    }
}
