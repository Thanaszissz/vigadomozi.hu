<?php

namespace Database\Seeders;

use App\Models\Auditorium;
use App\Models\Movie;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demó műsor: 25 film és előadások a következő napokra (alapból 10 napra), éles környezetben is futtatható:
 *   php artisan db:seed --class=DemoProgramSeeder --force
 * Csak hozzáad: ugyanaz a film cím alapján, ugyanaz az előadás (film + időpont, illetve terem + időpont) pedig
 * ismétlődés nélkül kerül be, ezért akárhányszor lefuttatható (pl. a műsor továbbtolásához). A napok számát a
 * DEMO_DAYS környezeti változóval lehet módosítani.
 */
class DemoProgramSeeder extends Seeder
{
    private const TZ = 'Europe/Budapest';

    // Helyi kezdési időpontok; a hosszú és a 16/18-as filmek a későbbi sávokba kerülnek
    private const SLOTS = ['14:00', '16:30', '19:00', '21:30'];

    public function run(): void
    {
        $auditorium = Auditorium::firstOrCreate(
            ['name' => 'A terem'],
            ['rows' => 8, 'columns' => 10, 'total_seats' => 80]
        );

        $movies = [];
        foreach ($this->movies() as $data) {
            $movies[] = $this->ensureMovie($data);
        }

        $days = max(1, (int) env('DEMO_DAYS', 10));
        $created = $this->scheduleShowtimes($movies, $auditorium->id, $days);

        $this->command?->info(count($movies) . " film és {$created} új előadás a következő {$days} napra.");
    }

    private function ensureMovie(array $data): Movie
    {
        $wanted = $this->normalize($data['title']);
        $existing = Movie::all()->first(fn (Movie $m) => $this->normalize($m->title) === $wanted);

        if (isset($data['poster_file'])) {
            $data['poster_path'] = rtrim((string) config('app.url'), '/') . '/posters/' . $data['poster_file'];
            unset($data['poster_file']);
        }

        if (!$existing) {
            return Movie::create($data);
        }

        $fill = [];
        foreach (['description', 'poster_path', 'trailer_youtube_url', 'duration_min', 'film_info', 'badges'] as $column) {
            if (empty($existing->{$column}) && !empty($data[$column] ?? null)) {
                $fill[$column] = $data[$column];
            }
        }
        if ($fill) {
            $existing->update($fill);
        }

        return $existing;
    }

    /** @param Movie[] $movies */
    private function scheduleShowtimes(array $movies, int $auditoriumId, int $days): int
    {
        $created = 0;
        $shown = array_fill_keys(array_map(fn (Movie $m) => $m->id, $movies), 0);
        $today = Carbon::now(self::TZ)->startOfDay();

        for ($d = 0; $d < $days; $d++) {
            $date = $today->copy()->addDays($d);
            $usedToday = [];

            foreach (self::SLOTS as $slotIndex => $time) {
                $startsAt = Carbon::parse($date->format('Y-m-d') . ' ' . $time, self::TZ);
                if ($startsAt->isPast()) {
                    continue;
                }

                // a legkevesebbszer játszott, az adott sávba illő film jön (így mindegyik film sorra kerül)
                $movie = null;
                foreach ($movies as $candidate) {
                    if (isset($usedToday[$candidate->id]) || !$this->fitsSlot($candidate, $slotIndex)) {
                        continue;
                    }
                    if (!$movie || $shown[$candidate->id] < $shown[$movie->id]) {
                        $movie = $candidate;
                    }
                }
                if (!$movie) {
                    continue;
                }

                $usedToday[$movie->id] = true;
                $shown[$movie->id]++;
                $created += $this->ensureShowtime($movie->id, $auditoriumId, $startsAt->copy()->utc()) ? 1 : 0;
            }
        }

        return $created;
    }

    private function fitsSlot(Movie $movie, int $slotIndex): bool
    {
        $last = count(self::SLOTS) - 1;

        if ((int) $movie->duration_min > 150) {
            return $slotIndex === $last;
        }
        if (in_array($movie->rating, ['16', '18'], true)) {
            return $slotIndex >= $last - 1;
        }
        if (in_array($movie->rating, ['general', '6'], true)) {
            return $slotIndex <= 1;
        }

        return true;
    }

