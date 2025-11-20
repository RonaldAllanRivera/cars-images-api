<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class FilamentAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'jaeron.rivera@gmail.com'],
            [
                'name' => 'admin',
                'password' => '123456789',
            ],
        );
    }
}
