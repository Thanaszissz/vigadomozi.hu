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
        'customer' => 'Vásárlók',
        'invoice' => 'Számlák',
        'email' => 'E-mailek',
        'settings' => 'Beállítások',
        'report' => 'Riportok, exportok',
    ];

    // művelet => [kategória, megnevezés]
    public const ACTIONS = [
        'auth.login' => ['auth', 'Bejelentkezés'],
        'auth.login_failed' => ['auth', 'Sikertelen bejelentkezés'],
        'auth.logout' => ['auth', 'Kijelentkezés'],
        'auth.permission_denied' => ['auth', 'Jogosulatlan hozzáférési kísérlet'],

        'ticket.sale' => ['ticket', 'Jegyeladás'],
        'ticket.void' => ['ticket', 'Jegy sztornózása'],
        'ticket.print' => ['ticket', 'Jegynyomtatás'],

        'reservation.created' => ['reservation', 'Helyszíni foglalás rögzítése'],
        'reservation.cancelled' => ['reservation', 'Foglalás törlése'],
        'reservation.updated' => ['reservation', 'Foglalás módosítása'],
        'reservation.payment' => ['reservation', 'Foglalás kifizetése'],
        'reservation.payment_conflict' => ['reservation', 'Fizetési ütközés (visszatérítés szükséges)'],

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
        'user.role_changed' => ['user', 'Szerepkör módosítása'],

        'customer.created' => ['customer', 'Vásárló felvétele'],
        'customer.updated' => ['customer', 'Vásárló módosítása'],
        'customer.deleted' => ['customer', 'Vásárló törlése'],

        'invoice.issued' => ['invoice', 'Számla kiállítva'],
        'invoice.failed' => ['invoice', 'Számla kiállítása sikertelen'],
        'settings.szamlazz_changed' => ['settings', 'Számlázz.hu beállítások módosítása'],
        'settings.szamlazz_tested' => ['settings', 'Számlázz.hu kapcsolat-ellenőrzés'],
        'settings.service_changed' => ['settings', 'Egyéb szolgáltatás árának módosítása'],

        'email_template.updated' => ['email', 'E-mail sablon módosítása'],
        'email.sent' => ['email', 'E-mail elküldve'],
        'email.failed' => ['email', 'E-mail küldési hiba'],

        'settings.ticket_prefix_changed' => ['settings', 'Jegyszám előtag módosítása'],
        'settings.ticket_counter_set' => ['settings', 'Jegyszám-számláló beállítása'],
        'settings.ticketing_denied' => ['settings', 'Jegyszámozás módosítása elutasítva'],
        'settings.password_denied' => ['settings', 'Beállítás módosítása elutasítva (hibás jelszó)'],
        'settings.simplepay_changed' => ['settings', 'OTP SimplePay beállítások módosítása'],
        'settings.simplepay_tested' => ['settings', 'OTP SimplePay kapcsolat-ellenőrzés'],

        'payment.simplepay_started' => ['reservation', 'SimplePay fizetés indítása'],
        'payment.simplepay_paid' => ['reservation', 'SimplePay fizetés sikeres'],
        'payment.simplepay_failed' => ['reservation', 'SimplePay fizetés sikertelen'],
        'payment.simplepay_ipn_rejected' => ['reservation', 'SimplePay IPN elutasítva'],

        'settings.nav_changed' => ['settings', 'NAV kapcsolat beállításai módosítva'],
        'settings.nav_schedule_changed' => ['settings', 'Automatikus NAV beküldés beállítása'],
        'nav.tested' => ['report', 'NAV kapcsolat-ellenőrzés'],
        'nav.software_registered' => ['report', 'NAV kiállító szoftver regisztrálása'],
        'nav.sent' => ['report', 'NAV adatszolgáltatás beküldve'],
        'nav.failed' => ['report', 'NAV adatszolgáltatás sikertelen'],
        'nav.send_denied' => ['report', 'NAV beküldés elutasítva'],

        'backup.created' => ['settings', 'Biztonsági mentés készült'],
        'backup.failed' => ['settings', 'Biztonsági mentés sikertelen'],
        'backup.downloaded' => ['settings', 'Mentés letöltése'],
        'backup.deleted' => ['settings', 'Mentés törlése'],
        'backup.uploaded' => ['settings', 'Mentésfájl feltöltése'],
        'backup.schedule_changed' => ['settings', 'Automatikus mentés beállítása'],
        'backup.restore_prepared' => ['settings', 'Visszatöltés előkészítése'],
        'backup.restored' => ['settings', 'Adatbázis visszatöltése'],
        'backup.restore_denied' => ['settings', 'Visszatöltés elutasítva'],
        'backup.restore_failed' => ['settings', 'Visszatöltés sikertelen'],

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
