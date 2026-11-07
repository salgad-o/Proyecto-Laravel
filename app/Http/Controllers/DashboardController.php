<?php

namespace App\Http\Controllers;

use App\Models\Noticia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewPanel', Noticia::class);

        return view('pages.dashboard', [
            'usuario' => $request->user(),
        ]);
    }
}
