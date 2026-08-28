@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Bienvenido, {{ Auth::user()->name }}</h1>
    <p>Este es tu panel privado. Solo lo puedes ver si iniciaste sesión.</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Cerrar sesión</button>
    </form>
@endsection