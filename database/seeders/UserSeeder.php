<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Modules\Auth\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            [
                'email' => 'ragab5434@gmail.com',
            ],
            [
                'name'      => 'System Administrator',
                'password'  => 'MoH.1822', // auto-hashed via cast
                'is_active' => true,
            ]
        );
    }
}
