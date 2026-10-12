<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TheaterShow;

class TheaterShowController extends Controller
{
    public function index()
    {
        return response()->json(TheaterShow::all(), 200);
    }

    public function show(TheaterShow $theater_show)
    {
        return response()->json($theater_show, 200);
    }
}
