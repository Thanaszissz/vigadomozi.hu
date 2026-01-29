<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Showtime;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\SeatLock;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatLockController extends Controller
{
    public function lock(Showtime $showtime, Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'seatKeys' => ['required', 'array', 'min:1'],
            'seatKeys.*' => ['string', 'max:32'],
        ]);

        return DB::transaction(function () use ($showtime, $data) {
            // 1) Delete expired locks
            SeatLock::where('showtime_id', $showtime->id)
                ->where('expires_at', '<', now())
                ->delete();

            // 2) Check if any seat is already PAID
            $alreadyBooked = ReservationItem::query()
                ->whereHas('reservation', fn($q) => $q
                    ->where('showtime_id', $showtime->id)
                    ->where('status', 'PAID')
                )
                ->whereIn('seat_key', $data['seatKeys'])
                ->exists();

            if ($alreadyBooked) {
                return response()->json(
                    ['message' => 'One or more seats already booked.'],
                    409
                );
            }

            // 3) Check for active locks
            $locked = SeatLock::where('showtime_id', $showtime->id)
                ->whereIn('seat_key', $data['seatKeys'])
                ->exists();

            if ($locked) {
                return response()->json(
                    ['message' => 'One or more seats temporarily locked.'],
                    409
                );
            }

            // 4) Create reservation + items + locks
            $reservation = Reservation::create([
                'showtime_id' => $showtime->id,
                'customer_email' => $data['email'],
                'customer_name' => $data['name'],
                'customer_phone' => $data['phone'],
                'status' => 'PENDING',
                'currency' => 'HUF',
                'total_amount' => 0,
                'expires_at' => now()->addMinutes(10),
            ]);

            $pricingService = app(PricingService::class);
            $total = 0;

            foreach ($data['seatKeys'] as $seatKey) {
                $price = $pricingService->priceForSeat($showtime, $seatKey);

                ReservationItem::create([
                    'reservation_id' => $reservation->id,
                    'seat_key' => $seatKey,
                    'row_label' => explode('-', $seatKey)[0],
                    'seat_number' => intval(explode('-', $seatKey)[1] ?? 0) ?: null,
                    'price_amount' => $price,
                ]);

                SeatLock::create([
                    'showtime_id' => $showtime->id,
                    'reservation_id' => $reservation->id,
                    'seat_key' => $seatKey,
                    'expires_at' => $reservation->expires_at,
                ]);

                $total += $price;
            }

            $reservation->update(['total_amount' => $total]);

            return response()->json([
                'reservation' => $reservation->load('items'),
                'status' => 'ok',
            ], 201);
        });
    }
    public function hold(Showtime $showtime, Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'seatKeys' => ['required', 'array', 'min:1'],
            'seatKeys.*' => ['string', 'max:32'],
        ]);

        // 1) Delete expired locks
        SeatLock::where('showtime_id', $showtime->id)
            ->where('expires_at', '<', now())
            ->delete();

        // 2) Check if any seat is already PAID
        $alreadyBooked = ReservationItem::query()
            ->whereHas('reservation', fn($q) => $q
                ->where('showtime_id', $showtime->id)
                ->where('status', 'PAID')
            )
            ->whereIn('seat_key', $data['seatKeys'])
            ->exists();

        if ($alreadyBooked) {
            return response()->json(
                ['message' => 'One or more seats already booked.'],
                409
            );
        }

        // 3) Check for active locks
        $locked = SeatLock::where('showtime_id', $showtime->id)
            ->whereIn('seat_key', $data['seatKeys'])
            ->exists();

        if ($locked) {
            return response()->json(
                ['message' => 'One or more seats temporarily locked.'],
                409
            );
        }

        // 4) Create reservation + items + locks WITHOUT expiry
        $reservation = Reservation::create([
            'showtime_id' => $showtime->id,
            'customer_email' => $data['email'],
            'customer_name' => $data['name'],
            'customer_phone' => $data['phone'],
            'status' => 'PENDING',
            'currency' => 'HUF',
            'total_amount' => 0,
            'expires_at' => null, // No expiry for hold reservations
        ]);

        $pricingService = app(PricingService::class);
        $total = 0;

        foreach ($data['seatKeys'] as $seatKey) {
            $price = $pricingService->priceForSeat($showtime, $seatKey);

            ReservationItem::create([
                'reservation_id' => $reservation->id,
                'seat_key' => $seatKey,
                'row_label' => explode('-', $seatKey)[0],
                'seat_number' => intval(explode('-', $seatKey)[1] ?? 0) ?: null,
                'price_amount' => $price,
            ]);

            SeatLock::create([
                'showtime_id' => $showtime->id,
                'reservation_id' => $reservation->id,
                'seat_key' => $seatKey,
                'expires_at' => null, // No expiry for seat locks
            ]);

            $total += $price;
        }

        $reservation->update(['total_amount' => $total]);

        return response()->json([
            'reservation' => $reservation->load('items'),
            'status' => 'held',
        ], 200);
    }
}
