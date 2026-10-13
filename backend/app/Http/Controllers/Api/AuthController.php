<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const IP_MAX_ATTEMPTS = 20;
    private const LOCKOUT_SECONDS = 900;

    private static ?string $dummyHash = null;

    /**
     * Bejelentkezés és token generálása. Kitalálás ellen kettős korlát: e-mail+IP páronként és IP-nként is,
     * a hibás kísérletek 15 percig számítanak; sikeres belépés törli a páros számlálóját.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        $email = mb_strtolower($credentials['email']);
        $pairKey = 'login:' . sha1($email . '|' . $request->ip());
        $ipKey = 'login-ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($pairKey, self::MAX_ATTEMPTS) || RateLimiter::tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS)) {
            $seconds = max(RateLimiter::availableIn($pairKey), RateLimiter::availableIn($ipKey));
            ActivityLogger::log('auth.login_failed', 'Bejelentkezés letiltva (túl sok hibás kísérlet): ' . $email, null, ['reason' => 'zárolva'], user: null, attemptedEmail: $email);

            return response()->json([
                'message' => 'Túl sok sikertelen bejelentkezési kísérlet. Próbálja újra ' . (int) ceil($seconds / 60) . ' perc múlva.',
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Ismeretlen e-mail esetén is lefut egy jelszó-ellenőrzés, hogy a válaszidő ne áruljon el semmit a fiók létezéséről
        $hash = $user?->password ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(8))));
        $valid = Hash::check($credentials['password'], $hash) && $user !== null;

        if (!$valid) {
            RateLimiter::hit($pairKey, self::LOCKOUT_SECONDS);
            RateLimiter::hit($ipKey, self::LOCKOUT_SECONDS);

            ActivityLogger::log(
                'auth.login_failed',
                'Sikertelen bejelentkezés: ' . $credentials['email'],
                null,
                ['reason' => $user ? 'hibás jelszó' : 'ismeretlen e-mail cím'],
                user: null,
                attemptedEmail: $credentials['email']
            );

            throw ValidationException::withMessages([
                'email' => ['A bejelentkezési adatok helytelenek.'],
            ]);
        }

        RateLimiter::clear($pairKey);

        $token = $user->createToken('admin-token', ['admin:access'], now()->addMinutes((int) config('sanctum.expiration', 480)))->plainTextToken;

        ActivityLogger::log('auth.login', 'Bejelentkezés', $user, user: $user, subjectLabel: $user->name);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Sikeres bejelentkezés!',
        ]);
    }

    /**
     * Kijelentkezés
     */
    public function logout(Request $request)
    {
        ActivityLogger::log('auth.logout', 'Kijelentkezés', $request->user(), user: $request->user(), subjectLabel: $request->user()?->name);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sikeresen kijelentkeztél.']);
    }

    /**
     * Aktuális user adatai
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
