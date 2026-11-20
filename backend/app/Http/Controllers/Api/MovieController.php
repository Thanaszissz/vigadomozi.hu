<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Support\ProgramSales;
use Illuminate\Http\Request;

class MovieController extends Controller
{
    public function index()
    {
        return response()->json(
            Movie::listed()->where('is_private', false)->get(),
            200
        )->header('Cache-Control', 'public, max-age=30');
    }

    public function show(Movie $movie, Request $request)
    {
        abort_if($movie->is_private && !ProgramSales::canSeePrivate($request), 404);

        return response()->json($movie, 200);
    }
}
