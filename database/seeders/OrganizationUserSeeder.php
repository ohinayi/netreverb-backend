<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrganizationUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'organization@example.com'],
            [
                'name' => 'Organization User',
                'account_type' => AccountType::Community,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'password' => 'password',
            ],

        );

        User::query()->updateOrCreate(
            ['email' => 'organization2@example.com'],
            [
                'name' => 'Second Organization User',
                'account_type' => AccountType::Community,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'password' => 'password',
            ]
        );
    }
}
