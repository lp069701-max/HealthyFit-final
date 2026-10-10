<?php
/* Autor de la página web: Leonel. P */
/* =====================================================================
   HEALTHYFIT · php/suscribir.php — recibe la SUSCRIPCIÓN del pie de página (todas las páginas)
   Guarda el correo en la base de datos (tabla suscriptores) y en php/datos/suscriptores.csv, sin repetidos, y te avisa por correo.
   ===================================================================== */
require __DIR__ . '/config.php';

solo_post();
trampa_spam();
limitar_envios('suscribir');

$correo = mb_strtolower(campo('correo', 150));
if (!es_email($correo)) { responder(false, 'Escribe un correo válido, por ejemplo nombre@gmail.com', 422); }

// ¿Ya estaba suscrito? (base de datos y .csv)
$yaEsta = existe_db('suscriptores', 'correo', $correo);
$ruta = CARPETA_DATOS . '/suscriptores.csv';
if (!$yaEsta && is_file($ruta)) {
    foreach (file($ruta, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        $c = str_getcsv($linea, ',', '"', '\\');
        if (isset($c[1]) && mb_strtolower($c[1]) === $correo) { $yaEsta = true; break; }
    }
}
if ($yaEsta) { responder(true, '¡Ya estabas suscrito a HealthyFit!'); }

$enCsv = guardar_csv('suscriptores', [$correo]);
$enDb  = guardar_db('suscriptores', ['correo' => $correo, 'ip' => ip_visitante()]);
$enviado = enviar_aviso('Nueva suscripción',
    "Nuevo suscriptor\n----------------\nCorreo: $correo\nFecha:  " . date('d/m/Y H:i') . "\nIP:     " . ip_visitante() . "\n",
    $correo);

if (!$enCsv && !$enDb && !$enviado) {
    responder(false, 'No pudimos registrar tu suscripción. Inténtalo de nuevo más tarde.', 500);
}
responder(true, '¡Listo! Ya estás suscrito a HealthyFit.');