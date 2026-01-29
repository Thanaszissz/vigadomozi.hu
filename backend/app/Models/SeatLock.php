<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeatLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'showtime_id',
        'seat_key',
        'reservation_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function showtime()
    {
        return $this->belongsTo(Showtime::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
