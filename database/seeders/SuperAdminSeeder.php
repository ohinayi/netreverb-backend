<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('superadmin.name');
        $email = config('superadmin.email');
        $password = config('superadmin.password');

        if (! is_string($name) || ! is_string($email) || ! is_string($password)
            || $name === '' || $email === '' || $password === '') {
            $this->command?->warn('Super Admin not seeded: set SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL, and SUPER_ADMIN_PASSWORD.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => mb_strtolower(trim($email))],
            [
                'name' => trim($name),
                'account_type' => AccountType::Community,
                'is_super_admin' => true,
                'password' => $password,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
            ],
        );
    }
}
