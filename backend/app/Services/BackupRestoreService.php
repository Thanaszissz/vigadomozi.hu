<?php

namespace App\Services;

use App\Exceptions\BackupException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Visszatöltés mentésből. Kétlépcsős, többszörösen védett művelet:
 *  1. előkészítés: a mentésfájl teljes ellenőrzése, a séma kompatibilitása, összehasonlítás a jelenlegi adatokkal,
 *     rövid életű egyszer használatos kód és egy, az adott mentéshez kötött megerősítő szöveg;
 *  2. végrehajtás: kód + beírt megerősítő szöveg + tudomásulvétel; előtte kötelező biztonsági mentés a jelenlegi állapotról,
 *     majd egyetlen tranzakcióban a teljes csere (hiba esetén semmi nem változik).
 */
class BackupRestoreService
{
    public const CONFIRM_TTL_SECONDS = 300;

    public function __construct(private BackupService $backups)
    {
    }

    public function expectedPhrase(array $meta): string
    {
        return 'VISSZATÖLTÉS ' . $meta['code'];
    }

    /** 1. lépés */
    public function prepare(string $id, User $user): array
    {
        $meta = $this->backups->findValid($id);
        $info = $this->backups->inspect($this->backups->pathOf($id));

        // A fájl a jegyzékben tárolt ellenőrzőösszeggel is egyezzen
        if (!hash_equals($meta['sha256'], $info['footer']['sha256'])) {
            throw new BackupException('A mentésfájl nem egyezik a jegyzékben tárolt adatokkal.');
        }

        $compat = $this->compatibility($info['header']);
        if ($compat['problems']) {
            throw new BackupException('A mentés nem tölthető vissza: ' . implode(' ', $compat['problems']));
        }

        $current = $this->currentStats();
        $warnings = $this->warnings($meta, $current, $compat);

        $token = Str::random(40);
        Cache::put('backup-restore:' . $token, [
            'user_id' => $user->id,
            'backup_id' => $id,
            'sha256' => $meta['sha256'],
        ], now()->addSeconds(self::CONFIRM_TTL_SECONDS));

        return [
            'token' => $token,
            'expires_in' => self::CONFIRM_TTL_SECONDS,
            'phrase' => $this->expectedPhrase($meta),
            'backup' => $meta,
            'current' => $current,
            'warnings' => $warnings,
            'tables_to_clear' => $compat['to_clear'],
            'summary' => [
                'A mentés készült: ' . $this->fmt($meta['created_at']),
                'A visszatöltés a teljes adatbázis tartalmát lecseréli a mentés tartalmára (' . $meta['total_rows'] . ' sor).',
                'A jelenlegi állapotról előtte automatikus biztonsági mentés készül.',
                'A visszatöltés után minden felhasználót ki kell léptetni, újra be kell jelentkezni.',
            ],
        ];
    }

    /**
     * 2. lépés
     *
     * @return array{backup:array,safety:array}
     */
    public function apply(string $token, string $phrase, User $user): array
    {
        $payload = Cache::get('backup-restore:' . $token);
        if (!$payload || (int) $payload['user_id'] !== (int) $user->id) {
            throw new BackupException('A megerősítő kód lejárt vagy érvénytelen. Kezdd újra az előkészítéssel.');
        }

        $meta = $this->backups->findValid($payload['backup_id']);
        if (!hash_equals($payload['sha256'], $meta['sha256'])) {
            Cache::forget('backup-restore:' . $token);
            throw new BackupException('A mentés közben megváltozott. Kezdd újra az előkészítéssel.');
        }

        if (trim($phrase) !== $this->expectedPhrase($meta)) {
            throw new BackupException('A megerősítő szöveg nem egyezik.');
        }

        @set_time_limit(0);
        $lock = $this->backups->lock();

        try {
            // A mentés ellenőrzése közvetlenül a végrehajtás előtt is
            $path = $this->backups->pathOf($payload['backup_id']);
            $info = $this->backups->inspect($path);
            if (!hash_equals($payload['sha256'], $info['footer']['sha256'])) {
                throw new BackupException('A mentésfájl ellenőrzőösszege nem egyezik.');
            }
            $compat = $this->compatibility($info['header']);
            if ($compat['problems']) {
                throw new BackupException('A mentés nem tölthető vissza: ' . implode(' ', $compat['problems']));
            }

            Cache::forget('backup-restore:' . $token);

            // Kötelező biztonsági mentés a jelenlegi állapotról: ha ez nem sikerül, a visszatöltés el sem indul
            try {
                $safety = $this->backups->create('prerestore', $user);
            } catch (\Throwable $e) {
                throw new BackupException('A visszatöltés előtti biztonsági mentés sikertelen, a visszatöltés megszakadt: ' . $e->getMessage(), 500);
            }

            $this->backups->history('restore_started', $meta['id'], $user->name, 'Visszatöltés indul; biztonsági mentés: ' . $safety['id']);

            try {
                $this->restore($path, $compat['to_clear']);
            } catch (\Throwable $e) {
                $this->backups->history('restore_failed', $meta['id'], $user->name, 'A visszatöltés sikertelen, az adatbázis nem változott: ' . $e->getMessage());
                ActivityLogger::log('backup.restore_failed', 'A visszatöltés sikertelen: ' . $e->getMessage(), null, ['id' => $meta['id']], $user, $meta['id']);

                throw new BackupException('A visszatöltés sikertelen, az adatbázis nem változott: ' . $e->getMessage(), 500);
            }

            $this->backups->history('restore_applied', $meta['id'], $user->name, 'Visszatöltve a mentésből: ' . $this->fmt($meta['created_at']) . '; biztonsági mentés: ' . $safety['id']);

            // A visszatöltött adatbázisban a visszatöltést végző felhasználó nem feltétlenül létezik
            ActivityLogger::log(
                'backup.restored',
                'Adatbázis visszatöltve a mentésből: ' . $this->fmt($meta['created_at']) . ' (végrehajtotta: ' . $user->name . ')',
                null,
                ['id' => $meta['id'], 'safety_backup' => $safety['id'], 'by' => $user->name, 'by_email' => $user->email],
                null,
                $meta['id']
            );

            return ['backup' => $meta, 'safety' => $safety];
        } finally {
            $this->backups->unlock($lock);
        }
    }

