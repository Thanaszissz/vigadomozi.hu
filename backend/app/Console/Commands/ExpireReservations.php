<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\SeatLock;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire pending reservations and release seat locks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Find expired PENDING reservations
        $expired = Reservation::where('status', 'PENDING')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            $reservation->update(['status' => 'EXPIRED']);
            
            // Delete seat locks for this reservation
            SeatLock::where('reservation_id', $reservation->id)->delete();
            
            $this->info("Expired reservation: {$reservation->id}");
        }

        // Also delete orphaned seat locks
        SeatLock::where('expires_at', '<', now())->delete();

        $this->info("Completed expiring {$expired->count()} reservations");
    }
}
