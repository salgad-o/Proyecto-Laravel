{{-- TEXTO DE PRUEBA (NP-43). Reemplazar por el texto oficial antes de desplegar. --}}
<h2>1. Qué es una cookie</h2>
<p>Una cookie es un archivo pequeño que el sitio guarda en tu navegador para conservar información entre una página y otra. Este texto es de prueba.</p>

<h2>2. Cookies que usamos</h2>
<p>Nexus Plays solo usa cookies propias y necesarias para que el sitio funcione:</p>
<div class="legal-tabla">
    <table>
        <thead>
            <tr>
                <th scope="col">Nombre</th>
                <th scope="col">Para qué sirve</th>
                <th scope="col">Duración</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>{{ config('session.cookie') }}</code></td>
                <td>Cookie de sesión de Laravel: mantiene tu sesión abierta mientras navegas.</td>
                <td>{{ config('session.lifetime') }} minutos de inactividad</td>
            </tr>
            <tr>
                <td><code>XSRF-TOKEN</code></td>
                <td>Protege los formularios contra ataques CSRF.</td>
                <td>{{ config('session.lifetime') }} minutos de inactividad</td>
            </tr>
            <tr>
                <td><code>np_consentimiento_cookies</code></td>
                <td>Guarda tu elección sobre las cookies. Se activará con el banner.</td>
                <td>6 meses</td>
            </tr>
        </tbody>
    </table>
</div>

<h2>3. Lo que no usamos</h2>
<p>No usamos cookies de analítica, de publicidad ni de terceros.</p>

<h2>4. Cómo borrarlas</h2>
<p>Puedes borrar las cookies desde la configuración de tu navegador. Si borras la cookie de sesión, tendrás que iniciar sesión de nuevo.</p>
