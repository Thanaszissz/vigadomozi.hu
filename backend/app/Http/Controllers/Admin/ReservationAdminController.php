<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\SeatLock;
use App\Models\Showtime;
use Illuminate\Http\Request;

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
        $showtimes = Showtime::query()
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
            $reservation->update([
                'status' => 'PAID',
                'payment_provider' => 'cash',
                'payment_ref' => 'admin-cash',
                'expires_at' => null,
                'handled_by_admin_id' => $request->user()->id,
            ]);

            SeatLock::where('reservation_id', $reservation->id)->delete();

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

        return response()->json([
            'status' => 'checkout',
            'checkout_url' => $demoCheckoutUrl,
            'reservation_id' => $reservation->id,
        ], 200);
    }

    public function export(Showtime $showtime, Request $request)
    {
        $format = $request->query('format', 'json');
        
        $reservations = Reservation::where('showtime_id', $showtime->id)
            ->where('status', 'PAID')
            ->with(['items', 'showtime.movie', 'showtime.auditorium'])
            ->get();

        if ($format === 'csv') {
            return $this->exportCSV($reservations, $showtime);
        }

        return response()->json($reservations);
    }

    private function exportCSV($reservations, $showtime)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="reservations_' . $showtime->id . '.csv"',
        ];

        $csv = "Foglalás ID,Név,Telefon,Email,Sor,Szék,Ár (Ft)\n";
        
        foreach ($reservations as $res) {
            foreach ($res->items as $item) {
                $name = $res->customer_name ?? '';
                $phone = $res->customer_phone ?? '';
                $csv .= "{$res->id},{$name},{$phone},{$res->customer_email},{$item->row_label},{$item->seat_number},{$item->price_amount}\n";
            }
        }

        return response($csv, 200, $headers);
    }
}
