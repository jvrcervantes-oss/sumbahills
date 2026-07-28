<?php
/**
 * Rate-limiter por fichero, sin dependencias. Portado de B2K/api/throttle.php,
 * que lleva en produccion desde jul-2026; aqui solo cambia el prefijo.
 * Las marcas viven en  private/ratelimit/  (fuera de git y con 404 en .htaccess).
 * Uso:
 *   if (sh_throttle_blocked("login_$ip", 10, 600)) { ... 429 ... }
 *   sh_throttle_register("login_$ip", 600);
 */

function sh_throttle_dir() {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function sh_throttle_file($key) {
    return sh_throttle_dir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
}

function sh_throttle_read($key, $windowSecs) {
    $file = sh_throttle_file($key);
    $hits = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $cut  = time() - $windowSecs;
    return array_values(array_filter($hits, fn($t) => is_int($t) && $t > $cut));
}

/** true si la clave ya alcanzo el maximo de intentos dentro de la ventana. */
function sh_throttle_blocked($key, $maxHits, $windowSecs) {
    return count(sh_throttle_read($key, $windowSecs)) >= $maxHits;
}

/** Registra un intento y poda los que quedan fuera de ventana. */
function sh_throttle_register($key, $windowSecs) {
    $hits   = sh_throttle_read($key, $windowSecs);
    $hits[] = time();
    @file_put_contents(sh_throttle_file($key), json_encode($hits), LOCK_EX);
}

/** Limpia el historial de una clave (p.ej. tras un login correcto). */
function sh_throttle_clear($key) {
    $file = sh_throttle_file($key);
    if (is_file($file)) @unlink($file);
}
