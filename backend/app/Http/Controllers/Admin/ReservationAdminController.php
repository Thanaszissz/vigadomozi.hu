<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Showtime;
use App\Services\ActivityLogger;
use App\Models\SeatLock;
use App\Services\SeatClaimService;
use App\Services\SimplePayService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationAdminController extends Controller
{
    public function index(Showtime $showtime = null)
    {
        $query = Reservation::query();

        if ($showtime) {
            $query->where('showtime_id', $showtime->id);
        }

        return response()->json(
            $query->with(['items', 'showtime.movie', 'showtime.auditorium'])->get()
        );
    }

    public function grouped()
    {
        $showtimes = Showtime::listed()
            ->with([
                'movie',
                'auditorium',
                'reservations.items',
            ])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn($showtime) => $showtime->reservations->isNotEmpty())
            ->values();

        return response()->json($showtimes);
    }

    public function startPayment(Reservation $reservation, Request $request)
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:cash,card'],
        ]);

        if ($reservation->status !== 'PENDING') {
            return response()->json([
                'message' => 'Reservation is not in PENDING status.',
            ], 400);
        }

        if ($data['method'] === 'cash') {
            try {
                DB::transaction(function () use ($reservation, $request) {
                    // A székek zárolása véglegessé válik; ha közben másé lett a szék, a kifizetés nem véglegesíthető
                    app(SeatClaimService::class)->finalizeClaims($reservation);

                    $reservation->update([
                        'status' => 'PAID',
                        'payment_provider' => 'cash',
                        'payment_ref' => 'admin-cash',
                        'expires_at' => null,
                        'handled_by_admin_id' => $request->user()->id,
                    ]);
                });
            } catch (SeatUnavailableException $e) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            app(TicketService::class)->issueForReservation($reservation, 'cash', $request->user(), 'admin');

            return response()->json([
                'status' => 'paid',
                'reservation' => $reservation->load(['items', 'showtime.movie', 'showtime.auditorium']),
            ], 200);
        }

        if ($reservation->expires_at && $reservation->expires_at < now()) {
            return response()->json([
                'message' => 'Reservation has expired.',
            ], 400);
        }

        $demoCheckoutUrl = 'https://checkout.stripe.com/pay/cs_live_demo_' . $reservation->id;

        $reservation->update([
            'payment_provider' => 'card',
            'payment_ref' => 'admin-init',
            'handled_by_admin_id' => $request->user()->id,
        ]);

        ActivityLogger::log(
            'reservation.payment',
            "Kártyás fizetés indítva: foglalás #{$reservation->id}",
            $reservation,
            ['method' => 'card', 'amount' => $reservation->total_amount],
            subjectLabel: "Foglalás #{$reservation->id}"
        );

        return response()->json([
            'status' => 'checkout',
            'checkout_url' => $demoCheckoutUrl,
            'reservation_id' => $reservation->id,
        ], 200);
    }

    /**
     * Függő (ki nem fizetett) foglalás törlése: a foglalás lemondott lesz, a székek felszabadulnak.
     * Kifizetett foglalás nem törölhető, mert már sorszámozott jegyek tartoznak hozzá.
     */
    public function destroy(Reservation $reservation, Request $request)
    {
        $result = DB::transaction(function () use ($reservation, $request) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== 'PENDING') {
                return ['message' => 'Csak függő (ki nem fizetett) foglalás törölhető.', 'status' => 409];
            }

            if ($locked->payment_provider === SimplePayService::PROVIDER) {
                return ['message' => 'Az online fizetés alatt álló foglalás nem törölhető.', 'status' => 409];
            }

            $seats = $locked->items()->pluck('seat_key')->all();

            $locked->update(['status' => 'CANCELLED', 'expires_at' => null]);
            SeatLock::where('reservation_id', $locked->id)->delete();

            ActivityLogger::log(
                'reservation.cancelled',
                sprintf('Foglalás törölve: #%d, %s, %d szék (%s)', $locked->id, $locked->customer_name ?? 'N/A', count($seats), implode(', ', $seats)),
                $locked,
                ['seats' => $seats, 'amount' => $locked->total_amount, 'showtime_id' => $locked->showtime_id],
                $request->user(),
                "Foglalás #{$locked->id}"
            );

            return ['reservation' => $locked->load(['items', 'showtime.movie', 'showtime.auditorium'])];
        });

        if (isset($result['message'])) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json($result['reservation']);
    }

    public function export(Showtime $showtime, Request $request)
    {
        $format = $request->query('format', 'json');

        $reservations = Reservation::where('showtime_id', $showtime->id)
            ->where('status', 'PAID')
            ->with(['items', 'showtime.movie', 'showtime.auditorium'])
            ->get();

        if ($format === 'csv') {
            ActivityLogger::log(
                'report.export_reservations',
                'Foglalások exportálása (CSV): előadás #' . $showtime->id,
                $showtime,
                ['rows' => $reservations->count()],
                subjectLabel: $showtime->program_title
            );

            return $this->exportCSV($reservations, $showtime);
        }

        return response()->json($reservations);
    }

    private function exportCSV($reservations, $showtime)
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reservations_' . (int) $showtime->id . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Táblázatkezelőben a képletként értelmezhető cellák (=, +, -, @) elé aposztróf kerül, így nem futtathatók
        $safe = fn($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'" . $value : $value;

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Foglalás ID', 'Név', 'Telefon', 'Email', 'Sor', 'Szék', 'Ár (Ft)'], ';');

        foreach ($reservations as $res) {
            foreach ($res->items as $item) {
                fputcsv($out, [
                    $res->id,
                    $safe($res->customer_name ?? ''),
                    $safe($res->customer_phone ?? ''),
                    $safe($res->customer_email),
                    $item->row_label,
                    $item->seat_number,
                    $item->price_amount,
                ], ';');
            }
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, $headers);
    }
}
