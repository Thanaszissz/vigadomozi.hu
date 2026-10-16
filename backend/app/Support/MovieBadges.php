<?php

namespace App\Support;

/** A filmekhez az admin felületen választható jelvények (badge-ek); a kulcsok a frontend movie-badges.ts-sel egyeznek. */
final class MovieBadges
{
    public const LABELS = [
        'premier' => 'Premier',
        'elopremier' => 'Előpremier',
        'magyar' => 'Magyar film',
        'szinkronizalt' => 'Szinkronizált',
        'feliratos' => 'Feliratos',
        '3d' => '3D',
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }
}
