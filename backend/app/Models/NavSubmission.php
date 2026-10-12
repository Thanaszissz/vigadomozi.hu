<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavSubmission extends Model
{
    protected $fillable = [
        'applicable_date',
        'environment',
        'operation',
        'trigger',
        'status',
        'nav_id',
        'replaces_id',
        'serial_number',
        'sale_count',
        'total_amount',
        'vat_category',
        'request_id',
        'sent_by',
        'error_code',
        'error_message',
        'request_xml',
        'response_xml',
        'sent_at',
    ];

    protected $casts = [
        'applicable_date' => 'date:Y-m-d',
        'sent_at' => 'datetime',
        'sale_count' => 'integer',
        'total_amount' => 'integer',
    ];

    /** A listában a nagy XML-ek nem szerepelnek. */
    protected $hidden = ['request_xml', 'response_xml'];
}
