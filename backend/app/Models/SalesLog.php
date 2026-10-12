<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLog extends Model
{
    protected $fillable = [
        'occurred_at',
        'user_id',
        'user_name',
        'channel',
        'reservation_id',
        'showtime_id',
        'movie_title',
        'starts_at',
        'ticket_count',
        'total_amount',
        'payment_method',
        'ticket_numbers',
        'ip_address',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'starts_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
