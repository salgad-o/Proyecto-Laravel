@extends('layouts.app')

@section('title', 'Registro')

@section('content')
    <h1>Crear cuenta</h1>

    <form method="POST" action="{{ route('register.store') }}">
        @csrf

        <label>Nombre</label><br>
        <input type="text" name="name" value="{{ old('name') }}" required maxlength="100">
        @error('name') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Correo</label><br>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contraseña</label><br>
        <input type="password" name="password" required>
        @error('password') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Confirmar contraseña</label><br>
        <input type="password" name="password_confirmation" required>
        <br><br>

        <button type="submit">Registrarme</button>
    </form>

    <p>¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a></p>
@endsection