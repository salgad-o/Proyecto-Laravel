{{-- TEXTO DE PRUEBA (NP-43). Reemplazar por el texto oficial antes de desplegar. --}}
@php
    // La duración real depende de la configuración de la sesión (config/session.php).
    $duracionSesion = config('session.expire_on_close')
        ? 'Hasta que cierres el navegador'
        : config('session.lifetime').' minutos de inactividad';
@endphp
<h2>1. Qué es una cookie</h2>
<p>Una cookie es un archivo pequeño que el sitio guarda en tu navegador para conservar información entre una página y otra. Este texto es de prueba.</p>

<h2>2. Cookies y almacenamiento que usamos</h2>
<p>Nexus Plays usa siempre unas pocas cookies propias y necesarias para que el sitio funcione. Además hay una preferencia opcional, que solo se guarda si eliges "Aceptar todas" en el aviso de cookies:</p>
<div class="legal-tabla">
    <table>
        <thead>
            <tr>
                <th scope="col">Nombre</th>
                <th scope="col">Tipo</th>
                <th scope="col">Para qué sirve</th>
                <th scope="col">Duración</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{ config('session.cookie') }}</code></td>
                <td>Cookie necesaria</td>
                <td>Cookie de sesión de Laravel: mantiene tu sesión abierta mientras navegas.</td>
                <td>{{ $duracionSesion }}</td>
            </tr>
            <tr>
                <td><code>XSRF-TOKEN</code></td>
                <td>Cookie necesaria</td>
                <td>Protege los formularios contra ataques CSRF. No es HttpOnly a propósito: está pensada para que el JavaScript del sitio pueda leerla y enviar el token CSRF en sus peticiones. No sirve para entrar a tu cuenta.</td>
                <td>{{ $duracionSesion }}</td>
            </tr>
            <tr>
                <td><code>{{ config('legal.consentimiento.cookie') }}</code></td>
                <td>Cookie necesaria</td>
                <td>Guarda tu elección en el aviso de cookies durante 6 meses. Puedes borrarla desde tu navegador para volver a elegir.</td>
                <td>6 meses</td>
            </tr>
            <tr>
                <td><code>np_barra_plegada</code></td>
                <td>Almacenamiento local del navegador (preferencia)</td>
                <td>Recuerda si ocultaste la barra lateral. Solo se usa si eliges "Aceptar todas".</td>
                <td>Hasta que la borres o elijas "Solo necesarias"</td>
            </tr>
        </tbody>
    </table>
</div>

<h2>3. Lo que no usamos</h2>
<p>No usamos cookies de analítica, de publicidad ni de terceros.</p>

<h2>4. Cómo borrarlas</h2>
<p>Puedes borrar las cookies y el almacenamiento local desde la configuración de tu navegador (datos del sitio). Si borras la cookie de sesión, tendrás que iniciar sesión de nuevo. Si borras la cookie del aviso, el aviso vuelve a aparecer y puedes elegir otra vez.</p>
