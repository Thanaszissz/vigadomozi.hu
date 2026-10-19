<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
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
        'film_info',
        'badges',
    ];

    protected $casts = [
        'badges' => 'array',
    ];

    protected $appends = ['poster_url', 'duration_minutes'];

    public function showtimes()
    {
        return $this->hasMany(Showtime::class);
    }

    /** A listákban csak az látszik, aminek van (vagy az elmúlt napokban volt) előadása, illetve frissen felvett (lásd Showtime::PROGRAM_GRACE_DAYS). */
    public function scopeListed(Builder $query): Builder
    {
        $cutoff = now()->subDays(Showtime::PROGRAM_GRACE_DAYS);

        return $query->where(fn(Builder $q) => $q
            ->whereHas('showtimes', fn(Builder $s) => $s->where('starts_at', '>=', $cutoff))
            ->orWhere($query->getModel()->getTable() . '.created_at', '>=', $cutoff));
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
