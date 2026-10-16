<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Auditorium extends Model
{
    use HasFactory;

    protected $table = 'auditoria';

    protected $fillable = [
        'name',
        'rows',
        'columns',
        'total_seats',
        'layout_json',
        'style_json',
    ];

    protected $casts = [
        'layout_json' => 'array',
        'style_json' => 'array',
    ];

    /** Az összes férőhely a felvett (nem tiltott) székekből számolódik; elrendezés híján a tárolt érték marad. */
    protected function totalSeats(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $layout = json_decode($attributes['layout_json'] ?? 'null', true);
                if (is_array($layout) && isset($layout['seats']) && is_array($layout['seats'])) {
                    return count(array_filter($layout['seats'], fn ($seat) => empty($seat['blocked'])));
                }

                return (int) $value;
            }
        );
    }

    public function showtimes()
    {
        return $this->hasMany(Showtime::class, 'auditorium_id');
    }
}
