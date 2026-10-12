<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
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
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'sold_at' => 'datetime',
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
