<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use Illuminate\Http\Request;

class MovieController extends Controller
{
    public function index()
    {
        return response()->json(
            Movie::all(),
            200
        )->header('Cache-Control', 'public, max-age=30');
    }

    public function show(Movie $movie)
    {
        return response()->json($movie, 200);
    }
}
