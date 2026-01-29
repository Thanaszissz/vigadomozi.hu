<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use Illuminate\Http\Request;

class MovieAdminController extends Controller
{
    public function index()
    {
        return response()->json(Movie::all());
    }

    public function show(Movie $movie)
    {
        return response()->json($movie);
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
        ]);

        if ($request->hasFile('poster_file')) {
            $path = $request->file('poster_file')->store('posters', 'public');
            $data['poster_path'] = 'http://127.0.0.1:8000/storage/' . str_replace('posters/', 'posters/', $path);
            unset($data['poster_file']);
        }

        $movie = Movie::create($data);

        return response()->json($movie, 201);
    }

    public function update(Movie $movie, Request $request)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'poster_path' => ['sometimes', 'string'],
            'poster_file' => ['nullable', 'image', 'max:5120'],
            'trailer_youtube_url' => ['sometimes', 'url'],
            'duration_min' => ['sometimes', 'integer'],
            'rating' => ['sometimes', 'in:general,6,12,16,18'],
        ]);

        if ($request->hasFile('poster_file')) {
            // Delete old poster if exists
            if ($movie->poster_path && strpos($movie->poster_path, '/storage/posters/') !== false) {
                $filename = basename($movie->poster_path);
                \Storage::disk('public')->delete('posters/' . $filename);
            }
            
            $path = $request->file('poster_file')->store('posters', 'public');
            $data['poster_path'] = 'http://127.0.0.1:8000/storage/' . str_replace('posters/', 'posters/', $path);
            unset($data['poster_file']);
        }

        $movie->update($data);

        return response()->json($movie);
    }

    public function destroy(Movie $movie)
    {
        $movie->delete();

        return response()->json(null, 204);
    }

    public function uploadPoster(Movie $movie, Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'poster_path' => ['nullable', 'string'],
            'poster_file' => ['nullable', 'image', 'max:5120'],
            'trailer_youtube_url' => ['nullable', 'url'],
            'duration_min' => ['nullable', 'integer'],
            'rating' => ['nullable', 'in:general,6,12,16,18'],
        ]);

        if ($request->hasFile('poster_file')) {
            // Delete old poster if exists
            if ($movie->poster_path && strpos($movie->poster_path, '/storage/posters/') !== false) {
                $filename = basename($movie->poster_path);
                \Storage::disk('public')->delete('posters/' . $filename);
            }
            
            $path = $request->file('poster_file')->store('posters', 'public');
            $data['poster_path'] = 'http://127.0.0.1:8000/storage/' . str_replace('posters/', 'posters/', $path);
            unset($data['poster_file']);
        }

        $movie->update($data);

        return response()->json($movie);
    }
}
