<?php

namespace App\Services;

/**
 * A vásárló jegyeinek letöltéséhez használt kulcs. A foglalás azonosítója sorszámszerű (kitalálható),
 * ezért a jegyek csak az alkalmazás titkos kulcsából képzett, kitalálhatatlan kulccsal kérhetők le.
 * A kulcs a fizetés utáni átirányításban (és nem a nyilvános foglalás-adatokban) szerepel.
 */
class TicketAccess
{
    public static function key(int $reservationId): string
    {
        return substr(hash_hmac('sha256', 'tickets:' . $reservationId, (string) config('app.key')), 0, 32);
    }

    public static function check(int $reservationId, ?string $key): bool
    {
        return is_string($key) && $key !== '' && hash_equals(self::key($reservationId), $key);
    }
}
