{{--
    Banner de consentimiento de cookies (NP-43 paso 2, ajuste 2a): barra inferior.
    - Funciona sin JS: el formulario se envía normal y el servidor redirige de vuelta.
    - Con JS (resources/js/app.js) se envía con fetch y la barra se oculta sin recargar.
    - Nunca se oculta sola: solo desaparece cuando el usuario elige una opción.
    - Los dos botones tienen la misma clase y el mismo tamaño: ninguna opción se
      destaca sobre la otra (evita un patrón oscuro).
    - No atrapa el foco ni bloquea la página.
--}}
<section class="banner-cookies" role="region" aria-label="Aviso de cookies">
    <div class="banner-cookies-info">
        <svg class="banner-cookies-icono" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3a9 9 0 1 0 9 9 3 3 0 0 1-3.5-3A3 3 0 0 1 14 5.5 3 3 0 0 1 12 3z"></path>
            <path d="M8.5 9.5h.01"></path>
            <path d="M15.5 14.5h.01"></path>
            <path d="M10 15h.01"></path>
            <path d="M12 11.5h.01"></path>
        </svg>
        <p class="banner-cookies-texto">
            Solo usamos cookies necesarias: tu sesión y la seguridad de los formularios.
            <a href="{{ route('legal.cookies') }}" class="banner-cookies-enlace">Política de cookies</a>
        </p>
    </div>

    <form method="POST" action="{{ route('cookies.consentimiento') }}" class="banner-cookies-form">
        @csrf
        <button type="submit" name="eleccion" value="necesarias" class="banner-cookies-boton">Solo necesarias</button>
        <button type="submit" name="eleccion" value="todas" class="banner-cookies-boton">Aceptar todas</button>
    </form>
</section>
