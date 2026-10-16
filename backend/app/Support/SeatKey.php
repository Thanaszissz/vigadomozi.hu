<?php

namespace App\Support;

/**
 * A szék kulcsa: szint nélküli sornál "A-05", szintes sornál "Emelet|1-05" (szint|sorjel-székszám).
 * A szint neve így a szék azonosításának része, ezért két szinten is lehet "1. sor 1. szék".
 */
class SeatKey
{
    public static function make(?string $section, string $row, int $number): string
    {
        $prefix = ($section !== null && $section !== '') ? $section . '|' : '';

        return $prefix . $row . '-' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    /** A sor kulcsa a nézőtér térképén (szint|sorjel, szint nélkül csak a sorjel). */
    public static function rowKey(?string $section, string $row): string
    {
        return ($section !== null && $section !== '') ? $section . '|' . $row : $row;
    }

    /** @return array{section: ?string, row: string, number: ?int} */
    public static function parse(string $key): array
    {
        $pos = strrpos($key, '-');
        if ($pos === false) {
            return ['section' => null, 'row' => $key, 'number' => null];
        }

        $rowPart = substr($key, 0, $pos);
        $number = (int) substr($key, $pos + 1);
        $sep = strpos($rowPart, '|');

        return [
            'section' => $sep !== false ? substr($rowPart, 0, $sep) : null,
            'row' => $sep !== false ? substr($rowPart, $sep + 1) : $rowPart,
            'number' => $number ?: null,
        ];
    }

    public static function section(?string $key): ?string
    {
        return $key ? self::parse($key)['section'] : null;
    }

    /** Emberi olvasatú megjelölés: "Emelet 1-5" / "E-5". */
    public static function label(?string $key): string
    {
        if (!$key || $key === '-') {
            return (string) $key;
        }

        $p = self::parse($key);

        return ($p['section'] ? $p['section'] . ' ' : '') . $p['row'] . '-' . ($p['number'] ?? '');
    }
}
