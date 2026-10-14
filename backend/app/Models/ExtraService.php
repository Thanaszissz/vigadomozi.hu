<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraService extends Model
{
    /** Egyéb szolgáltatás, a pénztár "Egyéb szolgáltatások" menüjében értékesíthető. */
    public const KIND_SERVICE = 'service';

    /** A Dolgozó jegy: székhez kötött, a jegyeladásnál a székválasztáskor adható, ára a beállított ár. */
    public const KIND_STAFF = 'staff_ticket';

    protected $fillable = ['name', 'price', 'kind', 'sort_order'];

    protected $casts = [
        'price' => 'integer',
        'sort_order' => 'integer',
    ];

    /** A Dolgozó jegy beállított ára (0, ha nincs ilyen tétel). */
    public static function staffPrice(): int
    {
        return (int) (static::where('kind', self::KIND_STAFF)->value('price') ?? 0);
    }
}
