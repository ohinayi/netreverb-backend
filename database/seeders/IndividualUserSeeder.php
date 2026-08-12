<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;

class IndividualUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'individual@example.com'],
            [
                'name' => 'Individual User',
                'account_type' => AccountType::Individual,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'password' => 'password',
            ],

        );

        User::query()->updateOrCreate(
            ['email' => 'individual2@example.com'],
            [
                'name' => 'Second Individual User',
                'account_type' => AccountType::Individual,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'password' => 'password',
            ]
        );

	User::query()->updateOrCreate(
            ['email' => 'individual4@example.com'],
            [
                'name' => 'Fourth Individual User',
                'account_type' => AccountType::Individual,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'password' => 'V7!qR2#nL9@xK4$z',
            ]
        );

    }
}
