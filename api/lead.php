<?php
/**
 * lead.php — captura de leads del formulario de interés (QR Sumba Hills).
 * Añade una fila a private/leads.csv (fuente de verdad local) y, en mejor
 * esfuerzo, replica el lead en la tabla `leads` del Supabase de Lawang
 * (Sumba Hills es un subdominio de Lawang, mismo negocio).
 */
header('Content-Type: application/json; charset=utf-8');

const SUPABASE_URL = 'https://vtulllundrfennhjddhc.supabase.co';
const SUPABASE_ANON_KEY = 'sb_publishable_B_ot_6lNVRLiWiEMtApYOQ_3Ho3xNUg';
const BROCHURE_URL = 'https://sumbahills.lawangproperties.com/download/Sumba-Hills-Welcome-Brochure.pdf';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
$name     = isset($_POST['name'])     ? trim($_POST['name'])     : '';
$whatsapp = isset($_POST['whatsapp']) ? trim($_POST['whatsapp']) : '';
$source   = isset($_POST['source'])   ? trim($_POST['source'])   : '';
$property = isset($_POST['property']) ? trim($_POST['property']) : '';

// Validación de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'email']);
    exit;
}

// Recorta campos para evitar payloads enormes (no toca el contenido)
$clean = function ($s) {
    $s = preg_replace('/[\r\n]+/', ' ', $s);
    return mb_substr($s, 0, 200);
};
$name     = $clean($name);
$whatsapp = $clean(preg_replace('/[^\d+]/', '', $whatsapp));
$source   = $clean($source);
$property = $clean($property);

// Anti inyección de fórmula CSV (Excel ejecuta celdas que empiezan por = + - @) — SOLO para el CSV,
// nunca para los valores que van a Supabase (corromperían el número/nombre real).
$csvSafe = function ($s) {
    if ($s !== '' && strpos('=+-@', $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
};

// private/ no es servible por web (fuera del docroot lógico); se crea si falta
$dir = __DIR__ . '/../private';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$file = $dir . '/leads.csv';

$new = !file_exists($file);
$fh  = @fopen($file, 'a');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write']);
    exit;
}
if ($new) {
    fputcsv($fh, ['timestamp', 'email', 'name', 'whatsapp', 'source', 'property', 'ip']);
}
fputcsv($fh, [
    date('c'),
    $csvSafe($email),
    $csvSafe($name),
    $csvSafe($whatsapp),
    $csvSafe($source),
    $csvSafe($property),
    $_SERVER['REMOTE_ADDR'] ?? '',
]);
fclose($fh);

// Réplica en Supabase — mejor esfuerzo: si falla, el lead ya está a salvo en el CSV de arriba.
$ch = curl_init(SUPABASE_URL . '/rest/v1/leads');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
        'Prefer: return=minimal',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'email'    => $email,
        'name'     => $name !== '' ? $name : null,
        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
        'source'   => $source,
        'project'  => $property,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
]);
@curl_exec($ch);
curl_close($ch);

// Email con el folleto — SMTP autenticado (mismo cliente minimalista sin dependencias que
// ya funciona en producción en B2K, ver proyectos/B2K/api/subscribe.php). Enlace al PDF,
// no adjunto: 18MB es demasiado para ir pegado a un correo.
// Config real (host/usuario/contraseña del buzón) SOLO en private/mail-config.php en el
// servidor — nunca en git. Sin ese archivo, se salta el envío sin romper el resto (mejor esfuerzo).
$mailCfgFile = __DIR__ . '/../private/mail-config.php';
$emailed = false;
$emailDebug = 'no config file';
if (file_exists($mailCfgFile)) {
    $mailCfg = require $mailCfgFile;
    $subject = 'Your Sumba Hills brochure';
    $html = '<!DOCTYPE html><html><body style="margin:0;background:#F5F0E6;font-family:Arial,Helvetica,sans-serif;color:#2E3437">'
          . '<div style="max-width:480px;margin:0 auto;padding:32px 24px">'
          . '<h1 style="font-size:22px;color:#104C4F;margin:0 0 8px">Sumba Hills</h1>'
          . '<p style="font-size:15px;line-height:1.6">Thank you for your interest in Sumba Hills. Here is your welcome brochure:</p>'
          . '<p style="margin:24px 0"><a href="' . BROCHURE_URL . '" style="background:#485B37;color:#F5F0E6;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block">Download the brochure (PDF)</a></p>'
          . '<p style="font-size:13px;color:#666">Any questions? WhatsApp us at +62 811-3820-0932 or reply to this email.</p>'
          . '</div></body></html>';
    list($emailed, $emailDebug) = smtp_send($mailCfg, $email, $subject, $html);
}

// DEBUG TEMPORAL — solo visible con el token, se retira en cuanto se confirme el envío.
if (($_GET['debug'] ?? '') === 'sumbahills2026diag') {
    echo json_encode(['ok' => true, 'emailed' => $emailed, 'debug' => $emailDebug]);
    exit;
}

echo json_encode(['ok' => true]);

/**
 * Cliente SMTP minimalista sobre SSL (puerto 465) — copiado tal cual de
 * proyectos/B2K/api/subscribe.php (ya validado en producción, sin dependencias).
 */
function smtp_send($cfg, $to, $subject, $html) {
    $host   = $cfg['smtp_host'] ?? 'smtp.hostinger.com';
    $port   = (int)($cfg['smtp_port'] ?? 465);
    $secure = $cfg['smtp_secure'] ?? 'ssl';
    $user   = $cfg['smtp_user'] ?? '';
    $pass   = $cfg['smtp_pass'] ?? '';
    $fromE  = $cfg['from_email'] ?? $user;
    $fromN  = $cfg['from_name'] ?? 'Sumba Hills';

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20);
    if (!$fp) return [false, "connect: $errstr"];
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $d = '';
        while ($line = fgets($fp, 515)) {
            $d .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $d;
    };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    $read();                               // saludo 220
    $cmd('EHLO lawangproperties.com');
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $a = $cmd(base64_encode($pass));
    if (strpos($a, '235') === false) { fclose($fp); return [false, 'auth: ' . trim($a)]; }

    $cmd('MAIL FROM:<' . $fromE . '>');
    $r = $cmd('RCPT TO:<' . $to . '>');
    if (strpos($r, '250') === false && strpos($r, '251') === false) { fclose($fp); return [false, 'rcpt: ' . trim($r)]; }
    $cmd('DATA');

    $headers  = 'From: ' . mb_encode_mimeheader($fromN) . ' <' . $fromE . ">\r\n";
    $headers .= 'To: <' . $to . ">\r\n";
    $headers .= 'Subject: ' . mb_encode_mimeheader($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";

    $body = preg_replace('/^\./m', '..', $html);          // dot-stuffing
    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $final = $read();
    $cmd('QUIT');
    fclose($fp);
    return [strpos($final, '250') !== false, trim($final)];
}
