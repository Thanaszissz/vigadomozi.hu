<?php

namespace Database\Seeders;

use App\Models\Movie;
use Illuminate\Database\Seeder;

class FreshMoviesSeeder extends Seeder
{
    public function run(): void
    {
        $movies = [
            [
                'title' => 'Dűne: Harmadik rész',
                'description' => 'Paul Atreides végső csatáját vívja az Arrakis bolygón, miközben a fremen szövetség sorsa is dől el.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/qWQsnEQrheQOnLTvWnWaPVV0EXd.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=Way9Dexny3w',
                'duration_min' => 175,
                'rating' => '12',
            ],
            [
                'title' => 'Avatar 3: A tűz és hamu',
                'description' => 'Jake Sully és családja új ellenséggel néz szembe a Pandora vulkanikus régiójában.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/t6HIqrRAclMPA5NQSG7gDVuOWO1.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=d9MyW72ELq0',
                'duration_min' => 195,
                'rating' => '12',
            ],
            [
                'title' => 'Mission: Impossible – The Final Reckoning',
                'description' => 'Ethan Hunt utolsó küldetése, ahol a világ sorsa a tét.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/z53D72EAOxGRqdr7KXXWp9dJiDe.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=fsQgc9pCyDU',
                'duration_min' => 169,
                'rating' => '16',
            ],
            [
                'title' => 'Superman',
                'description' => 'James Gunn új DC univerzumának nyitófilmje, David Corenswet főszereplésével.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/ombsmhYUqR4qqOLOxAyr5V8hbyv.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=Ox8ZLF6cGM0',
                'duration_min' => 130,
                'rating' => '12',
            ],
            [
                'title' => 'Bosszúállók: Doomsday',
                'description' => 'A Marvel Cinematic Universe új korszaka kezdődik Robert Downey Jr.-ral, mint Doctor Doom.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/xRvRzxiiHhgUErl0yf9w8WariRE.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=fDqzm5GQbVc',
                'duration_min' => 150,
                'rating' => '12',
            ],
            [
                'title' => 'Zootropolis 2',
                'description' => 'Judy Hopps és Nick Wilde új rejtélyt oldanak meg az állatok városában.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/qY2DSLO2x8oFVS4cVjOYOWRMrZJ.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=cVwL3yZTOaA',
                'duration_min' => 108,
                'rating' => 'general',
            ],
            [
                'title' => 'Wicked: Második rész',
                'description' => 'Az Óz, a csodák csodájának előzménytörténete folytatódik Cynthia Erivo és Ariana Grande főszereplésével.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/c5Tqxeo1UpBvnAc3csUm7j3hlQl.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=hL1USdLYajU',
                'duration_min' => 145,
                'rating' => 'general',
            ],
            [
                'title' => 'Tron: Ares',
                'description' => 'A Tron univerzum új fejezete, ahol egy mesterséges intelligenciát küldenek a valós világba.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/wbTRBhi7t1XIVRQXQ8t8kQNm9YZ.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=fL2NyPSL_RA',
                'duration_min' => 119,
                'rating' => '12',
            ],
            [
                'title' => 'Jurassic World: Újjászületés',
                'description' => 'Egy új csapat indul veszélyes küldetésre az ősi DNS megszerzéséért.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/q0fGCmjLu42MPlSO9OYWpI3yYTI.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=jan3-2tHJxA',
                'duration_min' => 134,
                'rating' => '12',
            ],
            [
                'title' => 'Fantasztikus Négyes: Az első lépések',
                'description' => 'A Marvel első családja megérkezik az MCU-ba egy retró-futurisztikus 1960-as években játszódó történetben.',
                'poster_path' => 'https://image.tmdb.org/t/p/w500/x26MtUlwtWD26d0G0FXcppxCJio.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=18QQWa5MEcs',
                'duration_min' => 125,
                'rating' => '12',
            ],
        ];

        foreach ($movies as $data) {
            Movie::firstOrCreate(
                ['title' => $data['title']],
                $data
            );
        }
    }
}
