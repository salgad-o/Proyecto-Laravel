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

    const abrirMenu = () => {
        document.body.classList.add('menu-abierto');
        boton.setAttribute('aria-expanded', 'true');
        textoBoton.textContent = 'Cerrar menú';
        // Mientras el menú está abierto, el teclado no puede salir al contenido de atrás.
        columna.inert = true;
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

document.addEventListener('submit', (e) => {
    const msg = e.submitter?.dataset?.confirm ?? e.target.dataset?.confirm;
    if (msg && !confirm(msg)) {
        e.preventDefault();
    }
});
