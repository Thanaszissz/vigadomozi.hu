<?php

namespace App\Support;

/** A jegyek kategóriái az elszámoláshoz. */
final class TicketCategory
{
    public const STANDARD = 'standard';
    public const STAFF = 'staff';
    public const EXPERIENCE_CARD = 'experience_card';
    public const SERVICE = 'service';

    public const LABELS = [
        self::STANDARD => 'Normál jegy',
        self::STAFF => 'Dolgozó jegy',
        self::EXPERIENCE_CARD => 'Élménykártya',
        self::SERVICE => 'Egyéb szolgáltatás',
    ];

    /** A székválasztáskor kiválasztható kategóriák. */
    public const SEAT = [self::STANDARD, self::STAFF, self::EXPERIENCE_CARD];

    public static function label(?string $category): string
    {
        return self::LABELS[$category ?? self::STANDARD] ?? 'Normál jegy';
    }

    public static function normalizeSeat(?string $category): string
    {
        return in_array($category, self::SEAT, true) ? $category : self::STANDARD;
    }
}
