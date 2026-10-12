<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Services\PricingService;
use App\Services\SeatMapService;
use App\Services\TicketService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    private const WALK_IN_EMAIL = 'helyszini@vigadomozi.hu';

    // Az előadás kezdete után ennyi percig még adható el jegy
    private const SALES_GRACE_MINUTES = 30;

    public function __construct(private TicketService $tickets)
    {
    }

    /**
     * Új helyszíni eladás: a kiválasztott székek lefoglalása és azonnali kifizetése.
     */
    public function sell(Request $request): JsonResponse
    {
        $data = $request->validate([
            'showtime_id' => ['required', 'integer', 'exists:showtimes,id'],
            'seatKeys' => ['required', 'array', 'min:1', 'max:20'],
            'seatKeys.*' => ['string', 'max:32', 'distinct'],
            'payment_method' => ['required', 'string', 'in:cash,card'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $showtime = Showtime::with(['movie', 'auditorium'])->findOrFail($data['showtime_id']);

        if ($error = $this->salesClosedReason($showtime)) {
            return response()->json(['message' => $error], 422);
        }

        try {
            $reservation = DB::transaction(function () use ($showtime, $data, $request) {
                SeatLock::where('showtime_id', $showtime->id)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->delete();

                $seatMap = app(SeatMapService::class)->getFullSeatMap($showtime, app(PricingService::class));
                $seats = [];
                foreach ($seatMap['seats'] as $rowSeats) {
                    foreach ($rowSeats as $key => $seat) {
                        $seats[$key] = $seat;
                    }
                }

                foreach ($data['seatKeys'] as $key) {
                    $seat = $seats[$key] ?? null;
                    if (!$seat || !$seat['bookable'] || $seat['type'] === 'aisle') {
                        abort(422, "A(z) {$key} szék nem értékesíthető.");
                    }
                    if ($seat['status'] !== 'available') {
                        abort(409, "A(z) {$key} szék már foglalt vagy eladott.");
                    }
                }

                $reservation = Reservation::create([
                    'showtime_id' => $showtime->id,
                    'customer_email' => self::WALK_IN_EMAIL,
                    'customer_name' => $data['customer_name'] ?? null ?: 'Helyszíni vásárló',
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'status' => 'PENDING',
                    'currency' => 'HUF',
                    'total_amount' => 0,
                    'expires_at' => null,
                ]);

                $total = 0;
                foreach ($data['seatKeys'] as $key) {
                    $price = (int) $seats[$key]['price'];
                    [$rowLabel, $number] = array_pad(explode('-', $key), 2, null);

                    ReservationItem::create([
                        'reservation_id' => $reservation->id,
                        'seat_key' => $key,
                        'row_label' => $rowLabel,
                        'seat_number' => intval($number) ?: null,
                        'price_amount' => $price,
                    ]);

                    // Az egyedi (showtime, szék) kulcs védi az egyidejű eladásokat
                    SeatLock::create([
                        'showtime_id' => $showtime->id,
                        'reservation_id' => $reservation->id,
                        'seat_key' => $key,
                        'expires_at' => null,
                    ]);

                    $total += $price;
                }

                $reservation->update(['total_amount' => $total]);

                return $this->markPaid($reservation, $data['payment_method'], $request);
            });
        } catch (QueryException $e) {
            return response()->json(['message' => 'Néhány szék közben már foglalttá vált. Frissítse az előadást.'], 409);
        }

        return response()->json($this->payload($reservation), 201);
    }

    /**
     * Meglévő (függő) foglalás kifizetése a pénztárban.
     */
    public function payReservation(Reservation $reservation, Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'in:cash,card'],
        ]);

        $reservation->load('showtime.movie', 'showtime.auditorium');

        if ($reservation->status !== 'PENDING') {
            return response()->json(['message' => 'A foglalás nem függő állapotú.'], 422);
        }

        if ($reservation->expires_at && $reservation->expires_at < now()) {
            return response()->json(['message' => 'A foglalás lejárt.'], 422);
        }

        if ($reservation->showtime && ($error = $this->salesClosedReason($reservation->showtime))) {
            return response()->json(['message' => $error], 422);
        }

        $reservation = DB::transaction(
            fn() => $this->markPaid($reservation, $data['payment_method'], $request)
        );

        return response()->json($this->payload($reservation));
    }

    public function reservationTickets(Reservation $reservation): JsonResponse
    {
        return response()->json(
            $reservation->tickets()->orderBy('id')->get()
        );
    }

    private function markPaid(Reservation $reservation, string $method, Request $request): Reservation
    {
        $reservation->update([
            'status' => 'PAID',
            'payment_provider' => $method,
            'payment_ref' => 'pos',
            'expires_at' => null,
            'handled_by_admin_id' => $request->user()->id,
        ]);

        SeatLock::where('reservation_id', $reservation->id)->delete();

        $this->tickets->issueForReservation($reservation, $method, $request->user(), 'pos');

        return $reservation;
    }

    private function payload(Reservation $reservation): array
    {
        $reservation->load('items', 'showtime.movie', 'showtime.auditorium');

        return [
            'reservation' => $reservation,
            'tickets' => $reservation->tickets()->orderBy('id')->get(),
        ];
    }

    private function salesClosedReason(Showtime $showtime): ?string
    {
        if ($showtime->status !== 'active') {
            return 'Ez az előadás nem aktív.';
        }

        if ($showtime->starts_at->copy()->addMinutes(self::SALES_GRACE_MINUTES)->isPast()) {
            return 'Erre az előadásra már lezárult a jegyeladás.';
        }

        return null;
    }
}
