@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Bienvenido, {{ Auth::user()->name }}</h1>
    <p>Este es tu panel privado. Solo lo puedes ver si iniciaste sesión.</p>

    @can('viewAny', \App\Models\AuditLog::class)
        <p><a href="{{ route('auditoria.index') }}">Ver auditoría</a></p>
    @endcan

    @can('viewAny', \App\Models\Comentario::class)
        <p><a href="{{ route('comentarios.index') }}">Moderar comentarios</a></p>
    @endcan

    @can('viewAny', \App\Models\User::class)
        <p><a href="{{ route('usuarios.index') }}">Administrar usuarios</a></p>
    @endcan

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Cerrar sesión</button>
    </form>
@endsection