    /** A teljes csere egyetlen tranzakcióban. Az idegen kulcsok ellenőrzése a művelet idejére ki van kapcsolva. */
    private function restore(string $path, array $toClear): void
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            $conn->statement('PRAGMA foreign_keys = OFF');
        } else {
            $conn->statement('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            $conn->transaction(function () use ($conn, $path, $toClear) {
                foreach ($toClear as $table) {
                    $conn->table($table)->delete();
                }

                $table = null;
                $columns = [];
                $batch = [];
                $flush = function () use ($conn, &$table, &$batch) {
                    if ($table !== null && $batch) {
                        $conn->table($table)->insert($batch);
                    }
                    $batch = [];
                };

                $first = true;
                foreach ($this->backups->readLines($path) as $line) {
                    if ($first) {
                        $first = false;
                        continue;
                    }

                    $data = json_decode($line, true);

                    if (array_is_list($data)) {
                        $batch[] = array_combine($columns, $data);
                        if (count($batch) >= 200) {
                            $flush();
                        }
                    } elseif (isset($data['t'])) {
                        $flush();
                        $table = (string) $data['t'];
                        $columns = (array) $data['c'];
                        $conn->table($table)->delete();
                    }
                }
                $flush();

                // A visszatöltött adatok mellé a korábbi bejelentkezések nem maradhatnak érvényben
                $conn->table('personal_access_tokens')->delete();
            });
        } finally {
            if ($driver === 'sqlite') {
                $conn->statement('PRAGMA foreign_keys = ON');
            } else {
                $conn->statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        Cache::flush();
    }

    /**
     * A mentés és a jelenlegi séma összevetése.
     *
     * @return array{problems:array<int,string>,to_clear:array<int,string>}
     */
    public function compatibility(array $header): array
    {
        $problems = [];
        $current = $this->backups->backupTables();

        foreach ($header['tables'] as $table => $columns) {
            if (in_array($table, BackupService::EXCLUDED_TABLES, true)) {
                $problems[] = "A mentés nem engedélyezett táblát tartalmaz ({$table}).";
                continue;
            }
            if (!in_array($table, $current, true)) {
                $problems[] = "A(z) {$table} tábla nem létezik a jelenlegi adatbázisban (a mentés más verzióból származik).";
                continue;
            }

            $missing = array_diff($columns, Schema::getColumnListing($table));
            if ($missing) {
                $problems[] = "A(z) {$table} táblából hiányzó oszlopok: " . implode(', ', $missing) . '.';
            }
        }

        // A mentés után létrejött táblák üresre állnak, hogy az adatbázis egyetlen pillanat állapotát tükrözze
        $toClear = array_values(array_diff($current, array_keys($header['tables'])));

        return ['problems' => $problems, 'to_clear' => $toClear];
    }

    public function currentStats(): array
    {
        $count = fn(string $t) => Schema::hasTable($t) ? (int) DB::table($t)->count() : 0;

        return [
            'tickets' => $count('tickets'),
            'reservations' => $count('reservations'),
            'users' => $count('users'),
            'showtimes' => $count('showtimes'),
            'movies' => $count('movies'),
            'last_ticket_number' => Schema::hasTable('tickets') ? DB::table('tickets')->orderByDesc('id')->value('ticket_number') : null,
        ];
    }

    private function warnings(array $meta, array $current, array $compat): array
    {
        $warnings = [];
        $b = $meta['stats'];

        if ($current['tickets'] > $b['tickets']) {
            $lost = $current['tickets'] - $b['tickets'];
            $warnings[] = "A mentés {$lost} jeggyel kevesebbet tartalmaz, mint a jelenlegi állapot: ezek az eladott jegyek elvesznek, a jegyszámláló visszaáll. "
                . 'Ha ezeket a jegyeket már kinyomtatták, ugyanazok a jegyszámok újra kiadásra kerülhetnek!';
        }
        if ($current['reservations'] > $b['reservations']) {
            $warnings[] = 'A mentés ' . ($current['reservations'] - $b['reservations']) . ' foglalással kevesebbet tartalmaz: a későbbi foglalások elvesznek.';
        }
        if ($current['users'] != $b['users']) {
            $warnings[] = "A felhasználók száma változik ({$current['users']} → {$b['users']}): a visszatöltés után a mentés felhasználóival és jelszavaival lehet belépni.";
        }
        if (Carbon::parse($meta['created_at'])->diffInHours(now()) >= 24) {
            $warnings[] = 'A mentés több mint egy napos: a készítése óta történt módosítások elvesznek.';
        }
        if ($compat['to_clear']) {
            $warnings[] = 'A mentés után létrehozott táblák kiürülnek: ' . implode(', ', $compat['to_clear']) . '.';
        }

        return $warnings;
    }

    private function fmt(string $iso): string
    {
        return Carbon::parse($iso)->timezone('Europe/Budapest')->format('Y.m.d. H:i');
    }
}
