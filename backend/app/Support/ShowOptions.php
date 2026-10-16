<?php

namespace App\Support;

/** Az előadások / programok (színház, koncert stb.) választható típusai és jelvényei; a frontend theater-show-options.ts-sel egyezik. */
final class ShowOptions
{
    public const CATEGORIES = [
        'szinhaz' => 'Színházi előadás',
        'gyerek' => 'Gyerekelőadás',
        'koncert' => 'Koncert',
        'tanc' => 'Tánc',
        'kiallitas' => 'Kiállítás',
        'egyeb' => 'Egyéb program',
    ];

    public const BADGES = [
        'premier' => 'Bemutató',
        'vendegjatek' => 'Vendégjáték',
        'gyerekeknek' => 'Gyerekeknek',
        'ingyenes' => 'Ingyenes',
    ];

    /** @return array<int,string> */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /** @return array<int,string> */
    public static function badgeKeys(): array
    {
        return array_keys(self::BADGES);
    }
}
