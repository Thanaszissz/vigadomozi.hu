<?php

namespace App\Services;

use App\Models\Auditorium;
use App\Models\Movie;
use App\Models\Showtime;
use App\Support\MovieBadges;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * JSON-ból történő tömeges feltöltés: először a filmek (plakáttal, WebP-re alakítva), utána a hozzájuk tartozó előadás-időpontok.
 */
class CatalogImportService
{
    public const MAX_MOVIES = 200;
    public const MAX_SHOWTIMES = 2000;
    public const LOCAL_TIMEZONE = 'Europe/Budapest';

    /** @var array<string, Auditorium> */
    private array $auditoriaByName = [];
    /** @var array<int, Auditorium> */
    private array $auditoriaById = [];

    public function __construct(private WebpImageService $images)
    {
    }

    /**
     * @param  mixed  $payload  film-tömb, {"movies": [...]} objektum vagy egyetlen film
     * @return array<string, mixed>
     */
    public function run(mixed $payload, bool $dryRun, bool $updateExisting): array
    {
        $movies = $this->normalize($payload);

        foreach (Auditorium::all() as $auditorium) {
            $this->auditoriaById[$auditorium->id] = $auditorium;
            $this->auditoriaByName[mb_strtolower(trim($auditorium->name))] = $auditorium;
        }

        $summary = [
            'movies_created' => 0, 'movies_updated' => 0, 'movies_failed' => 0,
            'posters_converted' => 0, 'poster_warnings' => 0,
            'showtimes_created' => 0, 'showtimes_skipped' => 0, 'showtimes_failed' => 0,
        ];
        $results = [];
        $saved = [];

        // 1. lépés: a filmek
        foreach ($movies as $index => $raw) {
            $result = ['index' => $index, 'title' => is_array($raw) ? (string) ($raw['title'] ?? '') : '', 'status' => 'failed', 'id' => null, 'errors' => [], 'poster' => null, 'showtimes' => []];

            try {
                if (!is_array($raw)) {
                    throw new InvalidArgumentException('A filmnek objektumnak kell lennie.');
                }
                [$movie, $result] = $this->importMovie($raw, $result, $dryRun, $updateExisting, $summary);
                $saved[$index] = $movie;
            } catch (InvalidArgumentException $e) {
                $result['errors'][] = $e->getMessage();
                $summary['movies_failed']++;
            } catch (Throwable $e) {
                report($e);
                $result['errors'][] = 'Váratlan hiba: ' . $e->getMessage();
                $summary['movies_failed']++;
            }

            $results[$index] = $result;
        }

        // 2. lépés: a filmekhez tartozó előadások (csak a sikeresen felvett filmeké)
        $showtimeCount = 0;
        foreach ($movies as $index => $raw) {
            $showtimes = is_array($raw) ? ($raw['showtimes'] ?? []) : [];
            if (!is_array($showtimes) || $showtimes === []) {
                continue;
            }
            if (!array_is_list($showtimes)) {
                $showtimes = [$showtimes];
            }

            if (!array_key_exists($index, $saved)) {
                $results[$index]['showtimes'][] = ['index' => null, 'status' => 'skipped', 'message' => 'A film hibás, ezért az előadásai kimaradtak.'];
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

        return ['dry_run' => $dryRun, 'summary' => $summary, 'movies' => array_values($results)];
    }

    /** @return array<int, mixed> */
    private function normalize(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('A JSON tartalma érvénytelen: film-tömböt vagy {"movies": [...]} objektumot várunk.');
        }

        if (array_key_exists('movies', $payload)) {
            $payload = $payload['movies'];
        } elseif (!array_is_list($payload) && isset($payload['title'])) {
            $payload = [$payload];
        }

        if (!is_array($payload) || !array_is_list($payload) || $payload === []) {
            throw new InvalidArgumentException('A JSON nem tartalmaz filmeket (film-tömböt vagy {"movies": [...]} objektumot várunk).');
        }
        if (count($payload) > self::MAX_MOVIES) {
            throw new InvalidArgumentException('Egyszerre legfeljebb ' . self::MAX_MOVIES . ' film tölthető fel.');
        }

        return $payload;
    }

    /** @return array{0: Movie|null, 1: array<string, mixed>} */
    private function importMovie(array $raw, array $result, bool $dryRun, bool $updateExisting, array &$summary): array
    {
        if (isset($raw['rating']) && is_scalar($raw['rating'])) {
            $raw['rating'] = (string) $raw['rating'];
        }

        $validator = Validator::make($raw, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trailer_youtube_url' => ['nullable', 'url', 'max:255'],
            'duration_min' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rating' => ['nullable', Rule::in(['general', '6', '12', '16', '18'])],
            'film_info' => ['nullable', 'string', 'max:255'],
            'badges' => ['nullable', 'array'],
            'badges.*' => ['string', Rule::in(MovieBadges::keys())],
        ], [], [
            'title' => 'cím', 'description' => 'leírás', 'trailer_youtube_url' => 'előzetes URL', 'duration_min' => 'hossz (perc)',
            'rating' => 'korhatár', 'film_info' => 'film infó', 'badges' => 'jelvények',
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        $data = $validator->validated();
        $data['title'] = trim($data['title']);
        $result['title'] = $data['title'];

        $existing = Movie::whereRaw('LOWER(title) = ?', [mb_strtolower($data['title'])])->first();
        if ($existing && !$updateExisting) {
            $result['status'] = 'skipped';
            $result['id'] = $existing->id;
            $result['message'] = 'Már létezik ilyen című film (nem módosítottuk), a meglévő filmhez kerülnek az előadások.';
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
            $summary[$existing ? 'movies_updated' : 'movies_created']++;
            return [$existing ?? new Movie($data), $result];
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
            $movie = $existing;
            $result['status'] = 'updated';
            $summary['movies_updated']++;
        } else {
            $movie = Movie::create($data);
            $result['status'] = 'created';
            $summary['movies_created']++;
        }
        $result['id'] = $movie->id;

        return [$movie, $result];
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
    private function importShowtime(Movie $movie, mixed $raw, array $movieDefaults, int $index, bool $dryRun, array &$summary, int &$count): array
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
            $duplicate = $movie->exists && Showtime::where('movie_id', $movie->id)
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
                    'movie_id' => $movie->id,
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
