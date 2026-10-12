<?php

namespace App\Http\Controllers;

class HomeController extends Controller
{
    // Külön osztály, hogy az útvonalak gyorsítótárazhatók legyenek (closure nem szerializálható biztonságosan)
    public function __invoke()
    {
        return response('');
    }
}
