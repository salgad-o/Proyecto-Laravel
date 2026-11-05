{{-- Cabecera común de "Mi perfil" (NP-36): etiqueta, título, pestañas y aviso de estado.
     La lista de pestañas vive aquí, en un solo lugar: el parcial _pestanas solo
     muestra las que ya tienen ruta. --}}
<div class="pagina-encabezado">
    <div class="pagina-encabezado-texto">
        <p class="pagina-etiqueta">MI CUENTA</p>
        <h1 class="pagina-titulo">Mi perfil</h1>
        <p class="pagina-subtitulo">{{ $subtitulo }}</p>
    </div>
</div>

@include('perfil._pestanas', ['pestanas' => [
    ['perfil.edit', 'Perfil'],
    ['perfil.seguridad', 'Seguridad'],
    ['perfil.actividad', 'Actividad'],
    ['perfil.cuenta', 'Cuenta'],
]])

@if (session('status'))
    <p class="aviso-exito" role="status">{{ session('status') }}</p>
@endif
