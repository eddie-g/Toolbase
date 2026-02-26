<?php

namespace App\Filament\User\Pages;

use Filament\Pages\Auth\Login as FilamentLogin;

class Login extends FilamentLogin
{
    /**
     * Redirect unauthenticated users to Fortify's /login page
     * instead of showing a Filament login form.
     */
    public function mount(): void
    {
        if (auth()->guard('web')->check()) {
            $this->redirect(filament('user')->getUrl());
            return;
        }

        $this->redirect(route('login'));
    }
}
