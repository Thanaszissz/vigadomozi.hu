<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Showtime;
use App\Services\SeatMapService;
use App\Services\PricingService;
use App\Support\ProgramSales;
use Illuminate\Http\Request;

class ShowtimeController extends Controller
{
    public function index(Request $request)
    {
        // A terem teljes elrendezése (layout_json) a listához nem kell, csak a neve
        $query = Showtime::with(['movie', 'auditorium:id,name']);

        // A zártkörű előadások csak a helyszíni jegyeladásban (include_private=1, pos.sell jogosultsággal) kérhetők le
        $withPrivate = $request->boolean('include_private') && ProgramSales::canSeePrivate($request);
        if (!$withPrivate) {
            $query->publiclyVisible();
        }

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
            ->header('Cache-Control', $withPrivate ? 'private, no-store' : 'public, max-age=30');
    }

    public function show(Showtime $showtime, Request $request)
    {
        abort_if($showtime->isPrivate() && !ProgramSales::canSeePrivate($request), 404);

        // Külső jegyértékesítőnél nincs nézőtér: az oldal a megadott címre irányít
        if ($showtime->is_external) {
            return response()->json([
                'showtime' => $showtime->load(['movie', 'auditorium:id,name']),
                'seatMap' => null,
            ], 200);
        }

        $seatMapService = app(SeatMapService::class);
        $pricingService = app(PricingService::class);
        
        $fullSeatMap = $seatMapService->getFullSeatMap($showtime, $pricingService);

        return response()->json([
            'showtime' => $showtime->load(['movie', 'auditorium']),
            'seatMap' => $fullSeatMap,
        ], 200);
    }
}
