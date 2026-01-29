<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Auditorium extends Model
{
    use HasFactory;

    protected $table = 'auditoria';

    protected $fillable = [
        'name',
        'rows',
        'columns',
        'total_seats',
        'layout_json',
        'style_json',
    ];

    protected $casts = [
        'layout_json' => 'array',
        'style_json' => 'array',
    ];

    public function showtimes()
    {
        return $this->hasMany(Showtime::class, 'auditorium_id');
    }
}
