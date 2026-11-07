<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * NP-20 paso 1 (P2): toda clase que usan las vistas del panel de noticias tiene
 * estilos en resources/css/app.css. Evita volver a publicar una vista con clases
 * "huérfanas" (pestañas o chips sin CSS).
 *
 * Es un análisis ESTÁTICO del código Blade, no del HTML renderizado: así cubre
 * también las ramas que una sola petición no pinta (chip Publicada Y Borrador,
 * estado vacío, mensajes de error). Se leen:
 *  - class="..."  → se toman las palabras sin {{ }} (lo dinámico no se puede resolver);
 *  - clase="..."  → la prop del componente <x-avatar>, que termina en el class del <img>;
 *  - @class([...]) → de cada elemento del arreglo, la cadena con la que empieza
 *    ('chip-publicada' => ...): es el nombre de la clase, la condición se ignora.
 * Las clases que pone app.js en tiempo de ejecución no aparecen en las vistas, así
 * que no entran en la comprobación.
 */
class PanelNoticiasEstilosTest extends TestCase
{
    private const VISTAS = [
        // NP-20 paso 3: el panel es una tabla propia; ya no usa noticias/_tarjeta.
        'dashboard/noticias/index.blade.php',
        // NP-20 paso 5: la auditoría reutiliza la tabla del panel y agrega lo suyo.
        'auditoria/index.blade.php',
        // NP-20 paso 6a: usuarios, con la misma tabla y las mismas pestañas.
        'usuarios/index.blade.php',
    ];

    /**
     * @return list<string>
     */
    private function clasesDeLaVista(string $vista): array
    {
        $codigo = file_get_contents(resource_path('views/'.$vista));
        $this->assertIsString($codigo);

        // Los comentarios de Blade no se renderizan: no cuentan.
        $codigo = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $codigo);

        $clases = [];

        preg_match_all('/\b(?:class|clase)="([^"]*)"/', $codigo, $atributos);
        foreach ($atributos[1] as $valor) {
            $sinBlade = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $valor);
            foreach (preg_split('/\s+/', $sinBlade, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $clase) {
                $clases[] = $clase;
            }
        }

        // @class([...]): el arreglo termina en "])". Un $arreglo['clave'] dentro de una
        // condición deja "']" + "])", y la búsqueda no codiciosa corta en el correcto.
        preg_match_all('/@class\(\[(.*?)\]\)/s', $codigo, $directivas);
        foreach ($directivas[1] as $arreglo) {
            foreach (explode(',', $arreglo) as $elemento) {
                if (preg_match("/^\s*'([A-Za-z0-9_-]+)'/", $elemento, $nombre) === 1) {
                    $clases[] = $nombre[1];
                }
            }
        }

        return array_values(array_unique($clases));
    }

    public function test_el_analisis_encuentra_las_clases_conocidas(): void
    {
        // Control del propio test: si el análisis dejara de leer @class o clase="",
        // el test principal pasaría sin comprobar nada.
        $panel = $this->clasesDeLaVista(self::VISTAS[0]);

        foreach ([
            'panel-estado-tabs', 'panel-estado-tab', 'panel-estado-activo', 'panel-estado-total',
            'buscador-error', 'estado-vacio',
            'panel-tabla', 'panel-tabla-titulo', 'panel-tabla-resumen', 'panel-tabla-accion',
            'chip', 'chip-estado', 'chip-publicada', 'chip-borrador',
            'autor-avatar', 'panel-tabla-avatar',
        ] as $clase) {
            $this->assertContains($clase, $panel);
        }

        $auditoria = $this->clasesDeLaVista(self::VISTAS[1]);

        foreach ([
            'panel-tabla', 'panel-tabla-contenedor', 'panel-auditoria', 'auditoria-filtros',
            'chip-importancia', 'chip-alta', 'chip-media', 'chip-baja',
            'auditoria-plegable', 'auditoria-origen', 'panel-tabla-avatar',
        ] as $clase) {
            $this->assertContains($clase, $auditoria);
        }

        $usuarios = $this->clasesDeLaVista(self::VISTAS[2]);

        foreach ([
            'panel-estado-tabs', 'panel-tabla', 'panel-usuarios', 'chip-activo', 'chip-inactivo',
            'usuario-gestionar-boton', 'usuario-gestionar-chevron', 'usuario-panel-fila', 'usuario-panel-celda', 'usuario-gestion', 'boton-peligro', 'panel-tabla-avatar',
        ] as $clase) {
            $this->assertContains($clase, $usuarios);
        }
    }

    public function test_toda_clase_del_panel_de_noticias_existe_en_app_css(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($css);

        // Un selector dentro de un comentario no da estilos.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        foreach (self::VISTAS as $vista) {
            foreach ($this->clasesDeLaVista($vista) as $clase) {
                $this->assertMatchesRegularExpression(
                    '/\.'.preg_quote($clase, '/').'(?![A-Za-z0-9_-])/',
                    $css,
                    "La clase .{$clase} de {$vista} no tiene ningún selector en app.css."
                );
            }
        }
    }
}
