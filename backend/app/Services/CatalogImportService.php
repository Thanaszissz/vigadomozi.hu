<?php

namespace App\Services;

use App\Models\Auditorium;
use App\Models\Movie;
use App\Models\Showtime;
use App\Models\TheaterShow;
use App\Support\MovieBadges;
use App\Support\ShowOptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * JSON-ból történő tömeges feltöltés: először a filmek vagy előadások / programok (plakáttal, WebP-re alakítva),
 * utána a hozzájuk tartozó előadás-időpontok.
 */
class CatalogImportService
{
    public const MAX_ITEMS = 200;
    public const MAX_SHOWTIMES = 2000;
    public const LOCAL_TIMEZONE = 'Europe/Budapest';

    public const TYPE_MOVIE = 'movie';
    public const TYPE_SHOW = 'show';

    /** @var array<string, Auditorium> */
    private array $auditoriaByName = [];
    /** @var array<int, Auditorium> */
    private array $auditoriaById = [];

    private string $type = self::TYPE_MOVIE;

    public function __construct(private WebpImageService $images)
    {
    }

    /** @return class-string<Movie|TheaterShow> */
    private function modelClass(): string
    {
        return $this->type === self::TYPE_SHOW ? TheaterShow::class : Movie::class;
    }

    /** A showtimes tábla külső kulcsa. */
    private function foreignKey(): string
    {
        return $this->type === self::TYPE_SHOW ? 'theater_show_id' : 'movie_id';
    }

    /** Hogyan hívjuk a tételt a hibaüzenetekben. */
    private function noun(): string
    {
        return $this->type === self::TYPE_SHOW ? 'előadás / program' : 'film';
    }

    /**
     * @param  mixed  $payload  tétel-tömb, {"movies": [...]} / {"shows": [...]} objektum vagy egyetlen tétel
     * @param  string  $type  'movie' (film) vagy 'show' (előadás / program)
     * @return array<string, mixed>
     */
    public function run(mixed $payload, bool $dryRun, bool $updateExisting, string $type = self::TYPE_MOVIE): array
    {
        $this->type = $type === self::TYPE_SHOW ? self::TYPE_SHOW : self::TYPE_MOVIE;
        $items = $this->normalize($payload);

        foreach (Auditorium::all() as $auditorium) {
            $this->auditoriaById[$auditorium->id] = $auditorium;
            $this->auditoriaByName[mb_strtolower(trim($auditorium->name))] = $auditorium;
        }

        $summary = [
            'items_created' => 0, 'items_updated' => 0, 'items_failed' => 0,
            'posters_converted' => 0, 'poster_warnings' => 0,
            'showtimes_created' => 0, 'showtimes_skipped' => 0, 'showtimes_failed' => 0,
        ];
        $results = [];
        $saved = [];

        // 1. lépés: a filmek / előadások
        foreach ($items as $index => $raw) {
            $result = ['index' => $index, 'title' => is_array($raw) ? (string) ($raw['title'] ?? '') : '', 'status' => 'failed', 'id' => null, 'errors' => [], 'poster' => null, 'showtimes' => []];

            try {
                if (!is_array($raw)) {
                    throw new InvalidArgumentException('A tételnek objektumnak kell lennie.');
                }
                [$item, $result] = $this->importItem($raw, $result, $dryRun, $updateExisting, $summary);
                $saved[$index] = $item;
            } catch (InvalidArgumentException $e) {
                $result['errors'][] = $e->getMessage();
                $summary['items_failed']++;
            } catch (Throwable $e) {
                report($e);
                $result['errors'][] = 'Váratlan hiba: ' . $e->getMessage();
                $summary['items_failed']++;
            }

            $results[$index] = $result;
        }

        // 2. lépés: a hozzájuk tartozó előadás-időpontok (csak a sikeresen felvett tételekéi)
        $showtimeCount = 0;
        foreach ($items as $index => $raw) {
            $showtimes = is_array($raw) ? ($raw['showtimes'] ?? []) : [];
            if (!is_array($showtimes) || $showtimes === []) {
                continue;
            }
            if (!array_is_list($showtimes)) {
                $showtimes = [$showtimes];
            }

            if (!array_key_exists($index, $saved)) {
                $results[$index]['showtimes'][] = ['index' => null, 'status' => 'skipped', 'message' => 'A tétel hibás, ezért az időpontjai kimaradtak.'];
                $summary['showtimes_skipped'] += count($showtimes);
                continue;
            }

            $defaults = is_array($raw) ? $raw : [];
            foreach ($showtimes as $sIndex => $showtimeRaw) {
                foreach ($this->importShowtime($saved[$index], $showtimeRaw, $defaults, $sIndex, $dryRun, $summary, $showtimeCount) as $row) {
                    $results[$index]['showtimes'][] = $row;
                }
            }
        }

        return ['dry_run' => $dryRun, 'type' => $this->type, 'summary' => $summary, 'items' => array_values($results)];
    }

