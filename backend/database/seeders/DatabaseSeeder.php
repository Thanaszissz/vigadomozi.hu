<?php

namespace Database\Seeders;

use App\Models\Auditorium;
use App\Models\Movie;
use App\Models\Showtime;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin felhasználó létrehozása
        User::create([
            'name' => 'Admin',
            'email' => 'admin@vigadomozi.hu',
            'password' => bcrypt('admin123'),
        ]);

        // Terem létrehozása
        $auditorium = Auditorium::create([
            'name' => 'A terem',
            'rows' => 8,
            'columns' => 10,
            'total_seats' => 80,
        ]);

        // Filmek létrehozása
        $movie1 = Movie::create([
            'title' => 'Inception',
            'description' => 'A thief who steals corporate secrets through the use of dream-sharing technology.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=YoHD_XwstMw',
            'duration_min' => 148,
        ]);

        $movie2 = Movie::create([
            'title' => 'The Dark Knight',
            'description' => 'When the menace known as the Joker wreaks havoc on Gotham, Batman must accept one of the greatest tests.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/qJ2tW6WMUDux911r6m7haRef0WH.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=EXeTwQWrcwY',
            'duration_min' => 152,
        ]);

        $movie3 = Movie::create([
            'title' => 'Interstellar',
            'description' => 'A team of astronauts travel through a wormhole in space in an attempt to ensure humanity\'s survival.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=zSID6AWsSLE',
            'duration_min' => 169,
        ]);

        Movie::create([
            'title' => 'Oppenheimer',
            'description' => 'A biográfiai thriller, amely J. Robert Oppenheimer amerikai elméleti fizikus életét mutatja be.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/8Gxv8gSrzuNorVQRQ7izc52TwkP.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=uYPbbksJxIg',
            'duration_min' => 180,
            'rating' => '12',
        ]);

        Movie::create([
            'title' => 'Dűne - Második rész',
            'description' => 'Paul Atreides egyesíti erőit Chani-val és a fremenekkel, hogy bosszút álljon összeesküvőin.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/qWQsnEQrheQOnLTvWnWaPVV0EXd.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=Way9Dexny3w',
            'duration_min' => 166,
            'rating' => '12',
        ]);

        Movie::create([
            'title' => 'Barbie',
            'description' => 'Barbie és Ken az Én-túli világból az igazi világba látogatnak, hogy felfedezzék az életet.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/iuFNMS8U5cb6xn0FQVLiDJ9A3F8.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=pBk4NYhWNMM',
            'duration_min' => 114,
            'rating' => 'general',
        ]);

        Movie::create([
            'title' => 'Pókember - Nincs hazaút',
            'description' => 'Peter Parker identitását felfedték, és nem tudja szétválasztani szuperhős-életét barátaitól.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/1g0dhYtq4Cm9sqrt593On7IZO4.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=JfVOs4VSpmA',
            'duration_min' => 148,
            'rating' => '12',
        ]);

        Movie::create([
            'title' => 'Avatar - A víz útja',
            'description' => 'Jake Sully családjával együtt a Pandora vizeibe menekül egy új fenyegetés elől.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMPA5NQSG7gDVuOWO1.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=d9MyW72ELq0',
            'duration_min' => 192,
            'rating' => '12',
        ]);

        Movie::create([
            'title' => 'Joker',
            'description' => 'Arthur Fleck álma, hogy stand-up komikus legyen, de a világ őt viccnek tartja.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/udDclJDGlAvV37Cm59dNE8Z9yZQ.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=zAGVQLHvwOY',
            'duration_min' => 122,
            'rating' => '16',
        ]);

        Movie::create([
            'title' => 'A hang mögött',
            'description' => 'Egy család küzd, hogy túléljen egy világban, ahol a hangokra vadászó lények támadnak.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/hU0E130hsqBabxPWuVubk0FSVuA.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=WR7cc5t7tv8',
            'duration_min' => 90,
            'rating' => '16',
        ]);

        Movie::create([
            'title' => 'Top Gun - Maverick',
            'description' => 'Pete Mitchell több mint harminc év után még mindig szolgál, és egy életre szóló feladatot kap.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/lcq8dVxeeOqHvvgcte707K0KVx5.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=giXco2jaZ_4',
            'duration_min' => 131,
            'rating' => '12',
        ]);

        Movie::create([
            'title' => 'Agymanók 2',
            'description' => 'Riley tinédzser lett, és új érzelmek költöznek be az elméjébe.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/dhmeQcyl63YRtalsMaA6MaCeOI8.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=LEjhY15eCx0',
            'duration_min' => 96,
            'rating' => 'general',
        ]);

        Movie::create([
            'title' => 'Deadpool és Rozsomák',
            'description' => 'Wade Wilson találkozik Logannel egy multiverzális kalandban.',
            'poster_path' => 'https://image.tmdb.org/t/p/w500/8xV65gXuIWxWTFB7VJT9hj5ppEd.jpg',
            'trailer_youtube_url' => 'https://www.youtube.com/watch?v=73_1biulkYk',
            'duration_min' => 128,
            'rating' => '18',
        ]);

        // Előadások létrehozása (ma és következő napok)
        $today = now()->startOfDay();
        
        Showtime::create([
            'movie_id' => $movie1->id,
            'auditorium_id' => $auditorium->id,
            'starts_at' => $today->clone()->setHour(19)->setMinute(0),
            'sales_open_at' => $today->clone()->subDays(7),
            'sales_close_at' => $today->clone()->setHour(18)->setMinute(30),
            'status' => 'active',
        ]);

        Showtime::create([
            'movie_id' => $movie1->id,
            'auditorium_id' => $auditorium->id,
            'starts_at' => $today->clone()->setHour(21)->setMinute(30),
            'sales_open_at' => $today->clone()->subDays(7),
            'sales_close_at' => $today->clone()->setHour(21)->setMinute(0),
            'status' => 'active',
        ]);

        Showtime::create([
            'movie_id' => $movie2->id,
            'auditorium_id' => $auditorium->id,
            'starts_at' => $today->clone()->addDays(1)->setHour(19)->setMinute(0),
            'sales_open_at' => $today->clone()->subDays(7),
            'sales_close_at' => $today->clone()->addDays(1)->setHour(18)->setMinute(30),
            'status' => 'active',
        ]);

        Showtime::create([
            'movie_id' => $movie3->id,
            'auditorium_id' => $auditorium->id,
            'starts_at' => $today->clone()->addDays(2)->setHour(19)->setMinute(0),
            'sales_open_at' => $today->clone()->subDays(7),
            'sales_close_at' => $today->clone()->addDays(2)->setHour(18)->setMinute(30),
            'status' => 'active',
        ]);
    }
}
