<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditorium;
use App\Models\ReservationItem;
use App\Support\SeatKey;
use Illuminate\Http\Request;

class AuditoriumController extends Controller
{
    public function index()
    {
        return response()->json(Auditorium::all());
    }

    public function show(Auditorium $auditorium)
    {
        return response()->json($auditorium);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // a sorok, oszlopok és a férőhelyek száma a nézőtér elrendezéséből adódik
        $auditorium = Auditorium::create($data + ['rows' => 0, 'columns' => 0, 'total_seats' => 0]);

        return response()->json($auditorium, 201);
    }

    public function update(Auditorium $auditorium, Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $auditorium->update($data);

        return response()->json($auditorium);
    }

    public function destroy(Auditorium $auditorium)
    {
        $auditorium->delete();

        return response()->json(null, 204);
    }

    public function getSeats(Auditorium $auditorium)
    {
        if ($auditorium->layout_json && isset($auditorium->layout_json['seats'])) {
            return response()->json($auditorium->layout_json);
        }

        // Generate seats array based on rows and columns
        $seats = [];
        for ($row = 1; $row <= $auditorium->rows; $row++) {
            for ($col = 1; $col <= $auditorium->columns; $col++) {
                $seats[] = [
                    'row' => $row,
                    'column' => $col,
                    'blocked' => false,
                    'sector' => null,
                    'price' => null,
                ];
            }
        }

        return response()->json([
            'seats' => $seats,
            'sectors' => [],
            'sections' => [],
            'rows' => [],
        ]);
    }

    /** A szék kulcsa a mentett elrendezésben (régi, kulcs nélküli székeknél sor/oszlop alapján). */
    private function layoutSeatKey(array $seat): ?string
    {
        if (!empty($seat['key'])) {
            return (string) $seat['key'];
        }
        if (isset($seat['row'], $seat['column'])) {
            return ($seat['rowLabel'] ?? chr(64 + (int) $seat['row'])) . '-' . str_pad((string) $seat['column'], 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Az újonnan kizárt székek közül azok, amelyekre közelgő előadáson már van érvényes foglalás vagy eladott jegy.
     *
     * @return array<int,string>
     */
    private function soldSeatsAmongNewlyBlocked(Auditorium $auditorium, array $newSeats): array
    {
        $blockedBefore = collect($auditorium->layout_json['seats'] ?? [])
            ->filter(fn ($s) => !empty($s['blocked']))
            ->map(fn ($s) => $this->layoutSeatKey($s))
            ->filter()
            ->all();

        $newlyBlocked = collect($newSeats)
            ->filter(fn ($s) => !empty($s['blocked']))
            ->map(fn ($s) => $this->layoutSeatKey($s))
            ->filter()
            ->diff($blockedBefore)
            ->values()
            ->all();

        if (!$newlyBlocked) {
            return [];
        }

        return ReservationItem::whereIn('seat_key', $newlyBlocked)
            ->whereHas('reservation', fn ($q) => $q
                ->whereIn('status', ['PENDING', 'PAID'])
                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('showtime', fn ($s) => $s->where('auditorium_id', $auditorium->id)->where('starts_at', '>=', now())))
            ->pluck('seat_key')
            ->unique()
            ->map(fn ($key) => SeatKey::label($key))
            ->values()
            ->all();
    }

    public function updateSeats(Auditorium $auditorium, Request $request)
    {
        // This could be expanded to store seat layout in database if needed
        $data = $request->validate([
            'seats' => ['present', 'array'],
            'sectors' => ['nullable', 'array'],
            'sections' => ['nullable', 'array'],
            'rows' => ['nullable', 'array'],
        ]);

        $seats = $data['seats'];
        $rowConfigs = $data['rows'] ?? [];

        if ($conflicts = $this->soldSeatsAmongNewlyBlocked($auditorium, $seats)) {
            return response()->json([
                'message' => 'Ezek a székek nem zárhatók ki, mert már van rájuk eladott vagy foglalt jegy egy közelgő előadásra: '
                    . implode('; ', $conflicts) . '. Előbb sztornózd a jegyeket vagy töröld a foglalásokat.',
            ], 422);
        }

        $auditorium->layout_json = [
            'seats' => $seats,
            'sectors' => $data['sectors'] ?? [],
            'sections' => $data['sections'] ?? [],
            'rows' => $rowConfigs,
        ];

        // sorok, oszlopok és férőhelyek száma a felvett székekből
        $auditorium->rows = $rowConfigs ? count($rowConfigs) : (int) collect($seats)->max('row');
        $auditorium->columns = $rowConfigs
            ? (int) collect($rowConfigs)->max('columns')
            : (int) collect($seats)->max('column');
        $auditorium->total_seats = count(array_filter($seats, fn ($seat) => empty($seat['blocked'])));
        $auditorium->save();

        return response()->json([
            'message' => 'Nézőtér elrendezés sikeresen mentve!',
            'seats' => $auditorium->layout_json['seats'],
            'sectors' => $auditorium->layout_json['sectors'] ?? [],
            'sections' => $auditorium->layout_json['sections'] ?? [],
            'rows' => $auditorium->layout_json['rows'] ?? [],
            'auditorium' => $auditorium->fresh(),
        ]);
    }
}
