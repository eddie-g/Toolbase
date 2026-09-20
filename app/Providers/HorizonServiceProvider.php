<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Horizon hands the gate the default guard's user, which is a web
        // visitor; operators sign in on the admin guard, so look there.
        Gate::define('viewHorizon', function ($user = null) {
            $admin = $user instanceof Admin ? $user : Auth::guard('admin')->user();

            if (! $admin instanceof Admin || ! $admin->isOperator()) {
                return false;
            }

            // A password alone does not open Horizon either (App\Auth\AdminTwoFactor).
            $twoFactor = app(\App\Auth\AdminTwoFactor::class);

            return ! $twoFactor->isRequired()
                || ($twoFactor->isEnrolled($admin) && $twoFactor->isVerified(request(), $admin));
        });
    }
}
