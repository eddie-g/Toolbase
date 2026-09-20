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

        // Horizon's own alerts (a queue waiting longer than config horizon.waits)
        // go to the operator address. Jobs that fail for good: AlertOnFailedJob.
        $alertEmail = (string) config('horizon.alert_email');
        if ($alertEmail !== '') {
            Horizon::routeMailNotificationsTo($alertEmail);
        }
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

            return $admin instanceof Admin && $admin->isOperator();
        });
    }
}