    private function ensureShowtime(int $movieId, int $auditoriumId, Carbon $startsAt): bool
    {
        $from = $startsAt->copy()->subHour()->format('Y-m-d H:i:s');
        $to = $startsAt->copy()->addHour()->format('Y-m-d H:i:s');

        $sameMovie = DB::table('showtimes')->where('movie_id', $movieId)->whereBetween('starts_at', [$from, $to])->exists();
        $taken = DB::table('showtimes')->where('auditorium_id', $auditoriumId)->where('starts_at', $startsAt->format('Y-m-d H:i:s'))->exists();
        if ($sameMovie || $taken) {
            return false;
        }

        DB::table('showtimes')->insert([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'sales_open_at' => $startsAt->copy()->subDays(10)->startOfDay()->format('Y-m-d H:i:s'),
            'sales_close_at' => $startsAt->copy()->subMinutes(30)->format('Y-m-d H:i:s'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function normalize(string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($title)));
    }

    private function movies(): array
    {
        $tmdb = 'https://image.tmdb.org/t/p/w500';

        return [
            [
                'title' => 'Még egy kívánság',
                'film_info' => 'magyar vígjáték, 108 perc, 2026',
                'badges' => ['premier', 'magyar'],
                'description' => 'Juli harmincéves zenei menedzser, aki szerint az élet folyamatosan keresztbe tesz neki. Egy kínos baki után utolsó esélyként egy széteső rockzenekart bíznak rá, ugyanazon az éjszakán pedig a kezébe kerül egy füzet: amit beleír, az eltűnik a világból.',
                'poster_file' => 'meg-egy-kivansag.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=ThGsC3DZL1Y',
                'duration_min' => 108,
                'rating' => '12',
            ],
            [
                'title' => 'Digger',
                'film_info' => 'amerikai fekete komédia, 129 perc, 2026',
                'badges' => ['premier', 'feliratos'],
                'description' => 'A Birdman rendezője, Alejandro G. Iñárritu Tom Cruise főszereplésével készítette legújabb fekete komédiáját, amelyben a világ legnagyobb hatalmú embere mindenáron be akarja bizonyítani, hogy ő a legnagyobb.',
                'poster_file' => 'digger.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=RQIUW81bg5g',
                'duration_min' => 129,
                'rating' => '16',
            ],
            [
                'title' => 'Torrente 6. – Az elnöki ügy',
                'film_info' => 'spanyol politikai szatíra, 102 perc, 2026',
                'description' => 'José Luis Torrente, az erkölcstelen exzsaru minden eddiginél nagyobb fába vágja a fejszéjét: úgy határoz, hogy ő lesz Spanyolország következő miniszterelnöke. Santiago Segura politikai szatírája a Torrente-sorozat hatodik része.',
                'poster_file' => 'torrente-6-az-elnoki-ugy.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=SO3Zv47bBYY',
                'duration_min' => 102,
                'rating' => '18',
            ],
            [
                'title' => 'Verity',
                'film_info' => 'amerikai krimi, thriller, 117 perc, 2026',
                'description' => 'A féltékenység minden formájában gyilkos szenvedély. A sikeres író, Verity Crawford (Anne Hathaway) birtokára a kezdő Lowen Ashleigh (Dakota Johnson) költözik szellemírónak, és munka közben felfedez egy hátborzongató naplót.',
                'poster_file' => 'verity.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=qS6sXWr1Ew4',
                'duration_min' => 117,
                'rating' => '16',
            ],
            [
                'title' => 'Az örökség',
                'film_info' => 'magyar thriller, 89 perc, 2026',
                'badges' => ['magyar'],
                'description' => 'Árpi bácsi évek óta egyedül él, és az összes megtakarított pénzét elköltötte. Amikor két fia rájön, hogy a pénz egy fiatal lányra ment, kezdetét veszi életük leghosszabb éjszakája.',
                'poster_file' => 'az-orokseg.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=vBe0rS8Kh7g',
                'duration_min' => 89,
                'rating' => '16',
            ],
            [
                'title' => 'Street Fighter',
                'film_info' => 'amerikai akciófilm, 119 perc, 2026',
                'badges' => ['premier'],
                'description' => '1993-ban járunk: a két elidegenedett utcai harcos, Ryu és Ken Masters újra visszaszáll a csatába, amikor a titokzatos Chun-Li beveszi őket a következő World Warrior versenybe.',
                'poster_file' => 'street-fighter.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=d0OurRWxx5w',
                'duration_min' => 119,
                'rating' => '16',
            ],
            [
                'title' => 'A másik mami',
                'film_info' => 'amerikai horror, 93 perc, 2026',
                'description' => 'James Wan producertől és a Blumhouse Atomic Monstertől, Rob Savage rendezésében érkezik ez a félelmetes utazás egy fiatal lány, Bela egyre sötétebb világába.',
                'poster_file' => 'a-masik-mami.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=h5uZqRY0G9Q',
                'duration_min' => 93,
                'rating' => '16',
            ],
            [
                'title' => 'Heidi és a kis hiúz',
                'film_info' => 'családi animációs film, 79 perc, 2026',
                'badges' => ['szinkronizalt'],
                'description' => 'Heidi nagyapjával él egy hegyvidéki kunyhóban. Amikor egy sérült hiúzkölyköt talál, a szárnyai alá veszi és ápolni kezdi, de a kis hiúznak vissza kell térnie a családjához.',
                'poster_file' => 'heidi-es-a-kis-hiuz.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=YpcRhHoFZCo',
                'duration_min' => 79,
                'rating' => '6',
            ],
            [
                'title' => 'The Social Reckoning – A hálózat ára',
                'film_info' => 'amerikai életrajzi dráma, 120 perc, 2026',
                'description' => 'Demóadat: a közösségi média árnyoldalait feltáró dráma.',
                'poster_file' => 'the-social-reckoning-a-halozat-ara.webp',
                'duration_min' => 120,
                'rating' => '12',
            ],
            [
                'title' => 'Prérifarkas visszavág',
                'film_info' => 'amerikai animációs vígjáték, 90 perc, 2026',
                'description' => 'Demóadat: az egész családnak szóló animációs vígjáték.',
                'poster_file' => 'prerifarkas-visszavag.webp',
                'duration_min' => 90,
                'rating' => 'general',
            ],
            [
                'title' => 'Mommy Blue',
                'film_info' => 'thriller, 98 perc, 2026',
                'description' => 'Demóadat: feszült hangulatú thriller.',
                'poster_file' => 'mommy-blue.webp',
                'duration_min' => 98,
                'rating' => '16',
            ],
            [
                'title' => 'Földszint',
                'film_info' => 'magyar dráma, 95 perc, 2026',
                'description' => 'Demóadat: mai magyar családi dráma.',
                'poster_file' => 'foldszint.webp',
                'duration_min' => 95,
                'rating' => '12',
            ],
            [
                'title' => 'Az elfelejtett sziget',
                'film_info' => 'kalandfilm, 105 perc, 2026',
                'description' => 'Demóadat: kalandos utazás egy rejtélyes szigetre.',
                'poster_file' => 'elfelejtett-sziget.webp',
                'duration_min' => 105,
                'rating' => '12',
            ],
            [
                'title' => 'Blue Lock: Csak egy maradhat',
                'film_info' => 'japán animációs sportfilm, 110 perc, 2026',
                'description' => 'Demóadat: a népszerű focis anime mozifilmes folytatása.',
                'poster_file' => 'blue-lock-csak-egy-maradhat.webp',
                'duration_min' => 110,
                'rating' => '12',
            ],
            [
                'title' => 'Balnahullás',
                'film_info' => 'dráma, 101 perc, 2026',
                'description' => 'Demóadat: felnőtteknek szóló dráma.',
                'poster_file' => 'balnahullas.webp',
                'duration_min' => 101,
                'rating' => '16',
            ],
            [
                'title' => 'A cinege titka',
                'film_info' => 'családi kalandfilm, 85 perc, 2026',
                'description' => 'Demóadat: családi kalandfilm gyerekeknek.',
                'poster_file' => 'a-cinege-titka.webp',
                'duration_min' => 85,
                'rating' => '6',
            ],
            [
                'title' => 'Dűne: Harmadik rész',
                'film_info' => 'amerikai sci-fi, 175 perc, 2026',
                'description' => 'Paul Atreides végső csatáját vívja az Arrakis bolygón, miközben a fremen szövetség sorsa is dől el.',
                'poster_path' => $tmdb . '/qWQsnEQrheQOnLTvWnWaPVV0EXd.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=Way9Dexny3w',
                'duration_min' => 175,
                'rating' => '12',
            ],
            [
                'title' => 'Avatar 3: A tűz és hamu',
                'film_info' => 'amerikai sci-fi kalandfilm, 195 perc, 2025',
                'description' => 'Jake Sully és családja új ellenséggel néz szembe a Pandora vulkanikus régiójában.',
                'poster_path' => $tmdb . '/t6HIqrRAclMPA5NQSG7gDVuOWO1.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=d9MyW72ELq0',
                'duration_min' => 195,
                'rating' => '12',
            ],
            [
                'title' => 'Mission: Impossible – The Final Reckoning',
                'film_info' => 'amerikai akciófilm, 169 perc, 2025',
                'description' => 'Ethan Hunt utolsó küldetése, ahol a világ sorsa a tét.',
                'poster_path' => $tmdb . '/z53D72EAOxGRqdr7KXXWp9dJiDe.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=fsQgc9pCyDU',
                'duration_min' => 169,
                'rating' => '16',
            ],
            [
                'title' => 'Superman',
                'film_info' => 'amerikai szuperhősfilm, 130 perc, 2025',
                'description' => 'James Gunn új DC univerzumának nyitófilmje, David Corenswet főszereplésével.',
                'poster_path' => $tmdb . '/ombsmhYUqR4qqOLOxAyr5V8hbyv.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=Ox8ZLF6cGM0',
                'duration_min' => 130,
                'rating' => '12',
            ],
            [
                'title' => 'Zootropolis 2',
                'film_info' => 'amerikai animációs vígjáték, 108 perc, 2025',
                'description' => 'Judy Hopps és Nick Wilde új rejtélyt oldanak meg az állatok városában.',
                'poster_path' => $tmdb . '/qY2DSLO2x8oFVS4cVjOYOWRMrZJ.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=cVwL3yZTOaA',
                'duration_min' => 108,
                'rating' => 'general',
            ],
            [
                'title' => 'Wicked: Második rész',
                'film_info' => 'amerikai musical, 145 perc, 2025',
                'description' => 'Az Óz, a csodák csodájának előzménytörténete folytatódik Cynthia Erivo és Ariana Grande főszereplésével.',
                'poster_path' => $tmdb . '/c5Tqxeo1UpBvnAc3csUm7j3hlQl.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=hL1USdLYajU',
                'duration_min' => 145,
                'rating' => 'general',
            ],
            [
                'title' => 'Tron: Ares',
                'film_info' => 'amerikai sci-fi, 119 perc, 2025',
                'description' => 'A Tron univerzum új fejezete, ahol egy mesterséges intelligenciát küldenek a valós világba.',
                'poster_path' => $tmdb . '/wbTRBhi7t1XIVRQXQ8t8kQNm9YZ.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=fL2NyPSL_RA',
                'duration_min' => 119,
                'rating' => '12',
            ],
            [
                'title' => 'Jurassic World: Újjászületés',
                'film_info' => 'amerikai kalandfilm, 134 perc, 2025',
                'description' => 'Egy új csapat indul veszélyes küldetésre az ősi DNS megszerzéséért.',
                'poster_path' => $tmdb . '/q0fGCmjLu42MPlSO9OYWpI3yYTI.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=jan3-2tHJxA',
                'duration_min' => 134,
                'rating' => '12',
            ],
            [
                'title' => 'Fantasztikus Négyes: Az első lépések',
                'film_info' => 'amerikai szuperhősfilm, 125 perc, 2025',
                'description' => 'A Marvel első családja megérkezik az MCU-ba egy retró-futurisztikus 1960-as években játszódó történetben.',
                'poster_path' => $tmdb . '/x26MtUlwtWD26d0G0FXcppxCJio.jpg',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=18QQWa5MEcs',
                'duration_min' => 125,
                'rating' => '12',
            ],
        ];
    }
}
