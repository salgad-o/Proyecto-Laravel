<?php

namespace App\Support;

/**
 * Evita redirecciones abiertas (open redirect).
 *
 * url()->previous() se basa en la cabecera Referer, que el cliente controla.
 * Si un atacante la manipula (por ejemplo https://sitio-malo.test/), un
 * redirect()->back() mandaría al usuario a ese sitio. Aquí solo se acepta la
 * URL anterior si empieza por la raíz de esta aplicación; si no, se usa el
 * respaldo.
 */
class UrlAnterior
{
    public static function segura(string $respaldo): string
    {
        $anterior = url()->previous($respaldo);
        $raiz = url('/');

        // Se compara con "raíz/" para que https://app.test.malo.com no pase por https://app.test.
        if ($anterior === $raiz || str_starts_with($anterior, $raiz.'/')) {
            return $anterior;
        }

        return $respaldo;
    }
}
