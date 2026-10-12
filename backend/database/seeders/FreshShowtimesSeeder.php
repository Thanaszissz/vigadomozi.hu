<?php

namespace Database\Seeders;

use App\Models\Auditorium;
use App\Models\Movie;
use App\Models\Showtime;
use Illuminate\Database\Seeder;

class FreshShowtimesSeeder extends Seeder
{
    public function run(): void
    {
        $auditorium = Auditorium::first();
        if (!$auditorium) {
            $this->command->warn('Nincs auditorium - előbb futtass DatabaseSeedert.');
            return;
        }

        $titles = [
            'Dűne: Harmadik rész',
            'Avatar 3: A tűz és hamu',
            'Mission: Impossible – The Final Reckoning',
            'Superman',
            'Bosszúállók: Doomsday',
            'Zootropolis 2',
            'Wicked: Második rész',
            'Tron: Ares',
            'Jurassic World: Újjászületés',
            'Fantasztikus Négyes: Az első lépések',
        ];

        // Időpontok mintája (óra)
        $timeSlots = [16, 18, 20, 22];
        $today = now()->startOfDay();

        foreach ($titles as $i => $title) {
            $movie = Movie::where('title', $title)->first();
            if (!$movie) {
                continue;
            }

            // Minden filmhez 3 előadás: ma + 1 nap, +2 nap, +3 nap, különböző időpontokban
            for ($d = 0; $d < 3; $d++) {
                $dayOffset = $i % 5 + $d; // 0..6 közti eltolás
                $hour = $timeSlots[($i + $d) % count($timeSlots)];
                $startsAt = $today->clone()->addDays($dayOffset)->setHour($hour)->setMinute(0);

                Showtime::firstOrCreate(
                    [
                        'movie_id' => $movie->id,
                        'auditorium_id' => $auditorium->id,
                        'starts_at' => $startsAt,
                    ],
                    [
                        'sales_open_at' => $today->clone()->subDays(7),
                        'sales_close_at' => $startsAt->clone()->subMinutes(30),
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