    /** @return array<int, mixed> */
    private function normalize(mixed $payload): array
    {
        $rootKey = $this->type === self::TYPE_SHOW ? 'shows' : 'movies';
        $hint = "tömböt vagy {\"{$rootKey}\": [...]} objektumot várunk";

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException("A JSON tartalma érvénytelen: {$hint}.");
        }

        if (array_key_exists($rootKey, $payload)) {
            $payload = $payload[$rootKey];
        } elseif (!array_is_list($payload) && isset($payload['title'])) {
            $payload = [$payload];
        }

        if (!is_array($payload) || !array_is_list($payload) || $payload === []) {
            throw new InvalidArgumentException("A JSON nem tartalmaz tételeket ({$hint}).");
        }
        if (count($payload) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Egyszerre legfeljebb ' . self::MAX_ITEMS . ' tétel tölthető fel.');
        }

        return $payload;
    }

    /** @return array<string, array<int, mixed>> */
    private function itemRules(): array
    {
        $common = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trailer_youtube_url' => ['nullable', 'url', 'max:255'],
            'duration_min' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rating' => ['nullable', Rule::in(['general', '6', '12', '16', '18'])],
            'badges' => ['nullable', 'array'],
        ];

        if ($this->type === self::TYPE_SHOW) {
            return $common + [
                'badges.*' => ['string', Rule::in(ShowOptions::badgeKeys())],
                'category' => ['nullable', Rule::in(ShowOptions::categoryKeys())],
                'performer' => ['nullable', 'string', 'max:255'],
                'venue' => ['nullable', 'string', 'max:255'],
                'show_info' => ['nullable', 'string', 'max:255'],
                'ticket_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ];
        }

        return $common + [
            'badges.*' => ['string', Rule::in(MovieBadges::keys())],
            'film_info' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{0: Movie|TheaterShow|null, 1: array<string, mixed>} */
    private function importItem(array $raw, array $result, bool $dryRun, bool $updateExisting, array &$summary): array
    {
        $model = $this->modelClass();
        if (isset($raw['rating']) && is_scalar($raw['rating'])) {
            $raw['rating'] = (string) $raw['rating'];
        }

        $validator = Validator::make($raw, $this->itemRules(), [], [
            'title' => 'cím', 'description' => 'leírás', 'trailer_youtube_url' => 'előzetes URL', 'duration_min' => 'hossz (perc)',
            'rating' => 'korhatár', 'film_info' => 'film infó', 'badges' => 'jelvények', 'category' => 'típus',
            'performer' => 'fellépő', 'venue' => 'helyszín', 'show_info' => 'rövid infó', 'ticket_price' => 'jegyár',
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        $data = $validator->validated();
        $data['title'] = trim($data['title']);
        $result['title'] = $data['title'];

        $existing = $model::whereRaw('LOWER(title) = ?', [mb_strtolower($data['title'])])->first();
        if ($existing && !$updateExisting) {
            $result['status'] = 'skipped';
            $result['id'] = $existing->id;
            $result['message'] = 'Már létezik ilyen című ' . $this->noun() . ' (nem módosítottuk), a meglévőhöz kerülnek az időpontok.';
            return [$existing, $result];
        }

        $sources = $this->posterSources($raw);
        $posterUrl = null;
        $posterErrors = [];
        foreach ($sources as $source) {
            try {
                if ($dryRun) {
                    $this->images->validateSource($source);
                    $result['poster'] = ['status' => 'ok', 'message' => 'A forrás formátuma megfelelő (próbafuttatásnál nem töltjük le).'];
                    break;
                }
                $stored = $this->images->storeAsWebp($source);
                $posterUrl = $stored['url'];
                $result['poster'] = ['status' => 'converted', 'url' => $posterUrl, 'width' => $stored['width'], 'height' => $stored['height'], 'bytes' => $stored['bytes']];
                $summary['posters_converted']++;
                break;
            } catch (Throwable $e) {
                $posterErrors[] = $e->getMessage();
            }
        }
        if ($sources && $result['poster'] === null) {
            $result['poster'] = ['status' => 'failed', 'message' => implode(' | ', $posterErrors)];
            $summary['poster_warnings']++;
        }

        if ($dryRun) {
            $result['status'] = $existing ? 'updated' : 'created';
            $result['id'] = $existing?->id;
            $summary[$existing ? 'items_updated' : 'items_created']++;
            return [$existing ?? new $model($data), $result];
        }

        if ($posterUrl) {
            $data['poster_path'] = $posterUrl;
        }

        if ($existing) {
            $oldPoster = $existing->poster_path;
            $existing->update($data);
            if ($posterUrl && $oldPoster && $oldPoster !== $posterUrl) {
                $this->images->deleteStored($oldPoster);
            }
            $item = $existing;
            $result['status'] = 'updated';
            $summary['items_updated']++;
        } else {
            $item = $model::create($data);
            $result['status'] = 'created';
            $summary['items_created']++;
        }
        $result['id'] = $item->id;

        return [$item, $result];
    }

    /** @return array<int, string> */
    private function posterSources(array $raw): array
    {
        $value = $raw['poster'] ?? $raw['poster_url'] ?? $raw['poster_path'] ?? null;
        $items = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $items), fn ($v) => $v !== ''));
    }

    /**
     * Egy előadás-bejegyzés; a starts_at tömb esetén időpontonként egy-egy előadás jön létre.
     *
     * @return array<int, array<string, mixed>>
     */
    private function importShowtime(Movie|TheaterShow $item, mixed $raw, array $movieDefaults, int $index, bool $dryRun, array &$summary, int &$count): array
    {
        if (!is_array($raw)) {
            $summary['showtimes_failed']++;
            return [['index' => $index, 'status' => 'failed', 'message' => 'Az előadásnak objektumnak kell lennie.']];
        }

        $auditorium = null;
        $problem = null;
        $ref = $raw['auditorium'] ?? $raw['auditorium_id'] ?? $movieDefaults['auditorium'] ?? $movieDefaults['auditorium_id'] ?? null;
        if ($ref === null || $ref === '') {
            $problem = 'Hiányzik a terem (auditorium).';
        } else {
            $auditorium = is_int($ref) || ctype_digit((string) $ref)
                ? ($this->auditoriaById[(int) $ref] ?? null)
                : ($this->auditoriaByName[mb_strtolower(trim((string) $ref))] ?? null);
            if (!$auditorium) {
                $problem = 'Ismeretlen terem: ' . (is_scalar($ref) ? $ref : json_encode($ref)) . '.';
            }
        }

        $status = $raw['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive', 'cancelled', 'completed'], true)) {
            $problem ??= 'Érvénytelen státusz (active, inactive, cancelled vagy completed lehet).';
        }

        $pricing = $raw['pricing_override_json'] ?? null;
        if ($pricing !== null && !is_array($pricing)) {
            $problem ??= 'A pricing_override_json objektum vagy tömb lehet.';
        }

        $times = $raw['starts_at'] ?? null;
        $times = is_array($times) ? $times : [$times];
        if ($times === [] || $times === [null]) {
            $problem ??= 'Hiányzik a kezdés időpontja (starts_at).';
        }

        $open = $close = null;
        try {
            $open = $this->parseDate($raw['sales_open_at'] ?? null);
            $close = $this->parseDate($raw['sales_close_at'] ?? null);
        } catch (InvalidArgumentException $e) {
            $problem ??= $e->getMessage();
        }

        if ($problem) {
            $summary['showtimes_failed']++;
            return [['index' => $index, 'status' => 'failed', 'message' => $problem]];
        }

        $rows = [];
        foreach ($times as $time) {
            if (++$count > self::MAX_SHOWTIMES) {
                $summary['showtimes_failed']++;
                $rows[] = ['index' => $index, 'status' => 'failed', 'message' => 'Egyszerre legfeljebb ' . self::MAX_SHOWTIMES . ' előadás tölthető fel.'];
                continue;
            }

            try {
                $startsAt = $this->parseDate($time, true);
            } catch (InvalidArgumentException $e) {
                $summary['showtimes_failed']++;
                $rows[] = ['index' => $index, 'status' => 'failed', 'message' => $e->getMessage()];
                continue;
            }

            $label = $startsAt->copy()->setTimezone(self::LOCAL_TIMEZONE)->format('Y-m-d H:i');
            $duplicate = $item->exists && Showtime::where($this->foreignKey(), $item->id)
                ->where('auditorium_id', $auditorium->id)
                ->where('starts_at', $startsAt)
                ->exists();
            if ($duplicate) {
                $summary['showtimes_skipped']++;
                $rows[] = ['index' => $index, 'starts_at' => $label, 'auditorium' => $auditorium->name, 'status' => 'skipped', 'message' => 'Már létezik ilyen előadás.'];
                continue;
            }

            $id = null;
            if (!$dryRun) {
                $id = Showtime::create([
                    $this->foreignKey() => $item->id,
                    'auditorium_id' => $auditorium->id,
                    'starts_at' => $startsAt,
                    'sales_open_at' => $open,
                    'sales_close_at' => $close,
                    'pricing_override_json' => $pricing,
                    'status' => $status,
                ])->id;
            }
            $summary['showtimes_created']++;
            $rows[] = ['index' => $index, 'id' => $id, 'starts_at' => $label, 'auditorium' => $auditorium->name, 'status' => 'created'];
        }

        return $rows;
    }

    /** A megadott idő (időzóna nélkül) magyar helyi időnek számít; tárolásra az alkalmazás időzónájába (UTC) alakítjuk, mint a felület is. */
    private function parseDate(mixed $value, bool $required = false): ?Carbon
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException('Hiányzik a kezdés időpontja (starts_at).');
            }
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Érvénytelen dátum: ' . json_encode($value));
        }
        try {
            return Carbon::parse($value, self::LOCAL_TIMEZONE)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new InvalidArgumentException("Érvénytelen dátum: {$value} (formátum: 2026-11-05 18:00).");
        }
    }
}
