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

// Marca de JS disponible: el CSS solo la usa para mostrar controles que necesitan JS
// (por ejemplo el botón de plegar la barra en escritorio). Sin JS todo se ve igual.
document.documentElement.classList.add('js');

// ===== Preferencia "barra plegada" (NP-43 paso 2b) =====
// Solo se lee y se escribe con el consentimiento "todas". Todo acceso a
// localStorage va en try/catch: en algunos navegadores o modos privados lanza
// excepciones; si falla, la barra funciona igual, solo no se recuerda.
const CLAVE_BARRA = 'np_barra_plegada';

const permitePreferencias = () => document.documentElement.dataset.consentimiento === 'todas';

const borrarPreferenciaBarra = () => {
    try {
        localStorage.removeItem(CLAVE_BARRA);
    } catch {
        // Sin acceso al almacenamiento: no hay nada que borrar.
    }
};

// Lectura estricta: solo el valor exacto '1' cuenta; cualquier otro se borra.
const leerPreferenciaBarra = () => {
    if (!permitePreferencias()) {
        borrarPreferenciaBarra();
        return false;
    }

    try {
        const valor = localStorage.getItem(CLAVE_BARRA);

        if (valor === '1') {
            return true;
        }

        if (valor !== null) {
            localStorage.removeItem(CLAVE_BARRA);
        }
    } catch {
        // Sin acceso al almacenamiento: se usa la barra desplegada.
    }

    return false;
};

const guardarPreferenciaBarra = (plegada) => {
    if (!permitePreferencias()) {
        borrarPreferenciaBarra();
        return;
    }

    try {
        if (plegada) {
            localStorage.setItem(CLAVE_BARRA, '1');
        } else {
            localStorage.removeItem(CLAVE_BARRA);
        }
    } catch {
        // No se pudo guardar: la barra funciona igual, solo no se recuerda.
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const boton = document.querySelector('#menu-boton');
    const menu = document.querySelector('#menu-lateral');
    const fondo = document.querySelector('#menu-fondo');
    const columna = document.querySelector('.layout-columna');

    if (!boton || !menu || !fondo || !columna) {
        return;
    }

    const raiz = document.documentElement;
    const textoBoton = boton.querySelector('.sr-only');
    const escritorio = window.matchMedia('(min-width: 900px)');

    const estaAbierto = () => document.body.classList.contains('menu-abierto');
    const estaPlegada = () => raiz.classList.contains('barra-plegada');

    // El banner de cookies vive fuera de .layout-columna: también se bloquea
    // para que el teclado no llegue a él por detrás del panel.
    const fijarBanner = (bloqueado) => {
        const banner = document.querySelector('.banner-cookies');
        if (banner) {
            banner.inert = bloqueado;
        }
    };

    // Deja el botón y la barra coherentes con el modo actual (escritorio o móvil).
    const actualizarBoton = () => {
        if (escritorio.matches) {
            const plegada = estaPlegada();
            boton.setAttribute('aria-expanded', plegada ? 'false' : 'true');
            textoBoton.textContent = plegada ? 'Mostrar menú' : 'Ocultar menú';
            // La barra plegada no se ve: el Tab no debe llegar a sus enlaces.
            menu.inert = plegada;
        } else {
            const abierto = estaAbierto();
            boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
            textoBoton.textContent = abierto ? 'Cerrar menú' : 'Abrir menú';
            // En móvil el panel cerrado ya se oculta con visibility (CSS).
            menu.inert = false;
        }
    };

    // ----- Escritorio: plegar y desplegar -----
    const cambiarPlegada = (plegada) => {
        // La transición solo se activa en cambios hechos a mano.
        raiz.classList.add('barra-transicion');
        raiz.classList.toggle('barra-plegada', plegada);
        guardarPreferenciaBarra(plegada);
        actualizarBoton();
    };

    // ----- Móvil: panel deslizante (igual que antes) -----
    const abrirMenu = () => {
        document.body.classList.add('menu-abierto');
        // Mientras el menú está abierto, el teclado no puede salir al contenido de atrás.
        columna.inert = true;
        fijarBanner(true);
        actualizarBoton();
        menu.querySelector('a')?.focus();
    };

    const cerrarMenu = () => {
        if (!estaAbierto()) {
            return;
        }

        document.body.classList.remove('menu-abierto');
        columna.inert = false;
        fijarBanner(false);
        actualizarBoton();
        boton.focus();
    };

    // Estado guardado: se aplica SIN transición (la clase barra-transicion
    // todavía no está), así no se ve la animación en cada página.
    raiz.classList.toggle('barra-plegada', leerPreferenciaBarra());
    actualizarBoton();

    boton.addEventListener('click', () => {
        if (escritorio.matches) {
            cambiarPlegada(!estaPlegada());
        } else if (estaAbierto()) {
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

    // Al cruzar los 900 px: en escritorio el panel móvil deja de existir;
    // en ambos sentidos el botón y la barra quedan coherentes con el modo nuevo.
    escritorio.addEventListener('change', () => {
        raiz.classList.remove('barra-transicion');
        cerrarMenu();
        actualizarBoton();
    });

    // Elección en el banner de cookies (lanzado por el envío con fetch).
    document.addEventListener('np:consentimiento', (e) => {
        if (e.detail?.eleccion === 'todas') {
            if (estaPlegada()) {
                guardarPreferenciaBarra(true);
            }
        } else {
            borrarPreferenciaBarra();
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
