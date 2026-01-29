<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movie extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'poster_path',
        'trailer_youtube_url',
        'duration_min',
        'rating',
    ];

    protected $appends = ['poster_url', 'duration_minutes'];

    public function showtimes()
    {
        return $this->hasMany(Showtime::class);
    }

    // Accessor for frontend compatibility
    protected function posterUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->poster_path,
        );
    }

    // Accessor for frontend compatibility (duration_minutes)
    protected function durationMinutes(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->duration_min,
        );
    }
}
