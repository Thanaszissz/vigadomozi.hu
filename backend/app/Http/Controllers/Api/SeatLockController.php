<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Showtime;
use App\Services\ReservationMailer;
use App\Services\SeatClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatLockController extends Controller
{
    public function __construct(private SeatClaimService $claims)
    {
    }

    /** Foglalás azonnali (kártyás) fizetéshez: a székek 10 percig zároltak. */
    public function lock(Showtime $showtime, Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $reservation = $this->createReservation($showtime, $data, now()->addMinutes(10));
        } catch (SeatUnavailableException $e) {
            return $this->unavailable($e);
        }

        return response()->json([
            'reservation' => $reservation->load('items'),
            'status' => 'ok',
        ], 201);
    }

    /** Fizetés nélküli foglalás: a székek lejárati idő nélkül zároltak, a fizetés a pénztárban történik. */
    public function hold(Showtime $showtime, Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $reservation = $this->createReservation($showtime, $data, null);
        } catch (SeatUnavailableException $e) {
            return $this->unavailable($e);
        }

        // Visszaigazoló e-mail a megadott címre (a válasz elküldése után)
        app(ReservationMailer::class)->reservationReceived($reservation);

        return response()->json([
            'reservation' => $reservation->load('items'),
            'status' => 'held',
        ], 200);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // Nincs HTML/vezérlőkarakter a nevekben: az adat később admin felületen és e-mailben is megjelenik
            'name' => ['required', 'string', 'max:255', 'regex:/^[^<>\x00-\x1F\x7F]+$/u'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s\/.]{6,20}$/'],
            'zip' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z\- ]+$/'],
            'seatKeys' => ['required', 'array', 'min:1', 'max:20'],
            'seatKeys.*' => ['string', 'max:120', 'distinct'],
        ], ['zip.regex' => 'Az irányítószám csak betűket, számjegyeket és kötőjelet tartalmazhat.']);
    }

    /**
     * A foglalás, a tételek és a zárolások egyetlen tranzakcióban jönnek létre: ütközés esetén semmi nem marad félig kész.
     *
     * @throws SeatUnavailableException
     */
    private function createReservation(Showtime $showtime, array $data, ?\Illuminate\Support\Carbon $expiresAt): Reservation
    {
        $zip = filled($data['zip'] ?? null) ? trim($data['zip']) : null;

        $reservation = DB::transaction(function () use ($showtime, $data, $expiresAt, $zip) {
            $reservation = Reservation::create([
                'showtime_id' => $showtime->id,
                'customer_email' => $data['email'],
                'customer_name' => $data['name'],
                'customer_phone' => $data['phone'],
                'customer_zip' => $zip,
                'status' => 'PENDING',
                'currency' => 'HUF',
                'total_amount' => 0,
                'expires_at' => $expiresAt,
            ]);

            $this->claims->claimSeats($showtime, $reservation, $data['seatKeys'], $expiresAt);

            return $reservation;
        });

        // A vásárló adatai bekerülnek a Vásárlók közé (az admin felület név szerinti javaslataihoz); a hibája nem akaszthatja meg a foglalást
        Customer::remember($data['name'], $data['phone'], $data['email'], $zip);

        return $reservation;
    }

    private function unavailable(SeatUnavailableException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], $e->status);
    }
}
