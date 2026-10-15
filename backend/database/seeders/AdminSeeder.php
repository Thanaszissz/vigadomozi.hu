<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Az első adminisztrátor létrehozása. A jelszót nem tároljuk a kódban:
     * ADMIN_EMAIL / ADMIN_PASSWORD környezeti változóból jön, különben egyszer, véletlenszerűen generálódik és itt kiíródik.
     */
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@vigadojegy.hu');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("A(z) {$email} felhasználó már létezik, a jelszava nem módosult.");

            return;
        }

        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20);
        }

        User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Adminisztrátor létrehozva: {$email}");
        if ($generated) {
            $this->command?->warn("Generált jelszó (csak most látható, jelentkezés után cserélje le): {$password}");
        }
    }
}
