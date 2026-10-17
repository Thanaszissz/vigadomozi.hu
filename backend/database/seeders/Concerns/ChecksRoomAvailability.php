<?php

namespace Database\Seeders\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ChecksRoomAvailability
{
    /** Szünet két előadás között (perc). */
    private int $roomBufferMinutes = 20;

    /** A terem szabad-e az adott kezdéstől (UTC) a megadott hosszra, a többi időpont hosszát és a szünetet is figyelembe véve. */
    protected function roomIsFree(int $auditoriumId, Carbon $startsAtUtc, int $durationMin): bool
    {
        $start = $startsAtUtc->copy();
        $end = $start->copy()->addMinutes($durationMin + $this->roomBufferMinutes);

        $existing = DB::table('showtimes')
            ->leftJoin('movies', 'movies.id', '=', 'showtimes.movie_id')
            ->leftJoin('theater_shows', 'theater_shows.id', '=', 'showtimes.theater_show_id')
            ->where('showtimes.auditorium_id', $auditoriumId)
            ->whereBetween('showtimes.starts_at', [$start->copy()->subHours(8)->format('Y-m-d H:i:s'), $end->copy()->addHours(8)->format('Y-m-d H:i:s')])
            ->get(['showtimes.starts_at', DB::raw('COALESCE(movies.duration_min, theater_shows.duration_min, 120) as duration')]);

        foreach ($existing as $row) {
            $s = Carbon::parse($row->starts_at, 'UTC');
            $e = $s->copy()->addMinutes((int) $row->duration + $this->roomBufferMinutes);
            if ($s < $end && $e > $start) {
                return false;
            }
        }

        return true;
    }
}
