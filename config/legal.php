<?php

/*
|--------------------------------------------------------------------------
| Documentos legales (NP-43)
|--------------------------------------------------------------------------
|
| Versión de la política de cookies, la política de privacidad y los términos de uso.
| Esta versión se guardará en users.legal_version cuando el usuario acepte
| los documentos al registrarse (paso 3 de NP-43).
|
| Mientras 'es_prueba' sea true, las páginas muestran un aviso de que el texto
| no es oficial. Antes de desplegar se cambian los textos de
| resources/views/legal/textos/ por los oficiales, la versión pasa a '1.0'
| y 'es_prueba' a false.
|
*/

return [
    'version' => '0-prueba',

    // Fecha ISO (AAAA-MM-DD). La vista la muestra en formato legible.
    'vigente_desde' => '2026-11-03',

    'es_prueba' => true,
];
