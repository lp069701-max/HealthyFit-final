<?php
/* Autor de la página web: Leonel. P */
/* =====================================================================
   HEALTHYFIT · php/contacto.php — recibe el FORMULARIO DE CONTACTO (paginas/contacto.html)
   Se guarda en la base de datos (tabla contactos) y en php/datos/contactos.csv, y te llega por correo (DESTINO en config.php)
   ===================================================================== */
require __DIR__ . '/config.php';

solo_post();
trampa_spam();
limitar_envios('contacto');

$nombre   = campo('nombre', 100);
$correo   = campo('correo', 150);
$telefono = campo('telefono', 30);
$asunto   = campo('asunto', 30);
$mensaje  = campo('mensaje', 3000);
$terminos = campo('terminos', 5) !== '';

$asuntos = [
    'membresia'     => 'Membresías y planes',
    'entrenamiento' => 'Entrenamiento personalizado',
    'nutricion'     => 'Planes de alimentación',
    'productos'     => 'Productos y suplementos',
    'otro'          => 'Otro',
];

if (!es_email($correo))                  { responder(false, 'Escribe un correo válido.', 422); }
if (!isset($asuntos[$asunto]))           { responder(false, 'Elige un asunto.', 422); }
if (mb_strlen($mensaje) < 10)            { responder(false, 'El mensaje debe tener al menos 10 letras.', 422); }
if (!$terminos)                          { responder(false, 'Debes aceptar los términos y condiciones.', 422); }

$cuerpo = "Nuevo mensaje desde el formulario de contacto\n"
        . "----------------------------------------------\n"
        . "Nombre:   " . ($nombre !== '' ? $nombre : '(no indicado)') . "\n"
        . "Correo:   " . $correo . "\n"
        . "Teléfono: " . ($telefono !== '' ? $telefono : '(no indicado)') . "\n"
        . "Asunto:   " . $asuntos[$asunto] . "\n"
        . "Fecha:    " . date('d/m/Y H:i') . "\n"
        . "IP:       " . ip_visitante() . "\n\n"
        . "Mensaje:\n" . $mensaje . "\n";

$enCsv = guardar_csv('contactos', [$nombre, $correo, $telefono, $asuntos[$asunto], $mensaje]);
$enDb  = guardar_db('contactos', [
    'nombre'   => $nombre,
    'correo'   => $correo,
    'telefono' => $telefono,
    'asunto'   => $asuntos[$asunto],
    'mensaje'  => $mensaje,
    'ip'       => ip_visitante(),
]);
$guardado = $enCsv || $enDb;
$enviado  = enviar_aviso('Contacto: ' . $asuntos[$asunto], $cuerpo, $correo);

// Solo es error si no se pudo ni guardar ni avisar. (En XAMPP el correo suele fallar, pero el mensaje queda guardado.)
if (!$enviado && !$guardado) {
    responder(false, 'No pudimos recibir tu mensaje. Escríbenos por WhatsApp.', 500);
}
$primero = $nombre !== '' ? ', ' . explode(' ', $nombre)[0] : '';
responder(true, '¡Gracias' . $primero . '! Te responderemos en menos de 24 horas.');