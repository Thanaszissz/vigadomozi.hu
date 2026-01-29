<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Showtime extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'auditorium_id',
        'starts_at',
        'sales_open_at',
        'sales_close_at',
        'pricing_override_json',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'sales_open_at' => 'datetime',
        'sales_close_at' => 'datetime',
        'pricing_override_json' => 'array',
    ];

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function auditorium()
    {
        return $this->belongsTo(Auditorium::class, 'auditorium_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function seatLocks()
    {
        return $this->hasMany(SeatLock::class);
    }
}
