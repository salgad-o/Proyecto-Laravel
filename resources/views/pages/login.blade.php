@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <span class="auth-badge">Acceso Seguro</span>
            <h1>Bienvenido</h1>
            <p>Ingresa a tu cuenta para continuar.</p>
        </div>

        @if (session('status'))
            <div class="auth-status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="auth-form">
            @csrf

            <label for="email">Correo electrónico</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <path d="M4 4h16v16H4z" stroke="none"></path>
                    <path d="M22 6l-10 7L2 6"></path>
                    <path d="M2 6h20v12H2z"></path>
                </svg>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="nombre@correo.com" required autofocus>
            </div>
            @error('email') <p class="auth-error">{{ $message }}</p> @enderror

            <label for="password">Contraseña</label>
            <div class="auth-field">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                <input id="password" type="password" name="password"
                       placeholder="••••••••••••" required>
                <button type="button" class="auth-toggle-password" id="togglePassword">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#908fa0" stroke-width="2" id="eyeIcon">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
            @error('password') <p class="auth-error">{{ $message }}</p> @enderror

            <label class="auth-remember">
                <input type="checkbox" name="remember">
                Recordar sesión
            </label>

            <button type="submit" class="auth-button">
                Iniciar Sesión
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </button>
        </form>

        <div class="auth-footer">
            ¿No tienes cuenta? <a href="{{ route('register') }}">Regístrate</a>
        </div>
    </div>
</div>

<script>
    const passwordInput = document.querySelector('#password');
    const toggleBtn = document.querySelector('#togglePassword');
    const eyeIcon = document.querySelector('#eyeIcon');

    if (passwordInput && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
        });
    }
</script>
@endsection