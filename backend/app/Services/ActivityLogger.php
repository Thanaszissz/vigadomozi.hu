<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ActivityLogger
{
    public const CATEGORIES = [
        'auth' => 'Bejelentkezés',
        'ticket' => 'Jegyeladás',
        'reservation' => 'Foglalások',
        'movie' => 'Filmek',
        'theater_show' => 'Színházi előadások',
        'showtime' => 'Előadások',
        'auditorium' => 'Termek',
        'user' => 'Felhasználók',
        'report' => 'Riportok, exportok',
    ];

    // művelet => [kategória, megnevezés]
    public const ACTIONS = [
        'auth.login' => ['auth', 'Bejelentkezés'],
        'auth.login_failed' => ['auth', 'Sikertelen bejelentkezés'],
        'auth.logout' => ['auth', 'Kijelentkezés'],

        'ticket.sale' => ['ticket', 'Jegyeladás'],
        'ticket.print' => ['ticket', 'Jegynyomtatás'],

        'reservation.payment' => ['reservation', 'Foglalás kifizetése'],

        'movie.created' => ['movie', 'Film felvétele'],
        'movie.updated' => ['movie', 'Film módosítása'],
        'movie.deleted' => ['movie', 'Film törlése'],

        'theater_show.created' => ['theater_show', 'Színházi előadás felvétele'],
        'theater_show.updated' => ['theater_show', 'Színházi előadás módosítása'],
        'theater_show.deleted' => ['theater_show', 'Színházi előadás törlése'],

        'showtime.created' => ['showtime', 'Előadás felvétele'],
        'showtime.updated' => ['showtime', 'Előadás módosítása'],
        'showtime.deleted' => ['showtime', 'Előadás törlése'],

        'auditorium.created' => ['auditorium', 'Terem felvétele'],
        'auditorium.updated' => ['auditorium', 'Terem módosítása'],
        'auditorium.deleted' => ['auditorium', 'Terem törlése'],

        'user.created' => ['user', 'Felhasználó felvétele'],
        'user.updated' => ['user', 'Felhasználó módosítása'],
        'user.deleted' => ['user', 'Felhasználó törlése'],

        'report.daily_close' => ['report', 'Napi zárás megtekintése'],
        'report.daily_close_print' => ['report', 'Napi zárás nyomtatása'],
        'report.daily_report' => ['report', 'Napi riport megtekintése'],
        'report.export_tickets' => ['report', 'Jegyek exportálása'],
        'report.export_reservations' => ['report', 'Foglalások exportálása'],
    ];

    /**
     * Tevékenység rögzítése. A naplózási hiba soha nem akaszthatja meg az eredeti műveletet.
     *
     * @param User|null|false $user false = az éppen bejelentkezett felhasználó, null = nincs felhasználó
     */
    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        User|null|false $user = false,
        ?string $subjectLabel = null,
        ?string $attemptedEmail = null
    ): void {
        try {
            $user = $user === false ? self::currentUser() : $user;
            $request = request();

            ActivityLog::create([
                'occurred_at' => now(),
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_email' => $user?->email ?? $attemptedEmail,
                'category' => self::ACTIONS[$action][0] ?? explode('.', $action)[0],
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ? mb_substr($subjectLabel, 0, 255) : null,
                'properties' => $properties ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Tevékenységnapló írása sikertelen: ' . $e->getMessage());
        }
    }

    public static function currentUser(): ?User
    {
        try {
            $user = auth('sanctum')->user();

            return $user instanceof User ? $user : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
