{{-- NP-39: foto de perfil o, si no hay, la inicial del nombre.
     - usuario: el User.
     - tamano: lado en px (width/height del <img>, para que no "salte" el layout al cargar).
     - clase: la clase CSS del círculo en cada lugar (perfil-avatar, autor-avatar, sesion-avatar).
     - lazy: carga diferida; solo para fotos que suelen quedar fuera de la pantalla
       (listas, comentarios). Nunca en la tarjeta del perfil, que está arriba.
     alt="" porque es decorativa: el nombre del usuario siempre se muestra al lado. --}}
@props(['usuario', 'tamano' => 40, 'clase' => '', 'lazy' => false])

@php($url = $usuario->avatarUrl())

@if ($url)
    <img class="{{ trim($clase.' avatar-foto') }}" src="{{ $url }}" alt="" width="{{ $tamano }}" height="{{ $tamano }}" decoding="async" @if ($lazy) loading="lazy" @endif>
@else
    <span class="{{ $clase }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($usuario->name, 0, 1)) }}</span>
@endif
