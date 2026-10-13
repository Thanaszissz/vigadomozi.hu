<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TZ = 'Europe/Budapest';

    /**
     * Az októberi műsor (15–31.) felvétele. Csak hozzáad: a meglévő filmeket, vetítéseket, foglalásokat és jegyeket
     * nem módosítja és nem törli. Ugyanaz a film cím alapján, ugyanaz a vetítés (film + időpont) pedig ismétlődés
     * nélkül kerül be, ezért akárhányszor lefuttatható. A meglévő filmeknél csak az üresen hagyott mezőket tölti ki.
     */
    public function up(): void
    {
        $auditorium = DB::table('auditoria')->orderByRaw("CASE WHEN name = 'A terem' THEN 0 ELSE 1 END")->orderBy('id')->first();
        if (!$auditorium) {
            return;
        }

        $movieIds = [];
        foreach ($this->movies() as $key => $data) {
            $movieIds[$key] = $this->ensureMovie($data);
        }

        foreach ($this->showtimes() as [$movieKey, $localStart]) {
            $this->ensureShowtime($movieIds[$movieKey], $auditorium->id, Carbon::parse($localStart, self::TZ)->utc());
        }
    }

    public function down(): void
    {
        // Szándékosan üres: az adatfelvétel nem visszavonható anélkül, hogy a már kezelt foglalásokat ne érintené.
    }

    private function ensureMovie(array $data): int
    {
        $wanted = $this->normalize($data['title']);
        $existing = DB::table('movies')->get()->first(fn($m) => $this->normalize($m->title) === $wanted);

        $data['poster_path'] = rtrim((string) config('app.url'), '/') . '/posters/' . $data['poster_file'];
        unset($data['poster_file']);

        if (!$existing) {
            return DB::table('movies')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        }

        $fill = [];
        foreach (['description', 'poster_path', 'trailer_youtube_url', 'duration_min'] as $column) {
            if ($existing->{$column} === null || $existing->{$column} === '' || $existing->{$column} === 0) {
                $fill[$column] = $data[$column];
            }
        }
        if ($fill) {
            DB::table('movies')->where('id', $existing->id)->update($fill + ['updated_at' => now()]);
        }

        return (int) $existing->id;
    }

    private function ensureShowtime(int $movieId, int $auditoriumId, Carbon $startsAt): void
    {
        $from = $startsAt->copy()->subHour()->format('Y-m-d H:i:s');
        $to = $startsAt->copy()->addHour()->format('Y-m-d H:i:s');

        $sameMovie = DB::table('showtimes')->where('movie_id', $movieId)->whereBetween('starts_at', [$from, $to])->exists();
        if ($sameMovie) {
            return;
        }

        $taken = DB::table('showtimes')->where('auditorium_id', $auditoriumId)->where('starts_at', $startsAt->format('Y-m-d H:i:s'))->exists();
        if ($taken) {
            Log::warning('Műsor felvétele: az időpont már foglalt, a vetítés kimaradt', ['movie_id' => $movieId, 'starts_at' => $startsAt->toIso8601String()]);

            return;
        }

        DB::table('showtimes')->insert([
            'movie_id' => $movieId,
            'auditorium_id' => $auditoriumId,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'sales_open_at' => $startsAt->copy()->subDays(7)->startOfDay()->format('Y-m-d H:i:s'),
            'sales_close_at' => $startsAt->copy()->subMinutes(30)->format('Y-m-d H:i:s'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalize(string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($title)));
    }

    private function movies(): array
    {
        return [
            'kivansag' => [
                'title' => 'Még egy kívánság',
                'description' => 'Juli harmincéves zenei menedzser, aki szerint az élet folyamatosan keresztbe tesz neki. Egy kínos baki után utolsó esélyként egy széteső rockzenekart bíznak rá, ugyanazon az éjszakán pedig a kezébe kerül egy füzet: amit beleír, az eltűnik a világból. Magyar vígjáték.',
                'poster_file' => 'meg-egy-kivansag.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=ThGsC3DZL1Y',
                'duration_min' => 108,
                'rating' => '12',
            ],
            'digger' => [
                'title' => 'Digger',
                'description' => 'A Birdman rendezője, Alejandro G. Iñárritu Tom Cruise főszereplésével készítette legújabb fekete komédiáját, amelyben a világ legnagyobb hatalmú embere mindenáron be akarja bizonyítani, hogy ő a legnagyobb.',
                'poster_file' => 'digger.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=RQIUW81bg5g',
                'duration_min' => 129,
                'rating' => '16',
            ],
            'torrente' => [
                'title' => 'Torrente 6. – Az elnöki ügy',
                'description' => 'José Luis Torrente, az erkölcstelen exzsaru minden eddiginél nagyobb fába vágja a fejszéjét: úgy határoz, hogy ő lesz Spanyolország következő miniszterelnöke. Kampánykörútján természetesen szedett-vedett bandája is vele tart, de a politikában nagyon könnyű ellenségeket szerezni. Santiago Segura politikai szatírája a Torrente-sorozat hatodik része.',
                'poster_file' => 'torrente-6-az-elnoki-ugy.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=SO3Zv47bBYY',
                'duration_min' => 102,
                'rating' => '18',
            ],
            'verity' => [
                'title' => 'Verity',
                'description' => 'A féltékenység minden formájában gyilkos szenvedély. A sikeres író, Verity Crawford (Anne Hathaway) birtokára a kezdő Lowen Ashleigh (Dakota Johnson) költözik szellemírónak, és munka közben felfedez egy hátborzongató naplót. Amerikai krimi, thriller.',
                'poster_file' => 'verity.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=qS6sXWr1Ew4',
                'duration_min' => 117,
                'rating' => '16',
            ],
            'orokseg' => [
                'title' => 'Az örökség',
                'description' => 'Árpi bácsi évek óta egyedül él, és az összes megtakarított pénzét elköltötte. Amikor két fia, Attila és Kristóf rájön, hogy a pénz egy Hajnalka nevű fiatal lányra ment, és az öreg szerint szerelmes, kezdetét veszi életük leghosszabb éjszakája. Magyar thriller.',
                'poster_file' => 'az-orokseg.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=vBe0rS8Kh7g',
                'duration_min' => 89,
                'rating' => '16',
            ],
            'streetfighter' => [
                'title' => 'Street Fighter',
                'description' => '1993-ban járunk: a két elidegenedett utcai harcos, Ryu (Andrew Koji) és Ken Masters (Noah Centineo) újra visszaszáll a csatába, amikor a titokzatos Chun-Li (Callina Liang) beveszi őket a következő World Warrior versenybe. Amerikai akciófilm.',
                'poster_file' => 'street-fighter.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=d0OurRWxx5w',
                'duration_min' => 119,
                'rating' => '16',
            ],
            'masikmami' => [
                'title' => 'A másik mami',
                'description' => 'James Wan producertől és a Blumhouse Atomic Monstertől, Rob Savage rendezésében érkezik ez a félelmetes utazás egy fiatal lány, Bela egyre sötétebb világába. Bela az otthonában kapcsolatba kerül egy baljós entitással, aki a megszólalásig hasonlít a saját anyjára. Amerikai horror.',
                'poster_file' => 'a-masik-mami.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=h5uZqRY0G9Q',
                'duration_min' => 93,
                'rating' => '16',
            ],
            'heidi' => [
                'title' => 'Heidi és a kis hiúz',
                'description' => 'Heidi nagyapjával él egy hegyvidéki kunyhóban. Amikor egy sérült hiúzkölyköt talál, a szárnyai alá veszi és ápolni kezdi, de a kis hiúznak vissza kell térnie a családjához. Heidi és Peter magukra vállalják, hogy megmentik új barátjukat, miközben egy kapzsi üzletember fűrésztelepet akar építeni a hegyekben. Családi animációs film.',
                'poster_file' => 'heidi-es-a-kis-hiuz.webp',
                'trailer_youtube_url' => 'https://www.youtube.com/watch?v=YpcRhHoFZCo',
                'duration_min' => 79,
                'rating' => '6',
            ],
        ];
    }

    /** Helyi (budapesti) időpontok. */
    private function showtimes(): array
    {
        return [
            ['kivansag', '2026-10-15 18:00'],
            ['digger', '2026-10-16 18:30'],
            ['torrente', '2026-10-20 18:00'],
            ['verity', '2026-10-21 18:00'],
            ['kivansag', '2026-10-27 18:00'],
            ['orokseg', '2026-10-28 18:00'],
            ['streetfighter', '2026-10-29 18:30'],
            ['masikmami', '2026-10-30 18:00'],
            ['heidi', '2026-10-31 14:00'],
            ['kivansag', '2026-10-31 16:30'],
            ['streetfighter', '2026-10-31 19:00'],
        ];
    }
};
