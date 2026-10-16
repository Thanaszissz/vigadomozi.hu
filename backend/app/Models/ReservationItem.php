<?php

namespace App\Models;

use App\Support\SeatKey;
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
        'category',
    ];

    protected $appends = ['section'];

    public function getSectionAttribute(): ?string
    {
        return SeatKey::section($this->seat_key);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
