@extends('layouts.app')

@section('title', '¿Olvidaste tu contraseña?')

@section('content')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <span class="auth-badge">Recuperar acceso</span>
            <h1>¿Olvidaste tu contraseña?</h1>
            <p>Escribe tu correo y te enviaremos un enlace para restablecerla.</p>
        </div>

        @if (session('status'))
            <div class="auth-status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="auth-form">
            @csrf

            <label for="email">Correo electrónico</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <path d="M22 6l-10 7L2 6"></path>
                    <path d="M2 6h20v12H2z"></path>
                </svg>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="nombre@correo.com" required autofocus>
            </div>
            @error('email') <p class="auth-error">{{ $message }}</p> @enderror

            <button type="submit" class="auth-button">
                Enviar enlace
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </button>
        </form>

        <div class="auth-footer">
            <a href="{{ route('login') }}">Volver a iniciar sesión</a>
        </div>
    </div>
</div>
@endsection
