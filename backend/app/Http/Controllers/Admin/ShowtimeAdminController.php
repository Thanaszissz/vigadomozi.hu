<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Showtime;
use Illuminate\Http\Request;

class ShowtimeAdminController extends Controller
{
    public function index()
    {
        return response()->json(
            Showtime::with(['movie', 'auditorium'])->get()
        );
    }

    public function show(Showtime $showtime)
    {
        return response()->json($showtime->load(['movie', 'auditorium']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'movie_id' => ['required', 'exists:movies,id'],
            'auditorium_id' => ['required', 'exists:auditoria,id'],
            'starts_at' => ['required', 'date'],
            'sales_open_at' => ['nullable', 'date'],
            'sales_close_at' => ['nullable', 'date'],
            'pricing_override_json' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive,cancelled,completed'],
        ]);

        $showtime = Showtime::create($data);

        return response()->json($showtime->load(['movie', 'auditorium']), 201);
    }

    public function update(Showtime $showtime, Request $request)
    {
        $data = $request->validate([
            'movie_id' => ['sometimes', 'exists:movies,id'],
            'auditorium_id' => ['sometimes', 'exists:auditoria,id'],
            'starts_at' => ['sometimes', 'date'],
            'sales_open_at' => ['sometimes', 'date'],
            'sales_close_at' => ['sometimes', 'date'],
            'pricing_override_json' => ['sometimes', 'array'],
            'status' => ['sometimes', 'in:active,inactive,cancelled,completed'],
        ]);

        $showtime->update($data);

        return response()->json($showtime->load(['movie', 'auditorium']));
    }

    public function destroy(Showtime $showtime)
    {
        $showtime->delete();

        return response()->json(null, 204);
    }
}
