<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SeatUnavailableException;
use App\Exceptions\SimplePayException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Ticket;
use App\Services\SeatClaimService;
use App\Services\TicketAccess;
use App\Services\SimplePayService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReservationController extends Controller
{
    /**
     * A foglalás nyilvános adatai a fizetési és visszaigazoló oldalakhoz. Az azonosító sorszámszerű (kitalálható),
     * ezért a vásárló személyes adatai csak elmaszkolva szerepelnek, így a végigpróbálgatás nem gyűjt be adatot.
     */
    public function show(Reservation $reservation)
    {
        $reservation->load('items', 'showtime.movie', 'showtime.auditorium');
        $data = $reservation->toArray();

        $data['customer_name'] = self::maskName($reservation->customer_name);
        $data['customer_email'] = self::maskEmail($reservation->customer_email);
        $data['customer_phone'] = self::maskPhone($reservation->customer_phone);
        unset($data['payment_ref'], $data['handled_by_admin_id']);

        return response()->json($data, 200);
    }

    private static function maskName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        return collect(preg_split('/\s+/u', trim($name)))
            ->map(fn($part) => mb_substr($part, 0, 1) . '***')
            ->implode(' ');
    }

    private static function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2) . '***@' . mb_substr($domain, 0, 1) . '***' . (str_contains($domain, '.') ? substr($domain, strrpos($domain, '.')) : '');
    }

    private static function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return $phone;
        }

        return str_repeat('*', max(0, mb_strlen($phone) - 3)) . mb_substr($phone, -3);
    }

    /** A kifizetett foglalás jegyei letöltéshez; csak a fizetés utáni átirányításban kapott kulccsal kérhető. */
    public function tickets(Reservation $reservation, Request $request)
    {
        if (!TicketAccess::check($reservation->id, $request->query('k'))) {
            return response()->json(['message' => 'A jegyek letöltési hivatkozása érvénytelen.'], 403);
        }

        $tickets = Ticket::where('reservation_id', $reservation->id)->orderBy('id')->get();
        if ($reservation->status !== 'PAID' || $tickets->isEmpty()) {
            return response()->json(['message' => 'A jegyek még nem állnak készen. Próbáld újra néhány másodperc múlva.'], 409);
        }

        return response()->json($tickets->map(fn(Ticket $t) => [
            'ticket_number' => $t->ticket_number,
            'movie_title' => $t->movie_title,
            'starts_at' => $t->starts_at,
            'auditorium_name' => $t->auditorium_name,
            'row_label' => $t->row_label,
            'seat_number' => $t->seat_number,
            'section' => $t->section,
            'price_amount' => $t->price_amount,
            'sold_at' => $t->sold_at,
        ])->values());
    }

    public function pay(Reservation $reservation, Request $request)
    {
        // Check if reservation is still PENDING and not expired
        if ($reservation->status !== 'PENDING') {
            return response()->json(
                ['message' => 'Reservation is not in PENDING status.'],
                400
            );
        }

        if ($reservation->expires_at && $reservation->expires_at < now()) {
            return response()->json(
                ['message' => 'Reservation has expired.'],
                400
            );
        }

        $reservation->load('items');

        // Ne indíthassunk fizetést olyan foglaláshoz, amelynek székei közben felszabadultak és másé lehettek
        try {
            app(SeatClaimService::class)->assertStillHeld($reservation);
        } catch (SeatUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // Ha az OTP SimplePay be van állítva, a bankkártyás fizetés azon keresztül megy
        $simplePay = app(SimplePayService::class);
        if ($simplePay->isEnabled()) {
            try {
                $paymentUrl = $simplePay->start($reservation);
            } catch (SimplePayException $e) {
                Log::error('SimplePay fizetés indítása sikertelen: ' . $e->getMessage());

                return response()->json(['message' => 'A fizetés indítása most nem sikerült. Kérjük, próbálja meg később.'], $e->status >= 500 ? 502 : $e->status);
            }

            return response()->json([
                'checkout_url' => $paymentUrl,
                'provider' => 'simplepay',
                'reservation_id' => $reservation->id,
            ], 200);
        }

        $checkoutUrl = app(StripeService::class)->createCheckoutSession($reservation);

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'reservation_id' => $reservation->id,
        ], 200);
    }
}

