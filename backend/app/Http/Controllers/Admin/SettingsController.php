<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TicketNumberingException;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\TicketNumberingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SettingsController extends Controller
{
    private const MAX_PASSWORD_FAILURES = 5;
    private const LOCKOUT_SECONDS = 900;

    public function __construct(private TicketNumberingService $numbering)
    {
    }

    public function ticketNumbering(): JsonResponse
    {
        return response()->json($this->numbering->state());
    }

    /** 1. lépés: a kért módosítás ellenőrzése, jelszó-megerősítés, összefoglaló és megerősítő kód. */
    public function prepareTicketNumbering(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        try {
            $result = $this->numbering->prepare($request->user(), $request->only(['action', 'prefix', 'mode', 'value']));
        } catch (TicketNumberingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** 2. lépés: a módosítás végrehajtása (kód + megerősítő szöveg + jelszó). */
    public function applyTicketNumbering(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'phrase' => ['required', 'string', 'max:100'],
        ]);

        $this->confirmPassword($request);

        try {
            $result = $this->numbering->apply($request->user(), $data['token'], $data['phrase']);
        } catch (TicketNumberingException $e) {
            ActivityLogger::log('settings.ticketing_denied', 'Jegyszámozás módosítása elutasítva: ' . $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $action = $result['action'] === 'prefix' ? 'settings.ticket_prefix_changed' : 'settings.ticket_counter_set';
        ActivityLogger::log($action, $result['description'], null, $result['properties']);
        Log::warning('Jegyszámozás módosítva', ['user_id' => $request->user()->id] + $result['properties']);

        return response()->json([
            'status' => 'applied',
            'message' => $result['description'],
            'state' => $this->numbering->state(),
        ]);
    }

    /** A módosítás előtt a bejelentkezett felhasználó jelszavát is meg kell adni; hibás próbálkozás után időzár van. */
    private function confirmPassword(Request $request): void
    {
        $user = $request->user();
        $key = 'ticketing-password:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            abort(response()->json([
                'message' => "Túl sok hibás jelszó. A jegyszámozás módosítása {$minutes} percre zárolva van.",
            ], 429));
        }

        if (!Hash::check((string) $request->input('password', ''), (string) $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            ActivityLogger::log('settings.ticketing_denied', 'Jegyszámozás módosítása elutasítva: hibás jelszó');

            abort(response()->json(['message' => 'Hibás jelszó.'], 422));
        }

        RateLimiter::clear($key);
    }
}
