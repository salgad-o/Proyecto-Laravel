@extends('layouts.app')

@section('title', 'Nueva contraseña')

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
            <span class="auth-badge">Seguridad</span>
            <h1>Nueva contraseña</h1>
            <p>Elige una contraseña segura de al menos 12 caracteres.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="auth-form">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Correo electrónico</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <path d="M22 6l-10 7L2 6"></path>
                    <path d="M2 6h20v12H2z"></path>
                </svg>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}"
                       placeholder="nombre@correo.com" required>
            </div>
            @error('email') <p class="auth-error">{{ $message }}</p> @enderror

            <label for="password">Contraseña</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <input id="password" type="password" name="password"
                       placeholder="Mínimo 12 caracteres" required>
                <button type="button" class="auth-toggle-password" id="togglePassword">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#908fa0" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
            @error('password') <p class="auth-error">{{ $message }}</p> @enderror

            <label for="password_confirmation">Confirmar contraseña</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       placeholder="Repite tu contraseña" required>
            </div>

            <button type="submit" class="auth-button">
                Guardar contraseña
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
