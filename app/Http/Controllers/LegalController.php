<?php

namespace App\Http\Controllers;

/**
 * Páginas legales públicas (NP-43). Solo devuelven la vista: los textos
 * viven en resources/views/legal/textos/ y la versión en config/legal.php.
 */
class LegalController extends Controller
{
    public function cookies()
    {
        return view('legal.cookies');
    }

    public function privacidad()
    {
        return view('legal.privacidad');
    }

    public function terminos()
    {
        return view('legal.terminos');
    }
}
