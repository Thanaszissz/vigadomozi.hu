<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraService extends Model
{
    protected $fillable = ['name', 'price', 'sort_order'];

    protected $casts = [
        'price' => 'integer',
        'sort_order' => 'integer',
    ];
}
