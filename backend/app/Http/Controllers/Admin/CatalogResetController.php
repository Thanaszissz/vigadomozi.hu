<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CatalogResetException;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\CatalogResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class CatalogResetController extends Controller
{
    private const MAX_PASSWORD_FAILURES = 5;

    private const LOCKOUT_SECONDS = 900;

    public function __construct(private CatalogResetService $reset) {}

    public function prepare(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $request->user();
        $prepared = $this->reset->prepare($user);

        try {
            if (config('mail.default') === 'log') {
                throw new \RuntimeException('A levélküldés nincs éles email-szolgáltatóra beállítva.');
            }

            Mail::raw(
                "A programadatok törléséhez szükséges megerősítő kód: {$prepared['email_code']}\n\n"
                .'A kód 10 percig érvényes. Ha nem te kezdeményezted a műveletet, hagyd figyelmen kívül ezt az üzenetet.',
                function ($message) use ($user) {
                    $message->to($user->email)->subject('Programadatok törlésének megerősítése');
                }
            );
        } catch (Throwable $e) {
            Log::error('Programadatok törléséhez szükséges megerősítő email küldése sikertelen.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            cache()->forget('catalog-reset:'.$prepared['plan']['token']);

            return response()->json(['message' => 'A megerősítő email küldése sikertelen. Ellenőrizd a levelezés beállításait, majd próbáld újra.'], 503);
        }

        ActivityLogger::log('settings.catalog_reset_prepared', 'Programadatok törlése előkészítve emailes megerősítéssel.');

        return response()->json($prepared['plan'] + [
            'email_hint' => $this->maskEmail($user->email),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'phrase' => ['required', 'string', 'max:100'],
            'email_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $this->confirmPassword($request);
        $attemptKey = 'catalog-reset-confirm:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($attemptKey, self::MAX_PASSWORD_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($attemptKey) / 60);

            return response()->json([
                'message' => "Túl sok hibás megerősítési kísérlet. A törlés {$minutes} percre zárolva van.",
            ], 429);
        }

        try {
            $summary = $this->reset->apply(
                $request->user(),
                $data['token'],
                $data['phrase'],
                $data['email_code']
            );
        } catch (CatalogResetException $e) {
            RateLimiter::hit($attemptKey, self::LOCKOUT_SECONDS);
            ActivityLogger::log('settings.catalog_reset_denied', 'Programadatok törlése elutasítva: '.$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        RateLimiter::clear($attemptKey);
        ActivityLogger::log('settings.catalog_reset_applied', 'Film-, színházi előadás- és időpontadatok törölve.', null, $summary);
        Log::warning('Programadatok és kapcsolódó foglalások törölve.', ['user_id' => $request->user()->id] + $summary);

        return response()->json([
            'status' => 'applied',
            'message' => 'A filmek, színházi előadások, időpontok és kapcsolódó foglalások törölve lettek. Az eladott jegyek és az értékesítési napló megmaradt.',
            'summary' => $summary,
        ]);
    }

    private function confirmPassword(Request $request): void
    {
        $user = $request->user();
        $key = 'catalog-reset-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            abort(response()->json([
                'message' => "Túl sok hibás jelszó. A programadatok törlése {$minutes} percre zárolva van.",
            ], 429));
        }

        if (! Hash::check((string) $request->input('password', ''), (string) $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            ActivityLogger::log('settings.catalog_reset_denied', 'Programadatok törlése elutasítva: hibás jelszó.');

            abort(response()->json(['message' => 'Hibás jelszó.'], 422));
        }

        RateLimiter::clear($key);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).str_repeat('*', max(2, strlen($local) - 1)).'@'.$domain;
    }
}
