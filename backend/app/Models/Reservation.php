<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'showtime_id',
        'user_id',
        'handled_by_admin_id',
        'customer_email',
        'customer_name',
        'customer_phone',
        'status',
        'total_amount',
        'currency',
        'payment_provider',
        'payment_ref',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function showtime()
    {
        return $this->belongsTo(Showtime::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function handledByAdmin()
    {
        return $this->belongsTo(User::class, 'handled_by_admin_id');
    }

    public function items()
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function seatLocks()
    {
        return $this->hasMany(SeatLock::class);
    }
}
