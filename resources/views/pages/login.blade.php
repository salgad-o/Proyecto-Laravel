@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <h1>Iniciar sesión</h1>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label>Correo</label><br>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contraseña</label><br>
        <input type="password" name="password" required>
        @error('password') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Entrar</button>
    </form>

    <p>¿No tienes cuenta? <a href="{{ route('register') }}">Regístrate</a></p>
@endsection