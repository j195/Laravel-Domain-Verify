<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@mailin.test'],
            [
                'name' => 'Mailin Admin',
                'password' => Hash::make('Mailin@Admin123'),
                'email_verified_at' => now(),
            ]
        );
    }
}
