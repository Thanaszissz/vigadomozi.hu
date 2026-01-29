<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@vigadomozi.hu'],
            [
                'name' => 'Admin',
                'password' => bcrypt('admin123'),
                'email_verified_at' => now(),
            ]
        );

        echo "\n✅ Admin user: admin@vigadomozi.hu / admin123\n";
    }
}
