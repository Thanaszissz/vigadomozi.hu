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
        // A terem teljes elrendezése (layout_json) a listához nem kell, csak a neve
        $query = Showtime::with(['movie', 'auditorium:id,name']);

        // A műsorlista az aktuális naptól kezdve mutatja az előadásokat, a korábbi napok előadásai nem kellenek
        // (konkrét nap kérhető a date paraméterrel)
        if ($request->has('date')) {
            $query->whereDate('starts_at', $request->date);
        } else {
            $query->where('starts_at', '>=', now('Europe/Budapest')->startOfDay()->utc());
        }

        if ($request->has('movie_id')) {
            $query->where('movie_id', $request->movie_id);
        }

        if ($request->has('theater_show_id')) {
            $query->where('theater_show_id', $request->theater_show_id);
        }

        return response()->json($query->orderBy('starts_at')->get(), 200)
            ->header('Cache-Control', 'public, max-age=30');
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
