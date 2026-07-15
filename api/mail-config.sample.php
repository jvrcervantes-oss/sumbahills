<?php
/**
 * PLANTILLA de configuración SMTP para el envío del folleto por email.
 *
 * PASOS (en el servidor, nunca en el repo):
 *   1. Crea el buzón real sumbahills@lawangproperties.com en hPanel (Emails).
 *   2. Copia este archivo a   ../private/mail-config.php
 *   3. Rellena 'smtp_pass' con la contraseña real del buzón directamente en el servidor.
 *
 * La carpeta private/ está en .gitignore: las credenciales no salen del servidor.
 */

return [
    // --- SMTP Hostinger (puerto 465 SSL recomendado) ---
    'smtp_host'   => 'smtp.hostinger.com',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',
    'smtp_user'   => 'sumbahills@lawangproperties.com',
    'smtp_pass'   => 'PON_AQUI_LA_CLAVE_DEL_CORREO',   // <-- rellenar en el servidor

    // --- Remitente ---
    'from_email'  => 'sumbahills@lawangproperties.com',
    'from_name'   => 'Sumba Hills',
];
