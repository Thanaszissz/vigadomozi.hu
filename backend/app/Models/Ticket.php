<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    /** A sztornózott jegyek alapból sehol nem jelennek meg (statisztika, archívum, NAV); a hozzáféréshez withoutGlobalScopes() kell. */
    protected static function booted(): void
    {
        static::addGlobalScope('not_voided', fn(Builder $query) => $query->whereNull($query->getModel()->getTable() . '.voided_at'));
    }

    protected $fillable = [
        'ticket_year',
        'ticket_prefix',
        'ticket_seq',
        'ticket_number',
        'reservation_id',
        'showtime_id',
        'movie_title',
        'starts_at',
        'auditorium_name',
        'seat_key',
        'row_label',
        'seat_number',
        'price_amount',
        'currency',
        'payment_method',
        'channel',
        'sold_by_user_id',
        'sold_by_name',
        'sold_at',
        'voided_at',
        'voided_by_user_id',
        'voided_by_name',
        'void_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'sold_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function showtime()
    {
        return $this->belongsTo(Showtime::class);
    }
}
