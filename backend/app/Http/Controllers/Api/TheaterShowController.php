<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TheaterShow;
use App\Support\ProgramSales;
use Illuminate\Http\Request;

class TheaterShowController extends Controller
{
    public function index()
    {
        return response()->json(TheaterShow::listed()->where('is_private', false)->get(), 200);
    }

    public function show(TheaterShow $theater_show, Request $request)
    {
        abort_if($theater_show->is_private && !ProgramSales::canSeePrivate($request), 404);

        return response()->json($theater_show, 200);
    }
}
