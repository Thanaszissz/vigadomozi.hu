<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Showtime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShowtimeAdminController extends Controller
{
    public function index()
    {
        return response()->json(
            Showtime::listed()->with(['movie', 'auditorium'])->get()
        );
    }

    public function show(Showtime $showtime)
    {
        return response()->json($showtime->load(['movie', 'auditorium']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'movie_id' => ['nullable', 'required_without:theater_show_id', 'exists:movies,id'],
            'theater_show_id' => ['nullable', 'required_without:movie_id', 'exists:theater_shows,id'],
            'auditorium_id' => ['required', 'exists:auditoria,id'],
            'starts_at' => ['required', 'date'],
            'sales_open_at' => ['nullable', 'date'],
            'sales_close_at' => ['nullable', 'date'],
            'pricing_override_json' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive,cancelled,completed'],
            'is_external' => ['sometimes', 'boolean'],
            'external_url' => [Rule::requiredIf(fn() => $request->boolean('is_external')), 'nullable', 'url:http,https', 'max:2048'],
        ]);

        // egy időpont vagy filmhez, vagy előadáshoz tartozik
        if (!empty($data['theater_show_id'])) {
            $data['movie_id'] = null;
        }

        $data = $this->normalizeExternal($data);

        $showtime = Showtime::create($data);

        return response()->json($showtime->load(['movie', 'auditorium']), 201);
    }

    public function update(Showtime $showtime, Request $request)
    {
        $data = $request->validate([
            'movie_id' => ['sometimes', 'nullable', 'exists:movies,id'],
            'theater_show_id' => ['sometimes', 'nullable', 'exists:theater_shows,id'],
            'auditorium_id' => ['sometimes', 'exists:auditoria,id'],
            'starts_at' => ['sometimes', 'date'],
            'sales_open_at' => ['sometimes', 'date'],
            'sales_close_at' => ['sometimes', 'date'],
            'pricing_override_json' => ['sometimes', 'array'],
            'status' => ['sometimes', 'in:active,inactive,cancelled,completed'],
            'is_external' => ['sometimes', 'boolean'],
            'external_url' => [Rule::requiredIf(fn() => $request->boolean('is_external')), 'nullable', 'url:http,https', 'max:2048'],
        ]);

        if (!empty($data['theater_show_id'])) {
            $data['movie_id'] = null;
        } elseif (!empty($data['movie_id'])) {
            $data['theater_show_id'] = null;
        }

        $data = $this->normalizeExternal($data);

        $showtime->update($data);

        return response()->json($showtime->load(['movie', 'auditorium']));
    }

    public function destroy(Showtime $showtime)
    {
        $showtime->delete();

        return response()->json(null, 204);
    }

    /** Külső értékesítőnél a hivatkozás kötelező; ha az előadás nem külső, a korábbi hivatkozás törlődik. */
    private function normalizeExternal(array $data): array
    {
        if (array_key_exists('is_external', $data) && !$data['is_external']) {
            $data['external_url'] = null;
        } elseif (isset($data['external_url'])) {
            $data['external_url'] = trim($data['external_url']);
        }

        return $data;
    }
}
