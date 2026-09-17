<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

/**
 * php artisan admin:role someone@example.com admin
 *
 * Grants or changes an admin account's role: owner, admin, support, or
 * "none" to lock the account out of the panel.
 */
class SetAdminRole extends Command
{
    protected $signature = 'admin:role {email} {role : owner, admin, support or none}';

    protected $description = 'Set the panel role of an admin account';

    public function handle(): int
    {
        $admin = Admin::where('email', $this->argument('email'))->first();
        if (! $admin) {
            $this->error('No admin with that email.');

            return self::FAILURE;
        }

        $role = strtolower((string) $this->argument('role'));
        if ($role === 'none') {
            $admin->forceFill(['role' => null])->save();
            $this->info("{$admin->email} can no longer sign in to the admin panel.");

            return self::SUCCESS;
        }

        if (! in_array($role, Admin::PANEL_ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', Admin::PANEL_ROLES).', none.');

            return self::FAILURE;
        }

        $admin->forceFill(['role' => $role])->save();
        $this->info("{$admin->email} is now {$role}.");

        return self::SUCCESS;
    }
}
