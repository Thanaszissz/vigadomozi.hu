<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Showtime;
use App\Services\SeatMapService;
use App\Services\PricingService;
use Illuminate\Http\Request;

class ShowtimeController extends Controller
{
    public function index(Request $request)
    {
        $query = Showtime::with(['movie', 'auditorium']);

        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('starts_at', $date);
        }

        if ($request->has('movie_id')) {
            $query->where('movie_id', $request->movie_id);
        }

        return response()->json(
            $query->get(),
            200
        );
    }

    public function show(Showtime $showtime)
    {
        $seatMapService = app(SeatMapService::class);
        $pricingService = app(PricingService::class);
        
        $fullSeatMap = $seatMapService->getFullSeatMap($showtime, $pricingService);

        return response()->json([
            'showtime' => $showtime->load(['movie', 'auditorium']),
            'seatMap' => $fullSeatMap,
        ], 200);
    }
}
