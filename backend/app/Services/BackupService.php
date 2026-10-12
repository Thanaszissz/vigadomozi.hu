<?php

namespace App\Services;

use App\Exceptions\BackupException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teljes adatbázis-mentés. A mentés egy gzip-elt, soronként JSON formátumú fájl (adatbázis-motortól független),
 * amit a szerveren, a webgyökéren kívül tárolunk (storage/app/backups). A mentések jegyzéke, az ütemezés és
 * az eseménynapló fájlokban él, így egy visszatöltés nem írja felül őket.
 *
 * Fájlformátum (soronként egy JSON):
 *   {"format":"vigado-backup","version":1,...,"tables":{név:[oszlopok]}}   fejléc
 *   {"t":"név","c":[oszlopok]}                                              táblafejléc
 *   [érték, érték, ...]                                                     sor (az oszlopok sorrendjében)
 *   {"f":1,"counts":{név:sorszám},"sha256":"..."}                           lábléc (a fejléc és az adatsorok ellenőrzőösszege)
 */
class BackupService
{
    public const FORMAT = 'vigado-backup';
    public const VERSION = 1;
    public const TYPES = ['manual', 'auto', 'prerestore', 'upload'];

    /** Ezeket a táblákat nem mentjük és nem írjuk felül: munkamenetek, gyorsítótár, sorok, a migrációk jegyzéke. */
    public const EXCLUDED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens',
    ];

    private const ID_PATTERN = '/^vigado-backup_\d{8}_\d{6}_(manual|auto|prerestore|upload)(_[a-z0-9]{4})?$/';

    public const FREQUENCIES = [
        'hourly' => 'Óránként',
        'every_6_hours' => '6 óránként',
        'every_12_hours' => '12 óránként',
        'daily' => 'Naponta',
        'weekly' => 'Hetente',
    ];

    public function directory(): string
    {
        $dir = storage_path('app/backups');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) {
            throw new BackupException('A mentések mappája nem hozható létre: ' . $dir, 500);
        }

        // A mentések nem lehetnek közvetlenül letölthetők a webről
        $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }

        return $dir;
    }

    public function isValidId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id);
    }

    public function pathOf(string $id): string
    {
        if (!$this->isValidId($id)) {
            throw new BackupException('Érvénytelen mentés-azonosító.', 404);
        }

        return $this->directory() . DIRECTORY_SEPARATOR . $id . '.ndjson.gz';
    }

    private function metaPathOf(string $id): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $id . '.meta.json';
    }

    // ------------------------------------------------------------------ zár

    /** Egyidejű mentés / visszatöltés kizárása. A zár a kérés végéig él. */
    public function lock(bool $wait = false)
    {
        $handle = fopen($this->directory() . DIRECTORY_SEPARATOR . '.lock', 'c');
        $flags = LOCK_EX | ($wait ? 0 : LOCK_NB);

        if (!$handle || !flock($handle, $flags)) {
            throw new BackupException('Másik mentés vagy visszatöltés van folyamatban, próbáld újra később.', 409);
        }

        return $handle;
    }

    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // ----------------------------------------------------------- mentés

    /** @return array<int,string> a mentett táblák nevei */
    public function backupTables(): array
    {
        $this->assertSupportedDriver();

        // MySQL-en a séma-lista a szerver összes adatbázisát tartalmazza: csak a saját adatbázis táblái kellenek
        $database = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? DB::connection()->getDatabaseName()
            : null;

        $names = collect(Schema::getTables())
            ->filter(fn($t) => $database === null || (is_array($t) ? ($t['schema'] ?? null) : ($t->schema ?? null)) === $database)
            ->map(fn($t) => is_array($t) ? $t['name'] : $t->name)
            ->reject(fn($n) => in_array($n, self::EXCLUDED_TABLES, true) || str_starts_with($n, 'sqlite_'))
            ->unique()
            ->sort()
            ->values();

        return $names->all();
    }

    public function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new BackupException("A(z) {$driver} adatbázis-motor mentése nem támogatott.", 500);
        }
    }

    /**
     * Új mentés készítése. A hívó felelős a zárért (lock), hogy a mentés ne fusson egyszerre mással.
     *
     * @return array a mentés jegyzék-adatai
     */
    public function create(string $type, ?User $user = null): array
    {
        if (!in_array($type, self::TYPES, true) || $type === 'upload') {
            throw new BackupException('Érvénytelen mentés-típus.', 500);
        }

        @set_time_limit(0);
        $now = Carbon::now('UTC');
        $id = $this->newId($type, $now);
        $final = $this->pathOf($id);
        $tmp = $final . '.part';

        $tables = $this->backupTables();
        $schema = [];
        foreach ($tables as $table) {
            $schema[$table] = Schema::getColumnListing($table);
        }

        $gz = @gzopen($tmp, 'wb6');
        if (!$gz) {
            throw new BackupException('A mentés fájlja nem hozható létre (nincs írási jog?).', 500);
        }

        $hash = hash_init('sha256');
        $counts = [];

        $write = function (array $data) use ($gz, $hash): void {
            $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
            hash_update($hash, $line);
            gzwrite($gz, $line);
        };

        try {
            $header = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'created_at' => $now->toIso8601String(),
                'type' => $type,
                'created_by' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
                'driver' => DB::connection()->getDriverName(),
                'tables' => $schema,
            ];
            $write($header);

            // Egyetlen konzisztens pillanatkép az összes táblából
            DB::transaction(function () use ($tables, $schema, $write, &$counts) {
                foreach ($tables as $table) {
                    $columns = $schema[$table];
                    $write(['t' => $table, 'c' => $columns]);
                    $counts[$table] = 0;
                    $orderBy = in_array('id', $columns, true) ? 'id' : $columns[0];

                    DB::table($table)->orderBy($orderBy)->chunk(500, function ($rows) use ($columns, $write, $table, &$counts) {
                        foreach ($rows as $row) {
                            $row = (array) $row;
                            $write(array_map(fn($c) => $row[$c] ?? null, $columns));
                            $counts[$table]++;
                        }
                    });
                }
            });

            $footer = ['f' => 1, 'counts' => $counts, 'sha256' => hash_final($hash, false)];
            gzwrite($gz, json_encode($footer, JSON_UNESCAPED_UNICODE) . "\n");
            gzclose($gz);

            if (!@rename($tmp, $final)) {
                throw new BackupException('A mentés fájlja nem menthető el.', 500);
            }
        } catch (\Throwable $e) {
            @gzclose($gz);
            @unlink($tmp);

            throw $e instanceof BackupException ? $e : new BackupException('A mentés sikertelen: ' . $e->getMessage(), 500);
        }

        $meta = $this->buildMeta($id, $type, $header, $footer, filesize($final));
        file_put_contents($this->metaPathOf($id), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->history('backup_created', $meta['id'], $user?->name ?? 'Automatikus', "Mentés készült ({$type}), {$meta['total_rows']} sor, " . $this->humanSize($meta['size']));

        return $meta;
    }

    private function newId(string $type, Carbon $now): string
    {
        $base = 'vigado-backup_' . $now->format('Ymd_His') . '_' . $type;
        $id = $base;

        while (is_file($this->pathOf($id))) {
            $id = $base . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        return $id;
    }

    private function buildMeta(string $id, string $type, array $header, array $footer, int $size): array
    {
        $counts = $footer['counts'] ?? [];

        return [
            'id' => $id,
            'type' => $type,
            'created_at' => $header['created_at'],
            'created_by' => $header['created_by']['name'] ?? null,
            'driver' => $header['driver'] ?? null,
            'size' => $size,
            'sha256' => $footer['sha256'],
            'code' => strtoupper(substr($footer['sha256'], 0, 6)),
            'tables' => $counts,
            'total_rows' => array_sum($counts),
            'stats' => [
                'tickets' => (int) ($counts['tickets'] ?? 0),
                'reservations' => (int) ($counts['reservations'] ?? 0),
                'users' => (int) ($counts['users'] ?? 0),
                'showtimes' => (int) ($counts['showtimes'] ?? 0),
                'movies' => (int) ($counts['movies'] ?? 0),
            ],
        ];
    }

    // ------------------------------------------------------- jegyzék

    /** @return array<int,array> a mentések, legújabb elöl */
    public function list(): array
    {
        $items = [];

        foreach (glob($this->directory() . DIRECTORY_SEPARATOR . 'vigado-backup_*.ndjson.gz') ?: [] as $file) {
            $id = basename($file, '.ndjson.gz');
            if (!$this->isValidId($id)) {
                continue;
            }

            $items[] = $this->meta($id);
        }

        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['id'], $a['id']));

        return $items;
    }

    public function find(string $id): array
    {
        if (!is_file($this->pathOf($id))) {
            throw new BackupException('A mentés nem található.', 404);
        }

        return $this->meta($id);
    }

    /** A jegyzék-adat; ha hiányzik vagy nem egyezik a fájllal, a fájlból épül újra. */
    private function meta(string $id): array
    {
        $file = $this->pathOf($id);
        $metaFile = $this->metaPathOf($id);

        if (is_file($metaFile)) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            if (is_array($meta) && ($meta['size'] ?? null) === filesize($file)) {
                return $meta;
            }
        }

        try {
            $info = $this->inspect($file);
        } catch (BackupException $e) {
            // A sérült fájl is látszik a jegyzékben (törölhető, letölthető), de nem tölthető vissza
            return [
                'id' => $id,
                'type' => preg_match(self::ID_PATTERN, $id, $m) ? $m[1] : 'upload',
                'created_at' => Carbon::createFromTimestampUTC((int) filemtime($file))->toIso8601String(),
                'created_by' => null,
                'driver' => null,
                'size' => (int) filesize($file),
                'sha256' => '',
                'code' => '',
                'tables' => [],
                'total_rows' => 0,
                'stats' => ['tickets' => 0, 'reservations' => 0, 'users' => 0, 'showtimes' => 0, 'movies' => 0],
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }

        $type = preg_match(self::ID_PATTERN, $id, $m) ? $m[1] : 'upload';
        $meta = $this->buildMeta($id, $type, $info['header'], $info['footer'], filesize($file));
        file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $meta;
    }

    /** Csak ép, visszatölthető mentést ad vissza. */
    public function findValid(string $id): array
    {
        $meta = $this->find($id);
        if (($meta['valid'] ?? true) === false) {
            throw new BackupException('A mentésfájl sérült vagy módosult: ' . ($meta['error'] ?? ''));
        }

        return $meta;
    }

    public function delete(string $id, ?User $user): array
    {
        $meta = $this->find($id);
        @unlink($this->pathOf($id));
        @unlink($this->metaPathOf($id));

        $this->history('backup_deleted', $id, $user?->name ?? '—', 'Mentés törölve');

        return $meta;
    }

    /** A feltöltött mentés ellenőrzése és felvétele a jegyzékbe. */
    public function importUploaded(string $tmpPath, ?User $user): array
    {
        $info = $this->inspect($tmpPath);
        $now = Carbon::now('UTC');
        $id = $this->newId('upload', $now);

        if (!@copy($tmpPath, $this->pathOf($id))) {
            throw new BackupException('A feltöltött fájl nem menthető el.', 500);
        }

        $header = $info['header'];
        // A fájl eredeti létrehozási ideje megmarad, a feltöltés ténye a típusból látszik
        $meta = $this->buildMeta($id, 'upload', $header, $info['footer'], filesize($this->pathOf($id)));
        $meta['uploaded_at'] = $now->toIso8601String();
        $meta['uploaded_by'] = $user?->name;
        file_put_contents($this->metaPathOf($id), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->history('backup_uploaded', $id, $user?->name ?? '—', "Mentés feltöltve ({$meta['total_rows']} sor)");

        return $meta;
    }

    // ---------------------------------------------------- ellenőrzés

    /**
     * A mentésfájl teljes ellenőrzése: formátum, ellenőrzőösszeg, sorszámok, oszlopszámok.
     *
     * @return array{header:array,footer:array}
     * @throws BackupException
     */
    public function inspect(string $path): array
    {
        $header = null;
        $footer = null;
        $counts = [];
        $columns = [];
        $current = null;
        $hash = hash_init('sha256');

        foreach ($this->readLines($path) as $line) {
            $data = json_decode($line, true);
            if ($data === null) {
                throw new BackupException('A mentésfájl sérült (hibás sor).');
            }

            if ($header === null) {
                if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
                    throw new BackupException('Ez nem a rendszer mentésfájlja.');
                }
                if ((int) ($data['version'] ?? 0) !== self::VERSION) {
                    throw new BackupException('A mentésfájl verziója nem támogatott.');
                }
                $header = $data;
                hash_update($hash, $line . "\n");
                continue;
            }

            if (array_is_list($data)) {
                if ($current === null || count($data) !== count($columns[$current])) {
                    throw new BackupException('A mentésfájl sérült (hibás adatsor).');
                }
                $counts[$current]++;
                hash_update($hash, $line . "\n");
            } elseif (isset($data['t'])) {
                $current = (string) $data['t'];
                $columns[$current] = (array) ($data['c'] ?? []);
                $counts[$current] = 0;
                if (!isset($header['tables'][$current]) || $header['tables'][$current] !== $columns[$current]) {
                    throw new BackupException('A mentésfájl sérült (hibás táblaleírás).');
                }
                hash_update($hash, $line . "\n");
            } elseif (isset($data['f'])) {
                $footer = $data;
            }
        }

        if ($header === null || $footer === null) {
            throw new BackupException('A mentésfájl hiányos vagy sérült (nincs lezárva).');
        }
        if (!hash_equals((string) $footer['sha256'], hash_final($hash, false))) {
            throw new BackupException('A mentésfájl ellenőrzőösszege nem egyezik: a fájl módosult vagy sérült.');
        }
        if (($footer['counts'] ?? []) != $counts) {
            throw new BackupException('A mentésfájl sorszámai nem egyeznek: a fájl sérült.');
        }
        if (array_keys($header['tables']) != array_keys($counts)) {
            throw new BackupException('A mentésfájl táblái nem egyeznek: a fájl sérült.');
        }

        return ['header' => $header, 'footer' => $footer];
    }

    /** @return \Generator<int,string> */
    public function readLines(string $path): \Generator
    {
        $gz = @gzopen($path, 'rb');
        if (!$gz) {
            throw new BackupException('A mentésfájl nem olvasható.');
        }

        try {
            while (!gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    break;
                }
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    // -------------------------------------------------- ütemezés

    private function scheduleFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'schedule.json';
    }

    public function defaultSchedule(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'weekday' => 1,
            'keep' => 14,
            'next_run_at' => null,
            'last_run_at' => null,
            'last_status' => null,
            'last_message' => null,
        ];
    }

    public function schedule(): array
    {
        $file = $this->scheduleFile();
        $stored = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return array_merge($this->defaultSchedule(), is_array($stored) ? $stored : []);
    }

    private function saveSchedule(array $schedule): void
    {
        file_put_contents($this->scheduleFile(), json_encode($schedule, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function updateSchedule(array $input, ?User $user): array
    {
        $schedule = array_merge($this->schedule(), [
            'enabled' => (bool) $input['enabled'],
            'frequency' => $input['frequency'],
            'time' => $input['time'],
            'weekday' => (int) $input['weekday'],
            'keep' => (int) $input['keep'],
        ]);

        $schedule['next_run_at'] = $schedule['enabled'] ? $this->nextRun($schedule)->toIso8601String() : null;
        $this->saveSchedule($schedule);

        $this->history('schedule_changed', null, $user?->name ?? '—', $schedule['enabled']
            ? 'Automatikus mentés bekapcsolva: ' . $this->describeSchedule($schedule)
            : 'Automatikus mentés kikapcsolva');

        return $schedule;
    }

    public function describeSchedule(array $s): string
    {
        $label = self::FREQUENCIES[$s['frequency']] ?? $s['frequency'];
        $days = [1 => 'hétfő', 2 => 'kedd', 3 => 'szerda', 4 => 'csütörtök', 5 => 'péntek', 6 => 'szombat', 7 => 'vasárnap'];

        return match ($s['frequency']) {
            'daily' => "{$label} {$s['time']}-kor",
            'weekly' => "{$label}, {$days[(int) $s['weekday']]} {$s['time']}-kor",
            default => $label,
        };
    }

    /** A következő időpont (budapesti idő szerint számolva), a megadott pillanat után. */
    public function nextRun(array $schedule, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? Carbon::now())->copy()->timezone('Europe/Budapest');
        [$h, $m] = array_map('intval', explode(':', $schedule['time'] ?? '03:00'));

        $next = match ($schedule['frequency']) {
            'hourly' => $from->copy()->startOfHour()->addHour(),
            'every_6_hours' => $from->copy()->startOfHour()->setHour(intdiv($from->hour, 6) * 6)->addHours(6),
            'every_12_hours' => $from->copy()->startOfHour()->setHour(intdiv($from->hour, 12) * 12)->addHours(12),
            'weekly' => (function () use ($from, $schedule, $h, $m) {
                $d = $from->copy()->setTime($h, $m, 0);
                $d->addDays(((int) $schedule['weekday'] - $d->dayOfWeekIso + 7) % 7);

                return $d <= $from ? $d->addWeek() : $d;
            })(),
            default => (function () use ($from, $h, $m) {
                $d = $from->copy()->setTime($h, $m, 0);

                return $d <= $from ? $d->addDay() : $d;
            })(),
        };

        return $next->timezone('UTC');
    }

    /**
     * Ha esedékes az automatikus mentés, lefuttatja. Kérésekből és a parancssorból is hívható; olcsó, ha nincs teendő.
     */
    public function runIfDue(): ?array
    {
        $file = $this->scheduleFile();
        if (!is_file($file)) {
            return null;
        }

        $schedule = $this->schedule();
        if (!$schedule['enabled'] || !$schedule['next_run_at'] || Carbon::parse($schedule['next_run_at'])->isFuture()) {
            return null;
        }

        try {
            $lock = $this->lock();
        } catch (BackupException) {
            return null;
        }

        try {
            // Újraolvasás zár alatt: közben más kérés már lefuttathatta
            $schedule = $this->schedule();
            if (!$schedule['enabled'] || !$schedule['next_run_at'] || Carbon::parse($schedule['next_run_at'])->isFuture()) {
                return null;
            }

            // Hiba esetén sem próbálkozik azonnal újra: a következő esedékes időpontra lép
            $schedule['next_run_at'] = $this->nextRun($schedule)->toIso8601String();
            $schedule['last_run_at'] = Carbon::now('UTC')->toIso8601String();

            try {
                $meta = $this->create('auto');
                $removed = $this->applyRetention((int) $schedule['keep']);
                $schedule['last_status'] = 'ok';
                $schedule['last_message'] = $meta['total_rows'] . ' sor, ' . $this->humanSize($meta['size'])
                    . ($removed ? ", {$removed} régi mentés törölve" : '');
            } catch (\Throwable $e) {
                $schedule['last_status'] = 'failed';
                $schedule['last_message'] = mb_substr($e->getMessage(), 0, 300);
                $this->history('backup_failed', null, 'Automatikus', 'Az automatikus mentés sikertelen: ' . $schedule['last_message']);
                ActivityLogger::log('backup.failed', 'Az automatikus mentés sikertelen: ' . $schedule['last_message'], null, [], user: null);
                $meta = null;
            }

            $this->saveSchedule($schedule);

            if ($meta) {
                ActivityLogger::log('backup.created', "Automatikus mentés készült ({$meta['total_rows']} sor)", null, ['id' => $meta['id']], user: null, subjectLabel: $meta['id']);
            }

            return $meta;
        } finally {
            $this->unlock($lock);
        }
    }

    /** Az automatikus mentések közül csak a legutóbbi $keep marad meg. */
    public function applyRetention(int $keep): int
    {
        $auto = array_values(array_filter($this->list(), fn($b) => $b['type'] === 'auto'));
        $removed = 0;

        foreach (array_slice($auto, max(1, $keep)) as $old) {
            @unlink($this->pathOf($old['id']));
            @unlink($this->metaPathOf($old['id']));
            $removed++;
        }

        return $removed;
    }

    // ------------------------------------------------------ napló

    private function historyFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'history.log';
    }

    /** Fájlalapú eseménynapló: visszatöltés után is megmarad (az adatbázis naplója a visszatöltött állapotra áll vissza). */
    public function history(string $event, ?string $id, string $who, string $message): void
    {
        $line = json_encode([
            'at' => Carbon::now('UTC')->toIso8601String(),
            'event' => $event,
            'id' => $id,
            'who' => $who,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE) . "\n";

        @file_put_contents($this->historyFile(), $line, FILE_APPEND | LOCK_EX);
    }

    public function recentHistory(int $limit = 40): array
    {
        $file = $this->historyFile();
        if (!is_file($file)) {
            return [];
        }

        $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);

        return array_values(array_filter(array_map(fn($l) => json_decode($l, true), array_reverse($lines))));
    }

    // -------------------------------------------------- segédek

    public function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    public function diskUsage(): array
    {
        $dir = $this->directory();
        $size = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'vigado-backup_*.ndjson.gz') ?: [] as $f) {
            $size += (int) filesize($f);
        }

        return ['used' => $size, 'free' => @disk_free_space($dir) ?: null];
    }
}
