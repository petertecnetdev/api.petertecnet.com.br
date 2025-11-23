<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Home;

class HomeController extends Controller
{
    public function home(Request $request, $app_id)
    {
        $city = $request->query('city');
        $uf   = $request->query('uf');

        $payload = Home::build($app_id, [
            'city' => $city,
            'uf'   => $uf,
        ]);

        return response()->json($payload);
    }
}
