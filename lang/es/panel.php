<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Textos del panel con conteo (NP-21, ajuste 2c)
    |--------------------------------------------------------------------------
    |
    | Para trans_choice(): singular|plural. Las claves de "roles" son los nombres
    | internos de config('roles') y de roles.name (admin, editor, lector).
    |
    */

    'roles' => [
        'admin' => ':count administrador|:count administradores',
        'editor' => ':count editor|:count editores',
        'lector' => ':count lector|:count lectores',
    ],

];
