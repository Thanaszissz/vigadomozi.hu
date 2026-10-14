<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'reservation_id',
        'provider',
        'invoice_number',
        'buyer_name',
        'buyer_json',
        'payment_method',
        'vat_rate',
        'net_amount',
        'vat_amount',
        'gross_amount',
        'pdf_path',
        'customer_url',
        'issued_by_user_id',
        'issued_by_name',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    protected $hidden = ['buyer_json', 'pdf_path'];

    protected $appends = ['has_pdf'];

    public function getHasPdfAttribute(): bool
    {
        return (bool) $this->pdf_path;
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
