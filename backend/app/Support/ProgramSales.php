<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A film / előadás értékesítési módját beállító jelölők (ingyenes, csak vásárlás) közös szabályai. */
class ProgramSales
{
    public const RULES = [
        'is_free' => ['nullable', 'boolean'],
        'purchase_only' => ['nullable', 'boolean'],
        'is_private' => ['nullable', 'boolean'],
    ];

    /** A zártkörű előadások a nyilvános felületen rejtettek, a helyszíni jegyeladásban (pos.sell jogosultsággal) látszanak. */
    public static function canSeePrivate(Request $request): bool
    {
        return (bool) $request->user('sanctum')?->hasPermission('pos.sell');
    }

    /** Ingyenes előadás nem lehet "csak vásárlás": az ingyenes jegy kizárólag foglalással igényelhető. */
    public static function assertConsistent(Request $request): void
    {
        if ($request->boolean('is_free') && $request->boolean('purchase_only')) {
            throw ValidationException::withMessages([
                'purchase_only' => 'Az ingyenes előadás nem lehet „Csak vásárlás”, mert ingyenes jegyet csak foglalni lehet.',
            ]);
        }
    }
}
