@extends('layouts.app')

@section('title', 'Registro')

@section('content')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <line x1="19" y1="8" x2="19" y2="14"></line>
                    <line x1="22" y1="11" x2="16" y2="11"></line>
                </svg>
            </div>
            <span class="auth-badge">Crear cuenta</span>
            <h1>Únete ahora</h1>
            <p>Completa tus datos para comenzar.</p>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="auth-form">
            @csrf

            <label for="name">Nombre</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                <input id="name" type="text" name="name" value="{{ old('name') }}"
                       placeholder="Tu nombre completo" required maxlength="100" autofocus>
            </div>
            @error('name') <p class="auth-error">{{ $message }}</p> @enderror

            <label for="email">Correo electrónico</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <path d="M22 6l-10 7L2 6"></path>
                    <path d="M2 6h20v12H2z"></path>
                </svg>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
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
                Registrarme
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </button>
        </form>

        <div class="auth-footer">
            ¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión</a>
        </div>
    </div>
</div>

<script>
    const passwordInput = document.querySelector('#password');
    const toggleBtn = document.querySelector('#togglePassword');

    if (passwordInput && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
        });
    }
</script>
@endsection