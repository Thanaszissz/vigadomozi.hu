<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\SeatLock;
use App\Models\Showtime;
use App\Services\ActivityLogger;
use App\Services\ReservationMailer;
use App\Services\SeatClaimService;
use App\Services\SimplePayService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    private const WALK_IN_EMAIL = 'helyszini@vigadomozi.hu';

    // Az előadás kezdete után ennyi percig még adható el jegy
    private const SALES_GRACE_MINUTES = 30;

    public function __construct(private TicketService $tickets, private SeatClaimService $claims)
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
            // A foglalás, a székek zárolása és a fizetés egyetlen tranzakció: ütközésnél semmi nem marad félig kész
            $reservation = DB::transaction(function () use ($showtime, $data, $request) {
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

                $this->claims->claimSeats($showtime, $reservation, $data['seatKeys'], null);

                return $this->markPaid($reservation, $data['payment_method'], $request);
            });
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($this->payload($reservation), 201);
    }

    /**
     * Helyszíni foglalás fizetés nélkül: a székek lejárati idő nélkül zároltak, a foglalás a foglalások között jelenik meg,
     * és később a pénztárban kifizethető. Név és telefonszám kötelező, az e-mail nem.
     */
    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'showtime_id' => ['required', 'integer', 'exists:showtimes,id'],
            'seatKeys' => ['required', 'array', 'min:1', 'max:20'],
            'seatKeys.*' => ['string', 'max:32', 'distinct'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_email' => ['nullable', 'email', 'max:255'],
        ]);

        $showtime = Showtime::with(['movie', 'auditorium'])->findOrFail($data['showtime_id']);

        if ($error = $this->salesClosedReason($showtime)) {
            return response()->json(['message' => $error], 422);
        }

        $email = trim((string) ($data['customer_email'] ?? ''));

        try {
            $reservation = DB::transaction(function () use ($showtime, $data, $email) {
                $reservation = Reservation::create([
                    'showtime_id' => $showtime->id,
                    'customer_email' => $email !== '' ? $email : self::WALK_IN_EMAIL,
                    'customer_name' => $data['customer_name'],
                    'customer_phone' => $data['customer_phone'],
                    'status' => 'PENDING',
                    'currency' => 'HUF',
                    'total_amount' => 0,
                    'expires_at' => null,
                ]);

                $this->claims->claimSeats($showtime, $reservation, $data['seatKeys'], null);

                return $reservation;
            });
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        ActivityLogger::log(
            'reservation.created',
            sprintf('Helyszíni foglalás: #%d, %s, %d szék (%s)', $reservation->id, $data['customer_name'], count($data['seatKeys']), implode(', ', $data['seatKeys'])),
            $reservation,
            ['seats' => $data['seatKeys'], 'amount' => $reservation->total_amount, 'showtime_id' => $showtime->id],
            subjectLabel: "Foglalás #{$reservation->id}"
        );

        // Visszaigazoló e-mail csak akkor megy, ha a vásárló megadott címet
        if ($email !== '') {
            app(ReservationMailer::class)->reservationReceived($reservation);
        }

        return response()->json($this->payload($reservation), 201);
    }

    /**
     * Függő foglalás módosítása: vásárlói adatok és/vagy a székek cseréje.
     * Kifizetett és online fizetés alatt álló foglalás nem módosítható.
     */
    public function updateReservation(Reservation $reservation, Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'seatKeys' => ['sometimes', 'array', 'min:1', 'max:20'],
            'seatKeys.*' => ['string', 'max:32', 'distinct'],
        ]);

        try {
            $result = DB::transaction(function () use ($reservation, $data, $request) {
                $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

                if (!$locked || $locked->status !== 'PENDING') {
                    return ['message' => 'Csak függő (ki nem fizetett) foglalás módosítható.', 'status' => 409];
                }
                if ($locked->payment_provider === SimplePayService::PROVIDER) {
                    return ['message' => 'Az online fizetés alatt álló foglalás nem módosítható.', 'status' => 409];
                }

                $locked->load('showtime.movie', 'showtime.auditorium', 'items');
                $before = $locked->items->pluck('seat_key')->sort()->values()->all();
                $wanted = isset($data['seatKeys']) ? collect($data['seatKeys'])->sort()->values()->all() : $before;
                $removed = array_values(array_diff($before, $wanted));
                $added = array_values(array_diff($wanted, $before));

                if (($removed || $added) && $locked->showtime && ($error = $this->salesClosedReason($locked->showtime))) {
                    return ['message' => $error, 'status' => 422];
                }

                $email = trim((string) ($data['customer_email'] ?? ''));
                $locked->update([
                    'customer_name' => $data['customer_name'],
                    'customer_phone' => $data['customer_phone'],
                    'customer_email' => $email !== '' ? $email : self::WALK_IN_EMAIL,
                ]);

                if ($removed) {
                    $locked->items()->whereIn('seat_key', $removed)->delete();
                    SeatLock::where('reservation_id', $locked->id)->whereIn('seat_key', $removed)->delete();
                }
                if ($added) {
                    $this->claims->claimSeats($locked->showtime, $locked, $added, null);
                }
                if ($removed || $added) {
                    $locked->update(['total_amount' => (int) $locked->items()->sum('price_amount')]);
                }

                ActivityLogger::log(
                    'reservation.updated',
                    sprintf('Foglalás módosítva: #%d, %s%s%s', $locked->id, $data['customer_name'], $added ? ', új székek: ' . implode(', ', $added) : '', $removed ? ', elvett székek: ' . implode(', ', $removed) : ''),
                    $locked,
                    ['added' => $added, 'removed' => $removed, 'amount' => $locked->total_amount, 'showtime_id' => $locked->showtime_id],
                    $request->user(),
                    "Foglalás #{$locked->id}"
                );

                return ['reservation' => $locked];
            });
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        if (isset($result['message'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json($this->payload($result['reservation']->fresh()));
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

        try {
            $reservation = DB::transaction(
                fn() => $this->markPaid($reservation, $data['payment_method'], $request)
            );
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($this->payload($reservation));
    }

    public function reservationTickets(Reservation $reservation): JsonResponse
    {
        return response()->json(
            $reservation->tickets()->orderBy('id')->get()
        );
    }

    /** @throws SeatUnavailableException */
    private function markPaid(Reservation $reservation, string $method, Request $request): Reservation
    {
        // A székek zárolása nem törlődik, hanem véglegessé válik: az eladott szék nem adható ki újra
        $this->claims->finalizeClaims($reservation);

        $reservation->update([
            'status' => 'PAID',
            'payment_provider' => $method,
            'payment_ref' => 'pos',
            'expires_at' => null,
            'handled_by_admin_id' => $request->user()->id,
        ]);

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
