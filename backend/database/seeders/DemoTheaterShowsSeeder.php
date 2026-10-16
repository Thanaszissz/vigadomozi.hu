<?php

namespace Database\Seeders;

use App\Models\TheaterShow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demó előadások és programok (színház, bábszínház, koncert, tánc, kiállítás), éles környezetben is futtatható:
 *   php artisan db:seed --class=DemoTheaterShowsSeeder --force
 * Csak hozzáad: ugyanaz a cím mellé nem kerül másik, a meglévő rekordoknál csak az üresen hagyott mezők töltődnek ki.
 */
class DemoTheaterShowsSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;

        foreach ($this->shows() as $data) {
            if (isset($data['poster_file'])) {
                $data['poster_path'] = rtrim((string) config('app.url'), '/') . '/posters/' . $data['poster_file'];
                unset($data['poster_file']);
            }

            $wanted = $this->normalize($data['title']);
            $existing = TheaterShow::all()->first(fn (TheaterShow $s) => $this->normalize($s->title) === $wanted);

            if (!$existing) {
                TheaterShow::create($data);
                $created++;
                continue;
            }

            $fill = [];
            foreach (['category', 'performer', 'venue', 'show_info', 'description', 'duration_min', 'badges', 'poster_path'] as $column) {
                if (empty($existing->{$column}) && !empty($data[$column] ?? null)) {
                    $fill[$column] = $data[$column];
                }
            }
            if ($fill) {
                $existing->update($fill);
            }
        }

        $this->command?->info("{$created} új demó előadás / program.");
    }

    private function normalize(string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($title)));
    }

    private function shows(): array
    {
        return [
            [
                'title' => 'Tavaszi vígjátékest',
                'category' => 'szinhaz',
                'performer' => 'Vendég színtársulat',
                'venue' => 'Vigadó nagyterem',
                'show_info' => 'vígjáték két részben, 120 perc',
                'description' => 'Demóadat: könnyed, humoros színházi est két felvonásban, az egész család szórakoztatására.',
                'duration_min' => 120,
                'poster_file' => 'tavaszi-vigjatekest.webp',
                'badges' => ['premier', 'vendegjatek'],
            ],
            [
                'title' => 'Bábszínház: Az aranyszőrű bárány',
                'category' => 'gyerek',
                'performer' => 'Vándor Bábszínház',
                'venue' => 'Vigadó nagyterem',
                'show_info' => 'mesejáték bábokkal, 55 perc',
                'description' => 'Demóadat: népmesei bábjáték a legkisebbeknek, dalokkal és közös játékkal.',
                'duration_min' => 55,
                'poster_file' => 'babszinhaz-aranyszoru-barany.webp',
                'badges' => ['gyerekeknek'],
            ],
            [
                'title' => 'Őszi hangversenyest',
                'category' => 'koncert',
                'performer' => 'Városi fúvószenekar',
                'venue' => 'Vigadó nagyterem',
                'show_info' => 'komolyzenei és könnyűzenei hangverseny, 90 perc',
                'description' => 'Demóadat: ismert dallamok koncertátiratokban, szünettel.',
                'duration_min' => 90,
                'poster_file' => 'oszi-hangversenyest.webp',
                'badges' => ['ingyenes'],
            ],
            [
                'title' => 'Népi táncház és bemutató',
                'category' => 'tanc',
                'performer' => 'Helyi néptáncegyüttes',
                'venue' => 'Vigadó előcsarnok',
                'show_info' => 'néptáncbemutató táncházzal, 100 perc',
                'description' => 'Demóadat: hagyományőrző néptáncbemutató, utána táncház kezdőknek is.',
                'duration_min' => 100,
                'poster_file' => 'nepi-tanchaz.webp',
                'badges' => ['ingyenes', 'gyerekeknek'],
            ],
            [
                'title' => 'Mikulás-műsor',
                'category' => 'gyerek',
                'performer' => 'Művelődési Központ gyerekcsoportja',
                'venue' => 'Vigadó nagyterem',
                'show_info' => 'családi ünnepi műsor, 60 perc',
                'description' => 'Demóadat: téli hangulatú műsor versekkel, dalokkal és ajándékosztással.',
                'duration_min' => 60,
                'poster_file' => 'mikulas-musor.webp',
                'badges' => ['gyerekeknek'],
            ],
            [
                'title' => 'Drámai monodráma-est',
                'category' => 'szinhaz',
                'performer' => 'Vendég színművész',
                'venue' => 'Vigadó kamaraterem',
                'show_info' => 'monodráma, 75 perc',
                'description' => 'Demóadat: egyszereplős, felnőtteknek szóló drámai előadás.',
                'duration_min' => 75,
                'poster_file' => 'dramai-monodrama-est.webp',
                'badges' => ['premier'],
            ],
            [
                'title' => 'Helytörténeti kiállítás megnyitó',
                'category' => 'kiallitas',
                'performer' => 'Városi Múzeum',
                'venue' => 'Vigadó galéria',
                'show_info' => 'kiállításmegnyitó, 45 perc',
                'description' => 'Demóadat: a város múltját bemutató tárlat ünnepélyes megnyitója.',
                'duration_min' => 45,
                'poster_file' => 'helytorteneti-kiallitas.webp',
                'badges' => ['ingyenes'],
            ],
            [
                'title' => 'Szilveszteri operett-gála',
                'category' => 'koncert',
                'performer' => 'Vendég operett-társulat',
                'venue' => 'Vigadó nagyterem',
                'show_info' => 'operettgála, 110 perc',
                'description' => 'Demóadat: ismert operettslágerek ünnepi hangulatban.',
                'duration_min' => 110,
                'poster_file' => 'szilveszteri-operett-gala.webp',
                'badges' => ['premier', 'vendegjatek'],
            ],
        ];
    }
}
