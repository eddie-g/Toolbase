<?php

namespace App\Auth;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Illuminate\Validation\ValidationException;

/**
 * An admin without a second factor lands here after signing in and cannot go
 * anywhere else in the panel until an authenticator app is set up.
 */
class AdminTwoFactorSetup extends SimplePage
{
    protected static string $view = 'filament.admin-two-factor.setup';

    use InteractsWithFormActions;

    public ?array $data = [];

    /** @var string[] shown once, right after enrolment */
    public array $recoveryCodes = [];

    public function mount(AdminTwoFactor $twoFactor): void
    {
        $admin = Filament::auth()->user();
        if ($twoFactor->isEnrolled($admin)) {
            $this->redirect($twoFactor->isVerified(request(), $admin) ? Filament::getUrl() : route('filament.admin.two-factor.challenge'));

            return;
        }
        if ($twoFactor->secret($admin) === null) {
            $twoFactor->beginEnrolment($admin);
        }
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return $this->recoveryCodes === [] ? 'Set up two-factor authentication' : 'Save your recovery codes';
    }

    public function getSubheading(): ?string
    {
        return $this->recoveryCodes === []
            ? 'Admin accounts need a second factor. Scan the code with an authenticator app, then enter the six digits it shows.'
            : 'Each code works once if you lose your phone. They are not shown again.';
    }

    public function qrCode(): string
    {
        return app(AdminTwoFactor::class)->qrCodeSvg(Filament::auth()->user());
    }

    public function manualKey(): string
    {
        return trim(chunk_split((string) app(AdminTwoFactor::class)->secret(Filament::auth()->user()), 4, ' '));
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            TextInput::make('code')->label('Six-digit code')->required()->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'numeric']),
        ]);
    }

    public function confirm(AdminTwoFactor $twoFactor): void
    {
        $admin = Filament::auth()->user();
        $codes = $twoFactor->confirmEnrolment($admin, (string) ($this->form->getState()['code'] ?? ''));
        if ($codes === null) {
            throw ValidationException::withMessages(['data.code' => 'That code is not valid. Check the time on your phone and try the next one.']);
        }

        $twoFactor->markVerified(request(), $admin);
        $this->recoveryCodes = $codes;
    }

    public function finish(): void
    {
        $this->redirect(Filament::getUrl());
    }

    /** @return Action[] */
    protected function getFormActions(): array
    {
        return [Action::make('confirm')->label('Turn on')->submit('confirm')];
    }
}
