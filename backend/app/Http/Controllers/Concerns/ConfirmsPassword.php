<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

trait ConfirmsPassword
{
    /** A bejelentkezett felhasználó jelszavának megerősítése; hibás próbálkozások után időzár van. */
    protected function confirmPassword(Request $request, string $scope, string $what): void
    {
        $user = $request->user();
        $key = "{$scope}-password:" . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            abort(response()->json(['message' => "Túl sok hibás jelszó. {$what} {$minutes} percre zárolva van."], 429));
        }

        if (!Hash::check((string) $request->input('password', ''), (string) $user->password)) {
            RateLimiter::hit($key, 900);
            ActivityLogger::log('settings.password_denied', "{$what} elutasítva: hibás jelszó");

            abort(response()->json(['message' => 'Hibás jelszó.'], 422));
        }

        RateLimiter::clear($key);
    }
}
