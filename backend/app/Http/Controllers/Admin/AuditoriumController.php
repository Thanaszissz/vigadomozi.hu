<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditorium;
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
            'rows' => ['required', 'integer', 'min:1'],
            'columns' => ['required', 'integer', 'min:1'],
            'total_seats' => ['required', 'integer', 'min:1'],
        ]);

        $auditorium = Auditorium::create($data);

        return response()->json($auditorium, 201);
    }

    public function update(Auditorium $auditorium, Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'rows' => ['sometimes', 'integer', 'min:1'],
            'columns' => ['sometimes', 'integer', 'min:1'],
            'total_seats' => ['sometimes', 'integer', 'min:1'],
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

    public function updateSeats(Auditorium $auditorium, Request $request)
    {
        // This could be expanded to store seat layout in database if needed
        $data = $request->validate([
            'seats' => ['required', 'array'],
            'sectors' => ['nullable', 'array'],
            'sections' => ['nullable', 'array'],
            'rows' => ['nullable', 'array'],
        ]);

        $auditorium->layout_json = [
            'seats' => $data['seats'],
            'sectors' => $data['sectors'] ?? [],
            'sections' => $data['sections'] ?? [],
            'rows' => $data['rows'] ?? [],
        ];
        $auditorium->save();

        return response()->json([
            'message' => 'Nézőtér elrendezés sikeresen mentve!',
            'seats' => $auditorium->layout_json['seats'],
            'sectors' => $auditorium->layout_json['sectors'] ?? [],
            'sections' => $auditorium->layout_json['sections'] ?? [],
            'rows' => $auditorium->layout_json['rows'] ?? [],
        ]);
    }
}
