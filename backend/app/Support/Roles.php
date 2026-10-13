<?php

namespace App\Support;

/**
 * A szerepkörök és jogosultságaik egyetlen helyen. A szerepkörök jogait itt lehet módosítani.
 */
final class Roles
{
    public const CASHIER = 'cashier';
    public const MANAGER = 'manager';
    public const ADMIN = 'admin';

    public const LABELS = [
        self::CASHIER => 'Pénztáros',
        self::MANAGER => 'Vezető',
        self::ADMIN => 'Adminisztrátor',
    ];

    public const PERMISSIONS = [
        'pos.sell' => 'Helyszíni jegyeladás',
        'tickets.void' => 'Eladott jegy sztornózása',
        'reservations.manage' => 'Foglalások kezelése és kifizetése',
        'daily_close.view' => 'Napi zárás (a saját eladásokról)',
        'daily_close.all' => 'Napi zárás (minden eladóról)',
        'catalog.manage' => 'Filmek, színházi előadások, előadások és termek kezelése',
        'tickets.archive' => 'Eladott jegyek archívuma és exportja',
        'reports.view' => 'Riportok és foglalás-exportok',
        'email.manage' => 'E-mail sablonok szerkesztése',
        'settings.manage' => 'Beállítások (jegyszámozás, biztonsági mentés és visszatöltés)',
        'users.manage' => 'Felhasználók és szerepkörök kezelése',
        'activity.view' => 'Tevékenységnapló megtekintése',
    ];

    private const CASHIER_PERMISSIONS = ['pos.sell', 'tickets.void', 'reservations.manage', 'daily_close.view'];

    private const MANAGER_EXTRA = ['daily_close.all', 'catalog.manage', 'tickets.archive', 'reports.view', 'email.manage'];

    /** @return array<int,string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(?string $role): bool
    {
        return $role !== null && isset(self::LABELS[$role]);
    }

    public static function label(?string $role): string
    {
        return self::LABELS[$role] ?? 'Ismeretlen';
    }

    /** @return array<int,string> */
    public static function permissionsFor(?string $role): array
    {
        return match ($role) {
            self::ADMIN => array_keys(self::PERMISSIONS),
            self::MANAGER => array_merge(self::CASHIER_PERMISSIONS, self::MANAGER_EXTRA),
            self::CASHIER => self::CASHIER_PERMISSIONS,
            default => [],
        };
    }
}
