{{--
    EXCEPCIÓN DOCUMENTADA a la regla "toda salida con dobles llaves" (NP-46 paso 3).
    Es la ÚNICA vista que imprime sin escapar; un test lo comprueba por ruta exacta.

    Por qué: esta vista es el cuerpo text/plain del correo (ContactMessage solo
    define "text"). Un cliente de correo NO interpreta HTML en text/plain, así que
    aquí no hay riesgo de XSS. Escapar corrompería el dato: "Tom & Jerry" llegaría
    como "Tom &amp; Jerry", y al importarlo luego por IMAP (paso 5) se volvería a
    escapar al mostrarlo (doble escapado).

    El escapado se hace donde sí importa: al mostrar el texto en HTML (bandeja del
    panel, paso 7), siempre con dobles llaves. Si algún día se añade una vista HTML
    a este correo, NO puede reutilizar esta plantilla: los tests de ContactoCorreoTest
    fallan si el Mailable define view, html, markdown o htmlString.
--}}
Nuevo mensaje desde el formulario de contacto de Nexus Plays.

Nombre: {!! $nombre !!}
Correo: {!! $correo !!}

Mensaje:
{!! $mensaje !!}

--
Autorizó el tratamiento de datos el {!! $autorizadoEn !!} (política versión {!! $legalVersion !!}). Ref: {!! $messageId !!}
