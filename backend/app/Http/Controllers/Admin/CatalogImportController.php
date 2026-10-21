<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditorium;
use App\Services\CatalogImportService;
use App\Support\MovieBadges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CatalogImportController extends Controller
{
    /** Filmek és a hozzájuk tartozó előadások feltöltése JSON-ból (plakátok WebP-re alakítva). */
    public function import(Request $request, CatalogImportService $import): JsonResponse
    {
        $request->validate([
            'data' => ['required'],
            'dry_run' => ['sometimes', 'boolean'],
            'update_existing' => ['sometimes', 'boolean'],
        ]);

        set_time_limit(300);

        try {
            $result = $import->run(
                $request->input('data'),
                $request->boolean('dry_run'),
                $request->boolean('update_existing', true),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** Minta JSON: a valós termek nevével és a mai naphoz igazított időpontokkal. */
    public function sample(): JsonResponse
    {
        $auditorium = Auditorium::query()->orderBy('id')->value('name') ?? 'Nagyterem';
        $day = fn (int $plus, string $time) => now()->addDays($plus)->format('Y-m-d') . ' ' . $time;

        return response()->json([
            'movies' => [
                [
                    'title' => 'Minta film 1',
                    'description' => 'A film rövid leírása.',
                    'duration_min' => 112,
                    'rating' => '12',
                    'film_info' => 'amerikai kalandfilm, 2026',
                    'trailer_youtube_url' => 'https://www.youtube.com/watch?v=XXXXXXXXXXX',
                    'badges' => ['premier', 'feliratos'],
                    'poster' => 'https://pelda.hu/plakatok/minta-film-1.jpg',
                    'showtimes' => [
                        ['auditorium' => $auditorium, 'starts_at' => [$day(1, '17:00'), $day(2, '17:00'), $day(3, '19:30')]],
                        ['auditorium' => $auditorium, 'starts_at' => $day(4, '20:00'), 'sales_open_at' => $day(0, '08:00'), 'status' => 'active'],
                    ],
                ],
                [
                    'title' => 'Minta film 2',
                    'description' => 'Plakát megadható base64 data URI-ként is, vagy több képből álló tömbként (az első feldolgozható kép lesz a plakát).',
                    'duration_min' => 95,
                    'rating' => 'general',
                    'badges' => ['magyar'],
                    'poster' => ['https://pelda.hu/plakatok/minta-film-2.png', 'data:image/png;base64,iVBORw0KGgo...'],
                    'auditorium' => $auditorium,
                    'showtimes' => [
                        ['starts_at' => [$day(5, '16:00'), $day(6, '16:00')]],
                    ],
                ],
            ],
            '_leiras' => [
                'movies' => 'Film-tömb (a legfelső szint maga is lehet a film-tömb, vagy egyetlen film-objektum). A filmek kerülnek fel először, utána a hozzájuk tartozó előadások.',
                'title' => 'Kötelező. Ha már létezik ilyen című film, frissül (a "Meglévő filmek frissítése" kikapcsolásával kimarad).',
                'rating' => 'general, 6, 12, 16 vagy 18',
                'badges' => 'Jelvények: ' . implode(', ', MovieBadges::keys()),
                'poster' => 'http(s) URL vagy base64 data URI, esetleg ezek tömbje. Minden kép WebP-re alakul (max. 1600 px, 82-es minőség). Belső hálózati címek nem engedélyezettek.',
                'showtimes' => 'A filmhez tartozó előadások tömbje. Kötelező: auditorium (terem neve vagy azonosítója, a filmnél is megadható alapértelmezésként) és starts_at.',
                'starts_at' => 'Egy időpont vagy időpontok tömbje ("2026-11-05 18:00"); időpontonként egy-egy előadás jön létre. Már létező előadást nem hoz létre újra.',
                'optional_showtime_fields' => 'sales_open_at, sales_close_at, status (active, inactive, cancelled, completed), pricing_override_json',
            ],
        ]);
    }
}
