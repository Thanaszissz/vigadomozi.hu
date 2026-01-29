<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'seat_key',
        'row_label',
        'seat_number',
        'price_amount',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
