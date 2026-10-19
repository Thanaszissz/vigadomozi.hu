<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TheaterShow;
use App\Support\ShowOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TheaterShowAdminController extends Controller
{
    public function index()
    {
        return response()->json(TheaterShow::listed()->get());
    }

    public function show(TheaterShow $theater_show)
    {
        return response()->json($theater_show);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'poster_path' => ['nullable', 'string'],
            'poster_file' => ['nullable', 'image', 'max:5120'],
            'trailer_youtube_url' => ['nullable', 'url'],
            'duration_min' => ['nullable', 'integer'],
            'rating' => ['nullable', 'in:general,6,12,16,18'],
            'category' => ['nullable', Rule::in(ShowOptions::categoryKeys())],
            'performer' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'show_info' => ['nullable', 'string', 'max:255'],
            'ticket_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'badges' => ['nullable', 'array'],
            'badges.*' => ['string', Rule::in(ShowOptions::badgeKeys())],
        ]);

        if ($request->hasFile('poster_file')) {
            $path = $request->file('poster_file')->store('posters', 'public');
            $data['poster_path'] = url('/storage') . '/' . $path;
            unset($data['poster_file']);
        }

        $show = TheaterShow::create($data);

        return response()->json($show, 201);
    }

    public function update(TheaterShow $theater_show, Request $request)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'poster_path' => ['sometimes', 'string'],
            'poster_file' => ['nullable', 'image', 'max:5120'],
            'trailer_youtube_url' => ['sometimes', 'url'],
            'duration_min' => ['sometimes', 'integer'],
            'rating' => ['sometimes', 'in:general,6,12,16,18'],
            'category' => ['nullable', Rule::in(ShowOptions::categoryKeys())],
            'performer' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'show_info' => ['nullable', 'string', 'max:255'],
            'ticket_price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'badges' => ['nullable', 'array'],
            'badges.*' => ['string', Rule::in(ShowOptions::badgeKeys())],
        ]);

        if ($request->hasFile('poster_file')) {
            if ($theater_show->poster_path && strpos($theater_show->poster_path, '/storage/posters/') !== false) {
                $filename = basename($theater_show->poster_path);
                Storage::disk('public')->delete('posters/' . $filename);
            }

            $path = $request->file('poster_file')->store('posters', 'public');
            $data['poster_path'] = url('/storage') . '/' . $path;
            unset($data['poster_file']);
        }

        $theater_show->update($data);

        return response()->json($theater_show);
    }

    public function destroy(TheaterShow $theater_show)
    {
        $theater_show->delete();

        return response()->json(null, 204);
    }

    public function uploadPoster(TheaterShow $theater_show, Request $request)
    {
        return $this->update($theater_show, $request);
    }
}
