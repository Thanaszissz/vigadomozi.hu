<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TheaterShow extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'category',
        'performer',
        'venue',
        'show_info',
        'ticket_price',
        'description',
        'poster_path',
        'trailer_youtube_url',
        'duration_min',
        'rating',
        'badges',
    ];

    protected $casts = [
        'badges' => 'array',
        'ticket_price' => 'integer',
    ];

    protected $appends = ['poster_url', 'duration_minutes'];

    protected function posterUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->poster_path,
        );
    }

    protected function durationMinutes(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->duration_min,
        );
    }
}
