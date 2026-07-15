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
const MAIL_FROM = 'Sumba Hills <sumbahills@lawangproperties.com>';
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

// Email con el folleto — mejor esfuerzo con mail() nativo (sin credenciales SMTP propias
// todavía; si Hostinger no entrega bien, migrar a SMTP autenticado, ver reference_newsletter_lead_magnet).
// Enlace al PDF, no adjunto: 18MB es demasiado para ir pegado a un correo.
$subject = 'Your Sumba Hills brochure';
$html = '<div style="font-family:sans-serif;color:#2E3437;max-width:480px;margin:0 auto">'
      . '<h2 style="font-family:Georgia,serif;color:#104C4F;font-weight:normal">Sumba Hills</h2>'
      . '<p>Thank you for your interest in Sumba Hills. Here is your welcome brochure:</p>'
      . '<p><a href="' . BROCHURE_URL . '" style="display:inline-block;background:#485B37;color:#F5F0E6;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Download the brochure (PDF)</a></p>'
      . '<p style="font-size:13px;color:#666">Questions? WhatsApp us at +62 811-3820-0932 or reply to this email at sumbahills@lawangproperties.com.</p>'
      . '</div>';
$headers = "MIME-Version: 1.0\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "From: " . MAIL_FROM . "\r\n"
         . "Reply-To: sumbahills@lawangproperties.com\r\n";
@mail($email, $subject, $html, $headers);

echo json_encode(['ok' => true]);
