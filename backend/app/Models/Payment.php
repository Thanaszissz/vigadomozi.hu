<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'reservation_id',
        'provider',
        'order_ref',
        'transaction_id',
        'status',
        'amount',
        'currency',
        'environment',
        'last_event',
        'completed_at',
        'details',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'details' => 'array',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
