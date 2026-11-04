import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#togglePassword').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.querySelector('#password');
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const boton = document.querySelector('#menu-boton');
    const menu = document.querySelector('#menu-lateral');
    const fondo = document.querySelector('#menu-fondo');
    const columna = document.querySelector('.layout-columna');

    if (!boton || !menu || !fondo || !columna) {
        return;
    }

    const textoBoton = boton.querySelector('.sr-only');
    const estaAbierto = () => document.body.classList.contains('menu-abierto');

    // El banner de cookies vive fuera de .layout-columna: también se bloquea
    // para que el teclado no llegue a él por detrás del panel.
    const fijarBanner = (bloqueado) => {
        const banner = document.querySelector('.banner-cookies');
        if (banner) {
            banner.inert = bloqueado;
        }
    };

    const abrirMenu = () => {
        document.body.classList.add('menu-abierto');
        boton.setAttribute('aria-expanded', 'true');
        textoBoton.textContent = 'Cerrar menú';
        // Mientras el menú está abierto, el teclado no puede salir al contenido de atrás.
        columna.inert = true;
        fijarBanner(true);
        menu.querySelector('a')?.focus();
    };

    const cerrarMenu = () => {
        if (!estaAbierto()) {
            return;
        }

        document.body.classList.remove('menu-abierto');
        boton.setAttribute('aria-expanded', 'false');
        textoBoton.textContent = 'Abrir menú';
        columna.inert = false;
        fijarBanner(false);
        boton.focus();
    };

    boton.addEventListener('click', () => {
        if (estaAbierto()) {
            cerrarMenu();
        } else {
            abrirMenu();
        }
    });

    fondo.addEventListener('click', cerrarMenu);

    menu.querySelectorAll('a').forEach(enlace => {
        enlace.addEventListener('click', cerrarMenu);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            cerrarMenu();
        }
    });

    // Si la ventana crece hasta el tamaño de escritorio, el panel deja de existir.
    window.matchMedia('(min-width: 900px)').addEventListener('change', (e) => {
        if (e.matches) {
            cerrarMenu();
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-contador]').forEach(campo => {
        const contador = document.getElementById(campo.dataset.contador);

        if (!contador) {
            return;
        }

        const aviso = document.getElementById(campo.dataset.contadorAviso);

        const actualizar = () => {
            contador.textContent = `${campo.value.length}/${campo.maxLength}`;
        };

        // Solo se anuncia al escribir y cerca del límite, para no interrumpir en cada tecla.
        const anunciar = () => {
            if (!aviso) {
                return;
            }

            const quedan = campo.maxLength - campo.value.length;

            if (quedan > 100) {
                aviso.textContent = '';
            } else {
                aviso.textContent = quedan === 1 ? 'Queda 1 carácter' : `Quedan ${quedan} caracteres`;
            }
        };

        campo.addEventListener('input', () => {
            actualizar();
            anunciar();
        });
        actualizar();
        contador.hidden = false;
    });
});

// Banner de cookies (NP-43 paso 2): mejora progresiva.
// Sin JS el formulario se envía normal. Con JS se envía con fetch y el banner
// se oculta sin recargar; si algo falla, se vuelve al envío normal.
document.addEventListener('submit', async (e) => {
    const form = e.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('.banner-cookies-form')) {
        return;
    }

    // Segundo intento tras un fallo: dejar el envío normal.
    if (form.dataset.sinFetch) {
        return;
    }

    const boton = e.submitter;
    const eleccion = boton?.value;

    if (eleccion !== 'todas' && eleccion !== 'necesarias') {
        return;
    }

    e.preventDefault();

    const banner = form.closest('.banner-cookies');
    const botones = form.querySelectorAll('button');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const datos = new FormData(form);
    datos.set('eleccion', eleccion);

    // Evita el doble envío mientras dura la petición.
    botones.forEach(b => { b.disabled = true; });

    let ok = false;

    try {
        const respuesta = await fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            },
            body: datos,
        });
        ok = respuesta.ok;
    } catch {
        ok = false;
    }

    if (!ok) {
        // 419, 429, 422 o error de red: envío normal con el botón pulsado.
        // Se reactivan los botones porque uno deshabilitado no viaja en el envío.
        botones.forEach(b => { b.disabled = false; });
        form.dataset.sinFetch = '1';
        form.requestSubmit(boton);
        return;
    }

    document.documentElement.dataset.consentimiento = eleccion;

    if (banner) {
        const teniaFoco = banner.contains(document.activeElement);
        let quitado = false;

        const quitar = () => {
            if (quitado) {
                return;
            }
            quitado = true;
            banner.remove();
        };

        banner.addEventListener('animationend', quitar, { once: true });
        // Respaldo por si no hay animación (prefers-reduced-motion).
        setTimeout(quitar, 400);
        banner.classList.add('banner-cookies-saliendo');

        if (teniaFoco) {
            document.getElementById('contenido')?.focus();
        }
    }

    document.dispatchEvent(new CustomEvent('np:consentimiento', { detail: { eleccion } }));
});

document.addEventListener('submit', (e) => {
    const msg = e.submitter?.dataset?.confirm ?? e.target.dataset?.confirm;
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});
