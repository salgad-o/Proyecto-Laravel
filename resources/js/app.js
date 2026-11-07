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
    const pestana = document.querySelector('#barra-plegar');
    const menu = document.querySelector('#menu-lateral');
    const fondo = document.querySelector('#menu-fondo');
    const columna = document.querySelector('.layout-columna');

    if (!boton || !pestana || !menu || !fondo || !columna) {
        return;
    }

    const raiz = document.documentElement;
    const textoBoton = boton.querySelector('.sr-only');
    const textoPestana = pestana.querySelector('.sr-only');
    const escritorio = window.matchMedia('(min-width: 900px)');

    const campoBuscar = menu.querySelector('#barra-buscar');
    const botonBuscar = menu.querySelector('.barra-buscar-boton');
    const mas = menu.querySelector('.menu-mas');
    const tituloMas = mas?.querySelector('.menu-mas-titulo');

    const estaAbierto = () => document.body.classList.contains('menu-abierto');
    const estaPlegada = () => raiz.classList.contains('barra-plegada');
    const esTemporal = () => raiz.classList.contains('barra-temporal');
    // "Solo iconos" de verdad en pantalla: escritorio, preferencia plegada y sin expansión temporal.
    const enModoIconos = () => escritorio.matches && estaPlegada() && !esTemporal();

    // El banner de cookies vive fuera de .layout-columna: también se bloquea
    // para que el teclado no llegue a él por detrás del panel.
    const fijarBanner = (bloqueado) => {
        const banner = document.querySelector('.banner-cookies');
        if (banner) {
            banner.inert = bloqueado;
        }
    };

    // ----- Textos y aria-expanded de los dos botones -----
    // #menu-boton (móvil): dice si el panel deslizante está abierto.
    const actualizarBotonMovil = () => {
        const abierto = estaAbierto();
        boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        textoBoton.textContent = abierto ? 'Cerrar menú' : 'Abrir menú';
    };

    // #barra-plegar (escritorio): dice si la barra se ve expandida (con textos).
    const actualizarPestana = () => {
        const expandida = !estaPlegada() || esTemporal();
        pestana.setAttribute('aria-expanded', expandida ? 'true' : 'false');
        textoPestana.textContent = expandida ? 'Contraer menú' : 'Expandir menú';
        sincronizarCampoBuscar();
    };

    // En modo iconos el campo de búsqueda está oculto visualmente: se saca del
    // orden de Tab y solo la lupa es enfocable. En modo expandido, en la expansión
    // temporal y en móvil se quita el tabindex y el campo vuelve a ser normal.
    // (Sin JS el servidor no pone tabindex: el campo siempre es enfocable.)
    function sincronizarCampoBuscar() {
        if (!campoBuscar) {
            return;
        }

        if (enModoIconos()) {
            campoBuscar.tabIndex = -1;
        } else {
            campoBuscar.removeAttribute('tabindex');
        }
    }

    const actualizarBotones = () => {
        actualizarBotonMovil();
        actualizarPestana();
    };

    // ----- Escritorio: modo de solo iconos -----
    // Cambio hecho a mano con la pestaña: se anima y se guarda la preferencia.
    const cambiarPlegada = (plegada) => {
        raiz.classList.add('barra-transicion');
        raiz.classList.remove('barra-temporal');
        raiz.classList.toggle('barra-plegada', plegada);
        guardarPreferenciaBarra(plegada);
        actualizarPestana();
    };

    // Expansión temporal (buscador o "Más"): la barra se abre por encima del
    // contenido, sin moverlo y SIN guardar nada. Al recargar vuelve a iconos.
    const expandirTemporal = () => {
        if (!enModoIconos()) {
            return;
        }

        raiz.classList.add('barra-transicion', 'barra-temporal');
        actualizarPestana();
    };

    // Vuelve a solo iconos. Si el foco estaba en algo que se oculta (el campo de
    // búsqueda o un enlace de "Más"), pasa al icono que lo abrió.
    const terminarTemporal = ({ moverFoco = true } = {}) => {
        if (!esTemporal()) {
            return;
        }

        raiz.classList.remove('barra-temporal');
        actualizarPestana();

        if (!moverFoco) {
            return;
        }

        const activo = document.activeElement;

        if (activo === campoBuscar) {
            botonBuscar?.focus();
        } else if (mas && mas.querySelector('.menu-mas-lista')?.contains(activo)) {
            tituloMas?.focus();
        }
    };

    pestana.addEventListener('click', () => {
        if (!escritorio.matches) {
            return;
        }

        if (esTemporal()) {
            // Estaba abierta solo un momento: se contrae otra vez (la preferencia ya es "iconos").
            terminarTemporal({ moverFoco: false });
        } else {
            cambiarPlegada(!estaPlegada());
        }
    });

    // Lupa en modo iconos (clic, Enter o Espacio llegan como 'click'): en vez de
    // enviar una búsqueda vacía, expande la barra y enfoca el campo.
    // expandirTemporal() ya le quitó el tabindex al campo antes del focus().
    botonBuscar?.addEventListener('click', (e) => {
        if (enModoIconos()) {
            e.preventDefault();
            expandirTemporal();
            campoBuscar?.focus();
        }
    });

    // Respaldo: en modo iconos el formulario del buscador nunca se envía,
    // venga el envío de donde venga. En modo expandido se envía como siempre.
    campoBuscar?.form?.addEventListener('submit', (e) => {
        if (enModoIconos()) {
            e.preventDefault();
        }
    });

    // "Más" en modo iconos: expande la barra y abre la lista (el clic y Enter/Espacio
    // en <summary> llegan como 'click'). Así se leen los enlaces completos.
    tituloMas?.addEventListener('click', (e) => {
        if (enModoIconos()) {
            e.preventDefault();
            expandirTemporal();
            mas.open = true;
        }
    });

    // La expansión temporal se cierra cuando el foco o el puntero salen de la barra.
    // Como la barra está encima del contenido, cerrarla no mueve nada debajo del clic.
    document.addEventListener('focusin', (e) => {
        if (esTemporal() && !menu.contains(e.target)) {
            terminarTemporal({ moverFoco: false });
        }
    });

    document.addEventListener('pointerdown', (e) => {
        if (esTemporal() && !menu.contains(e.target)) {
            terminarTemporal({ moverFoco: false });
        }
    });

    // ----- Móvil: panel deslizante (igual que antes) -----
    const abrirMenu = () => {
        document.body.classList.add('menu-abierto');
        // Mientras el menú está abierto, el teclado no puede salir al contenido de atrás.
        columna.inert = true;
        fijarBanner(true);
        actualizarBotonMovil();
        menu.querySelector('a')?.focus();
    };

    const cerrarMenu = () => {
        if (!estaAbierto()) {
            return;
        }

        document.body.classList.remove('menu-abierto');
        columna.inert = false;
        fijarBanner(false);
        actualizarBotonMovil();
        boton.focus();
    };

    boton.addEventListener('click', () => {
        // El botón solo se muestra en móvil; en escritorio manda la pestaña.
        if (escritorio.matches) {
            return;
        }

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
            terminarTemporal();
        }
    });

    // Estado guardado: se aplica SIN transición (la clase barra-transicion
    // todavía no está), así no se ve la animación en cada página.
    // En móvil la clase no tiene efecto (el CSS de iconos es solo de escritorio).
    raiz.classList.toggle('barra-plegada', leerPreferenciaBarra());
    // La barra ya no se oculta nunca del todo: no hace falta inert.
    menu.inert = false;
    actualizarBotones();

    // Al cruzar los 900 px: se cierra el panel móvil y la expansión temporal,
    // sin animación, y los dos botones quedan coherentes con el modo nuevo.
    escritorio.addEventListener('change', () => {
        raiz.classList.remove('barra-transicion');
        cerrarMenu();
        terminarTemporal({ moverFoco: false });
        actualizarBotones();
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

// ===== Foto de perfil (NP-39 paso 3): mejora progresiva =====
// Sin JS: se elige el archivo y se pulsa "Subir foto". Con JS: el botón se oculta
// y la foto se envía sola al elegirla.
// OJO: estas comprobaciones son solo COMODIDAD (avisar antes de subir 5 MB para
// nada). La seguridad está en el servidor: UpdateAvatarRequest + ProcesadorAvatar.
const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_TIPOS = ['image/jpeg', 'image/png', 'image/webp'];

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('avatar-archivo');
    const form = input?.form;

    if (!input || !form) {
        return;
    }

    const error = document.getElementById('avatar-error');
    const ayudaId = 'avatar-ayuda';

    form.querySelector('.perfil-foto-enviar')?.classList.add('perfil-foto-enviar-oculto');

    const mostrarError = (mensaje) => {
        if (error) {
            // textContent: el texto nunca se interpreta como HTML.
            error.textContent = mensaje;
            error.hidden = false;
            input.setAttribute('aria-describedby', `${ayudaId} ${error.id}`);
        }
        input.setAttribute('aria-invalid', 'true');
    };

    input.addEventListener('change', () => {
        const archivo = input.files?.[0];

        if (!archivo) {
            return;
        }

        if (archivo.size > AVATAR_MAX_BYTES || !AVATAR_TIPOS.includes(archivo.type)) {
            mostrarError('La foto de perfil debe ser una imagen JPG, PNG o WebP de 2 MB como máximo.');
            input.value = '';
            return;
        }

        form.classList.add('subiendo');
        form.setAttribute('aria-busy', 'true');
        form.requestSubmit();
        // DESPUÉS de requestSubmit: el navegador ya tomó los datos del formulario
        // (un input deshabilitado ANTES no se enviaría). Evita un segundo envío.
        input.disabled = true;
    });

    // Volver con el botón "Atrás" puede restaurar la página desde la caché del
    // navegador con el estado "subiendo": se limpia.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            form.classList.remove('subiendo');
            form.removeAttribute('aria-busy');
            input.disabled = false;
            input.value = '';
        }
    });
});

document.addEventListener('submit', (e) => {
    const msg = e.submitter?.dataset?.confirm ?? e.target.dataset?.confirm;
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});
