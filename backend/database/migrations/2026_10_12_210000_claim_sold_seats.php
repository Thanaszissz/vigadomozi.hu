<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Az eladott székek eddig fizetéskor elvesztették a zárolásukat, ezért csak egy lekérdezés védte őket.
     * Mostantól minden eladott székhez végleges zárolás tartozik, amely adatbázis-szinten egyedi.
     */
    public function up(): void
    {
        $now = now();

        $sold = DB::table('reservation_items as i')
            ->join('reservations as r', 'r.id', '=', 'i.reservation_id')
            ->where('r.status', 'PAID')
            ->orderBy('r.id')
            ->select('r.showtime_id', 'i.seat_key', 'r.id as reservation_id')
            ->get();

        $conflicts = 0;
        foreach ($sold as $row) {
            $inserted = DB::table('seat_locks')->insertOrIgnore([
                'showtime_id' => $row->showtime_id,
                'seat_key' => $row->seat_key,
                'reservation_id' => $row->reservation_id,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (!$inserted && !DB::table('seat_locks')->where('showtime_id', $row->showtime_id)->where('seat_key', $row->seat_key)->where('reservation_id', $row->reservation_id)->exists()) {
                $conflicts++;
                Log::warning('Szék kétszer eladva (meglévő adat)', ['showtime_id' => $row->showtime_id, 'seat' => $row->seat_key, 'reservation_id' => $row->reservation_id]);
            }
        }

        // A már meglévő zárolások a kifizetett foglalásoknál legyenek véglegesek
        DB::table('seat_locks')
            ->whereIn('reservation_id', DB::table('reservations')->where('status', 'PAID')->select('id'))
            ->update(['expires_at' => null]);

        if ($conflicts > 0) {
            Log::critical("Jegyeladási ütközés a meglévő adatokban: {$conflicts} szék többször is el van adva.");
        }
    }

    public function down(): void
    {
        // A végleges zárolások a kifizetett foglalásokhoz tartoznak; visszalépéskor nincs teendő
    }
};
