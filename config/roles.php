<?php

/*
|--------------------------------------------------------------------------
| Permisos por rol
|--------------------------------------------------------------------------
|
| Esta lista es de solo lectura: los permisos reales viven en las Policies y
| en el middleware de rol. Aquí solo se describen para mostrarlos en la
| sección de gestión de roles.
|
*/

return [
    'admin' => [
        'Administrar usuarios: cambiar su rol y activarlos o desactivarlos.',
        'Editar el nombre visible y la descripción de los roles.',
        'Crear, editar, publicar y eliminar cualquier noticia.',
        'Ver borradores de cualquier autor.',
        'Moderar y eliminar cualquier comentario.',
        'Consultar la auditoría.',
    ],
    'editor' => [
        'Crear noticias.',
        'Editar, publicar y eliminar sus propias noticias.',
        'Ver sus propios borradores.',
        'Comentar noticias publicadas y gestionar sus propios comentarios.',
    ],
    'lector' => [
        'Leer noticias publicadas.',
        'Comentar noticias publicadas.',
        'Editar y eliminar sus propios comentarios.',
    ],
];
