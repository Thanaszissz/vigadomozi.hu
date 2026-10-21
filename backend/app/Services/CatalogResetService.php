<?php

namespace App\Services;

use App\Exceptions\CatalogResetException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CatalogResetService
{
    public const CONFIRM_TTL_SECONDS = 600;

    public const CONFIRM_PHRASE = 'PROGRAMTÖRLÉS';

    /** @return array{plan: array<string, mixed>, email_code: string} */
    public function prepare(User $user): array
    {
        $snapshot = $this->snapshot();
        $token = Str::random(64);
        $emailCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'signature' => $this->signature($snapshot),
            'summary' => $snapshot['summary'],
            'phrase' => self::CONFIRM_PHRASE,
            'email_code_hash' => Hash::make($emailCode),
        ], self::CONFIRM_TTL_SECONDS);

        return [
            'plan' => [
                'token' => $token,
                'expires_in' => self::CONFIRM_TTL_SECONDS,
                'phrase' => self::CONFIRM_PHRASE,
                'summary' => $snapshot['summary'],
            ],
            'email_code' => $emailCode,
        ];
    }

    /** @return array{movies: int, theater_shows: int, showtimes: int, reservations: int} */
    public function apply(User $user, string $token, string $phrase, string $emailCode): array
    {
        $cacheKey = $this->cacheKey($token);
        $stored = Cache::get($cacheKey);

        if (! $stored || $stored['user_id'] !== $user->id) {
            throw new CatalogResetException('A megerősítés lejárt vagy érvénytelen. Kezdd újra a törlést.');
        }

        if (! hash_equals($stored['phrase'], trim($phrase))) {
            throw new CatalogResetException('A megerősítő szöveg nem egyezik.');
        }

        if (! Hash::check($emailCode, $stored['email_code_hash'])) {
            throw new CatalogResetException('Az emailben kapott kód hibás.');
        }

        Cache::forget($cacheKey);

        return DB::transaction(function () use ($stored) {
            $snapshot = $this->snapshot();
            if (! hash_equals($stored['signature'], $this->signature($snapshot))) {
                throw new CatalogResetException('A program vagy a foglalások közben megváltoztak. Kezdd újra a törlést.');
            }

            DB::table('showtimes')->delete();
            DB::table('movies')->delete();
            DB::table('theater_shows')->delete();

            return $stored['summary'];
        });
    }

    /** @return array{summary: array<string, int>, ids: array<string, array<int, int>>} */
    private function snapshot(): array
    {
        $movieIds = DB::table('movies')->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $theaterShowIds = DB::table('theater_shows')->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $showtimeIds = DB::table('showtimes')->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reservationIds = DB::table('reservations')
            ->whereIn('showtime_id', $showtimeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'summary' => [
                'movies' => count($movieIds),
                'theater_shows' => count($theaterShowIds),
                'showtimes' => count($showtimeIds),
                'reservations' => count($reservationIds),
            ],
            'ids' => [
                'movies' => $movieIds,
                'theater_shows' => $theaterShowIds,
                'showtimes' => $showtimeIds,
                'reservations' => $reservationIds,
            ],
        ];
    }

    private function signature(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot['ids'], JSON_THROW_ON_ERROR));
    }

    private function cacheKey(string $token): string
    {
        return 'catalog-reset:'.$token;
    }
}
