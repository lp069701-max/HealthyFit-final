<?php
/* Autor de la página web: Leonel. P */
/* =====================================================================
   HEALTHYFIT · php/config.php
   Configuración y funciones comunes de contacto.php, suscribir.php y comentarios.php
   Guarda cada envío en: 1) base de datos MySQL (phpMyAdmin)  2) respaldo .csv  3) correo
   ===================================================================== */
declare(strict_types=1);

/* ---------- LO ÚNICO QUE DEBES CAMBIAR ---------- */
const DESTINO    = 'info@healthyfit.com';          // ← correo donde TÚ recibirás todos los avisos
const REMITENTE  = 'no-responder@tudominio.com';   // ← correo de tu dominio (el hosting suele exigirlo para que mail() funcione)
const NOMBRE_SITIO = 'HealthyFit';

/* Base de datos (XAMPP por defecto: usuario root y sin contraseña).
   En un hosting real, cambia estos 4 datos por los que te da el hosting. */
const DB_HOST    = '127.0.0.1';
const DB_NOMBRE  = 'healthyfit';
const DB_USUARIO = 'root';
const DB_CLAVE   = '';
/* ------------------------------------------------ */

const CARPETA_DATOS = __DIR__ . '/datos';           // aquí se guarda un respaldo (.csv) de todo lo recibido

/* Tablas y columnas permitidas (deben existir: ejecuta setup.sql en phpMyAdmin) */
const TABLAS_DB = [
    'contactos'   => ['nombre', 'correo', 'telefono', 'asunto', 'mensaje', 'ip'],
    'suscriptores'=> ['correo', 'ip'],
    'comentarios' => ['nombre', 'correo', 'comentario', 'ip'],
];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Responde en JSON y termina. */
function responder(bool $ok, string $mensaje, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Solo acepta envíos por POST. */
function solo_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        responder(false, 'Método no permitido.', 405);
    }
}

/** Campo del formulario, limpio y con largo máximo. */
function campo(string $nombre, int $max = 500): string {
    $v = isset($_POST[$nombre]) && is_string($_POST[$nombre]) ? $_POST[$nombre] : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

/** Quita saltos de línea (evita inyección de cabeceras en el correo). */
function linea_segura(string $s): string {
    return trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
}

function es_email(string $v): bool {
    return (bool) filter_var($v, FILTER_VALIDATE_EMAIL);
}

/** Campo-trampa "web": los humanos no lo ven; los robots sí lo llenan. Si viene lleno, se finge éxito y no se hace nada. */
function trampa_spam(): void {
    if (campo('web') !== '') {
        responder(true, '¡Gracias!');
    }
}

/** Evita envíos repetidos seguidos desde el mismo navegador. */
function limitar_envios(string $clave, int $segundos = 15): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $k = 'ultimo_' . $clave;
    $ahora = time();
    if (isset($_SESSION[$k]) && $ahora - (int) $_SESSION[$k] < $segundos) {
        responder(false, 'Espera unos segundos antes de volver a enviar.', 429);
    }
    $_SESSION[$k] = $ahora;
}

/** Celda de CSV segura (evita que Excel ejecute fórmulas). */
function celda_csv(string $s): string {
    $s = linea_segura($s);
    return preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
}

/** Guarda una fila en datos/<archivo>.csv (carpeta protegida con .htaccess). Devuelve true si se guardó. */
function guardar_csv(string $archivo, array $fila): bool {
    if (!is_dir(CARPETA_DATOS)) { @mkdir(CARPETA_DATOS, 0755, true); }
    $ruta = CARPETA_DATOS . '/' . $archivo . '.csv';
    $nuevo = !file_exists($ruta);
    $f = @fopen($ruta, 'ab');
    if (!$f) { return false; }
    $ok = false;
    if (flock($f, LOCK_EX)) {
        if ($nuevo) { fwrite($f, "\xEF\xBB\xBF"); }                         // para que Excel lea bien las tildes
        $ok = fputcsv($f, array_merge([date('Y-m-d H:i:s')], array_map('celda_csv', $fila)), ',', '"', '\\') !== false;
        fflush($f);
        flock($f, LOCK_UN);
    }
    fclose($f);
    return $ok;
}

/** Conexión a MySQL (una sola vez). Devuelve null si no se puede conectar: el sitio sigue funcionando con el .csv. */
function conexion_db(): ?PDO {
    static $pdo = null, $intentado = false;
    if ($intentado) { return $pdo; }
    $intentado = true;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NOMBRE . ';charset=utf8mb4',
            DB_USUARIO, DB_CLAVE,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Inserta una fila en la tabla indicada. Solo acepta las tablas y columnas de TABLAS_DB. Devuelve true si se guardó. */
function guardar_db(string $tabla, array $datos): bool {
    $permitidas = TABLAS_DB[$tabla] ?? null;
    $pdo = $permitidas ? conexion_db() : null;
    if (!$pdo) { return false; }
    $datos = array_intersect_key($datos, array_flip($permitidas));
    if (!$datos) { return false; }
    $cols = array_keys($datos);
    $sql = 'INSERT INTO `' . $tabla . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
    try {
        $pdo->prepare($sql)->execute($datos);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** ¿Ya existe ese valor en esa columna? (para no repetir suscriptores). */
function existe_db(string $tabla, string $columna, string $valor): bool {
    $permitidas = TABLAS_DB[$tabla] ?? null;
    $pdo = ($permitidas && in_array($columna, $permitidas, true)) ? conexion_db() : null;
    if (!$pdo) { return false; }
    try {
        $q = $pdo->prepare('SELECT 1 FROM `' . $tabla . '` WHERE `' . $columna . '` = :v LIMIT 1');
        $q->execute(['v' => $valor]);
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Envía un aviso a DESTINO. */
function enviar_aviso(string $asunto, string $cuerpo, string $responderA = ''): bool {
    $cabeceras = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . NOMBRE_SITIO . ' <' . REMITENTE . '>',
    ];
    if ($responderA !== '' && es_email($responderA)) {
        $cabeceras[] = 'Reply-To: ' . linea_segura($responderA);
    }
    $asuntoCodificado = '=?UTF-8?B?' . base64_encode('[' . NOMBRE_SITIO . '] ' . linea_segura($asunto)) . '?=';
    return @mail(DESTINO, $asuntoCodificado, $cuerpo, implode("\r\n", $cabeceras));
}

/** IP del visitante (para el aviso). */
function ip_visitante(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}