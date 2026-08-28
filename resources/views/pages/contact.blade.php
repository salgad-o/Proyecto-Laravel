<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacto</title>
</head>
<body>
    <h1>Contacto</h1>
    <p>Formulario protegido y validado en el servidor.</p>
@extends('layouts.app')

@section('title', 'Contacto')

@section('content')
    <h1>Contacto</h1>
    <p>Formulario protegido y validado en el servidor.</p>

    @if (session('status'))
        <p style="color: green;">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('contact.send') }}">
        @csrf

        <label>Nombre</label><br>
        <input type="text" name="name" value="{{ old('name') }}" required maxlength="100">
        @error('name') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Correo</label><br>
        <input type="email" name="email" value="{{ old('email') }}" required>
        @error('email') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Mensaje</label><br>
        <textarea name="message" required maxlength="2000">{{ old('message') }}</textarea>
        @error('message') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Enviar</button>
    </form>
@endsection