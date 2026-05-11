<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'name' => 'Admin',
                'email' => 'operator@crowdease.test',
                'email_verified_at' => now(),
                'password' => Hash::make('secret123'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}