<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dailyReport(Request $request)
    {
        $date = $request->query('date', now()->toDateString());
        
        $startDate = Carbon::parse($date)->startOfDay();
        $endDate = Carbon::parse($date)->endOfDay();

        $reservations = Reservation::query()
            ->where('status', 'PAID')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['items', 'showtime.movie', 'showtime.auditorium', 'handledByAdmin'])
            ->orderBy('created_at')
            ->get();

        $summary = [
            'date' => $date,
            'total_reservations' => $reservations->count(),
            'total_tickets' => $reservations->sum(fn($r) => $r->items->count()),
            'total_amount' => $reservations->sum('total_amount'),
            'by_admin' => [],
            'by_showtime' => [],
            'by_payment_method' => [],
            'hourly' => [],
        ];

        // Adminisztrátoronkénti összesítés
        $adminGroups = $reservations->groupBy('handled_by_admin_id');

        foreach ($adminGroups as $adminId => $adminReservations) {
            $admin = $adminReservations->first()->handledByAdmin;
            $summary['by_admin'][] = [
                'admin_id' => $adminId,
                'admin_name' => $admin?->name ?? 'Rendszer/Online',
                'admin_email' => $admin?->email ?? 'N/A',
                'reservations_count' => $adminReservations->count(),
                'tickets_count' => $adminReservations->sum(fn($r) => $r->items->count()),
                'total_amount' => $adminReservations->sum('total_amount'),
            ];
        }

        // Előadások szerinti bevétel lista
        $showtimeGroups = $reservations->groupBy('showtime_id');
        foreach ($showtimeGroups as $showtimeId => $showtimeReservations) {
            $showtime = $showtimeReservations->first()->showtime;
            $summary['by_showtime'][] = [
                'showtime_id' => $showtimeId,
                'movie_title' => $showtime?->movie?->title ?? 'N/A',
                'starts_at' => $showtime?->starts_at,
                'auditorium_name' => $showtime?->auditorium?->name ?? 'N/A',
                'reservations_count' => $showtimeReservations->count(),
                'tickets_count' => $showtimeReservations->sum(fn($r) => $r->items->count()),
                'total_amount' => $showtimeReservations->sum('total_amount'),
            ];
        }

        // Fizetési módok szerinti összesítés
        foreach ($reservations->groupBy('payment_provider') as $method => $methodReservations) {
            $summary['by_payment_method'][] = [
                'method' => $method ?: 'unknown',
                'count' => $methodReservations->count(),
                'amount' => $methodReservations->sum('total_amount'),
            ];
        }

        // Óránkénti bontás
        for ($hour = 0; $hour < 24; $hour++) {
            $hourStart = $startDate->copy()->addHours($hour);
            $hourEnd = $hourStart->copy()->addHour();
            
            $hourReservations = $reservations->filter(function ($r) use ($hourStart, $hourEnd) {
                return $r->created_at >= $hourStart && $r->created_at < $hourEnd;
            });

            if ($hourReservations->count() > 0) {
                $summary['hourly'][] = [
                    'hour' => $hour,
                    'count' => $hourReservations->count(),
                    'amount' => $hourReservations->sum('total_amount'),
                ];
            }
        }

        return response()->json([
            'summary' => $summary,
            'reservations' => $reservations,
        ]);
    }
}
