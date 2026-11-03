<?php

namespace App\Http\Controllers;

use App\Models\Noticia;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function home()
    {
        $noticias = Noticia::publicadas()
            ->with('user')
            ->withCount('comentarios')
            ->latest('published_at')
            ->latest('id')
            ->take(7)
            ->get();

        $destacada = $noticias->first();
        $ultimas = $noticias->slice(1);

        return view('pages.home', compact('destacada', 'ultimas'));
    }

    public function about()
    {
        return view('pages.about');
    }

    public function contact()
    {
        return view('pages.contact');
    }
}
