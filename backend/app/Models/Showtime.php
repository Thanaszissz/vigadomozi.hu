<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Showtime extends Model
{
    use HasFactory;

    /** A lejárt előadások ennyi nap után kikerülnek a listákból (az eladott jegyek és a statisztikák nem érintettek). */
    public const EXPIRED_GRACE_DAYS = 1;

    /** Az előadás nélküli film / program ennyi nap után kikerül a listákból. */
    public const PROGRAM_GRACE_DAYS = 3;

    /** Csak azok az előadások, amelyek nem jártak le egy napnál régebben. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable() . '.starts_at', '>=', now()->subDays(self::EXPIRED_GRACE_DAYS));
    }

    protected $fillable = [
        'movie_id',
        'theater_show_id',
        'auditorium_id',
        'starts_at',
        'sales_open_at',
        'sales_close_at',
        'pricing_override_json',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'sales_open_at' => 'datetime',
        'sales_close_at' => 'datetime',
        'pricing_override_json' => 'array',
    ];

    // Az előadás-időpontoknál a program (előadás) mindig betöltődik, hogy a JSON-ban a film helyén megjelenhessen
    protected $with = ['theaterShow'];

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function theaterShow()
    {
        return $this->belongsTo(TheaterShow::class, 'theater_show_id');
    }

    /** A film vagy az előadás címe (jegyekhez, e-mailekhez, naplóhoz). */
    public function getProgramTitleAttribute(): ?string
    {
        return $this->movie?->title ?? $this->theaterShow?->title;
    }

    /**
     * Előadás-időpontnál a `movie` kulcs az előadás film-szerű adataival töltődik ki (cím, plakát, jelvények...),
     * így a foglalási, pénztári és admin felületek külön módosítás nélkül is a helyes címet mutatják.
     */
    public function toArray()
    {
        $array = parent::toArray();
        $array['is_theater'] = $this->theater_show_id !== null;

        if ($this->theater_show_id !== null && array_key_exists('movie', $array) && $array['movie'] === null && $this->theaterShow) {
            $show = $this->theaterShow;
            $array['movie'] = [
                'id' => $show->id,
                'title' => $show->title,
                'description' => $show->description,
                'poster_path' => $show->poster_path,
                'poster_url' => $show->poster_path,
                'trailer_youtube_url' => $show->trailer_youtube_url,
                'duration_min' => $show->duration_min,
                'duration_minutes' => $show->duration_min,
                'rating' => null,
                'film_info' => $show->show_info,
                'badges' => $show->badges,
                'category' => $show->category,
                'performer' => $show->performer,
                'venue' => $show->venue,
                'ticket_price' => $show->ticket_price,
                'is_theater' => true,
            ];
        }

        return $array;
    }

    public function auditorium()
    {
        return $this->belongsTo(Auditorium::class, 'auditorium_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function seatLocks()
    {
        return $this->hasMany(SeatLock::class);
    }
}
