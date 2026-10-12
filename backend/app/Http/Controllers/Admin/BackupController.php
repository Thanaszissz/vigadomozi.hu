<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\BackupRestoreService;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    private const MAX_PASSWORD_FAILURES = 5;
    private const LOCKOUT_SECONDS = 900;

    public function __construct(private BackupService $backups, private BackupRestoreService $restores)
    {
    }

    public function index(): JsonResponse
    {
        $schedule = $this->backups->schedule();

        return response()->json([
            'backups' => $this->backups->list(),
            'schedule' => $schedule + ['description' => $this->backups->describeSchedule($schedule)],
            'frequencies' => collect(BackupService::FREQUENCIES)->map(fn($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'history' => $this->backups->recentHistory(),
            'disk' => $this->backups->diskUsage(),
            'database' => ['driver' => DB::connection()->getDriverName(), 'tables' => count($this->backups->backupTables())],
            'current' => $this->restores->currentStats(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $lock = $this->backups->lock();
            try {
                $meta = $this->backups->create('manual', $request->user());
            } finally {
                $this->backups->unlock($lock);
            }
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('backup.created', "Kézi mentés készült ({$meta['total_rows']} sor)", null, ['id' => $meta['id'], 'size' => $meta['size']], subjectLabel: $meta['id']);

        return response()->json($meta, 201);
    }

    public function download(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        try {
            $this->backups->find($id);
            $path = $this->backups->pathOf($id);
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('backup.downloaded', "Mentés letöltve: {$id}", null, ['id' => $id], subjectLabel: $id);
        $this->backups->history('backup_downloaded', $id, $request->user()->name, 'Mentés letöltve');

        return response()->download($path, $id . '.ndjson.gz', ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $lock = $this->backups->lock();
            try {
                $this->backups->delete($id, $request->user());
            } finally {
                $this->backups->unlock($lock);
            }
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('backup.deleted', "Mentés törölve: {$id}", null, ['id' => $id], subjectLabel: $id);

        return response()->json(['status' => 'deleted']);
    }

    /** Korábban letöltött mentésfájl feltöltése (a fájl teljes ellenőrzése után kerül a jegyzékbe). */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:204800']], [
            'file.required' => 'Válassz ki egy mentésfájlt.',
            'file.uploaded' => 'A fájl feltöltése sikertelen (a szerver feltöltési korlátját meghaladhatja).',
            'file.max' => 'A fájl túl nagy (legfeljebb 200 MB).',
        ]);

        try {
            $meta = $this->backups->importUploaded($request->file('file')->getRealPath(), $request->user());
        } catch (BackupException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('backup.uploaded', "Mentésfájl feltöltve: {$meta['id']}", null, ['id' => $meta['id']], subjectLabel: $meta['id']);

        return response()->json($meta, 201);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(array_keys(BackupService::FREQUENCIES))],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'keep' => ['required', 'integer', 'between:1,90'],
        ]);

        $schedule = $this->backups->updateSchedule($data, $request->user());

        ActivityLogger::log(
            'backup.schedule_changed',
            $schedule['enabled'] ? 'Automatikus mentés beállítva: ' . $this->backups->describeSchedule($schedule) : 'Automatikus mentés kikapcsolva',
            null,
            ['frequency' => $schedule['frequency'], 'time' => $schedule['time'], 'weekday' => $schedule['weekday'], 'keep' => $schedule['keep'], 'enabled' => $schedule['enabled']]
        );

        return response()->json($schedule + ['description' => $this->backups->describeSchedule($schedule)]);
    }

    /** Visszatöltés 1. lépés: jelszó, a mentés ellenőrzése, összefoglaló és megerősítő kód. */
    public function prepareRestore(Request $request, string $id): JsonResponse
    {
        $this->confirmPassword($request);

        try {
            $plan = $this->restores->prepare($id, $request->user());
        } catch (BackupException $e) {
            ActivityLogger::log('backup.restore_denied', 'Visszatöltés elutasítva: ' . $e->getMessage(), null, ['id' => $id], subjectLabel: $id);

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log('backup.restore_prepared', "Visszatöltés előkészítve: {$id}", null, ['id' => $id], subjectLabel: $id);

        return response()->json($plan);
    }

    /** Visszatöltés 2. lépés: kód + megerősítő szöveg + tudomásulvétel + jelszó. */
    public function applyRestore(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'phrase' => ['required', 'string', 'max:100'],
            'acknowledge' => ['accepted'],
        ], ['acknowledge.accepted' => 'A visszatöltés következményeit tudomásul kell venni.']);

        $this->confirmPassword($request);

        try {
            $result = $this->restores->apply($data['token'], $data['phrase'], $request->user());
        } catch (BackupException $e) {
            ActivityLogger::log('backup.restore_denied', 'Visszatöltés elutasítva: ' . $e->getMessage(), null, ['id' => $id], subjectLabel: $id);

            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'status' => 'restored',
            'message' => 'Az adatbázis visszatöltve. Újra be kell jelentkezned.',
            'backup' => $result['backup'],
            'safety_backup' => $result['safety'],
        ]);
    }

    /** A jelszó megerősítése; hibás próbálkozások után időzár van. */
    private function confirmPassword(Request $request): void
    {
        $user = $request->user();
        $key = 'backup-password:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            abort(response()->json(['message' => "Túl sok hibás jelszó. A visszatöltés {$minutes} percre zárolva van."], 429));
        }

        if (!Hash::check((string) $request->input('password', ''), (string) $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            ActivityLogger::log('backup.restore_denied', 'Visszatöltés elutasítva: hibás jelszó');

            abort(response()->json(['message' => 'Hibás jelszó.'], 422));
        }

        RateLimiter::clear($key);
    }
}
