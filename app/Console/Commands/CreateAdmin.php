<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature   = 'admin:create';
    protected $description = 'Create a new admin account';

    public function handle(): int
    {
        $this->info('Creating a new admin account.');

        $name = $this->ask('Name');
        $email = $this->ask('Email');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email|unique:admins,email']
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));
            return self::FAILURE;
        }

        $password = $this->secret('Password (min 12 characters)');

        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');
            return self::FAILURE;
        }

        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        Admin::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Admin account for [{$email}] created successfully.");

        return self::SUCCESS;
    }
}
