<?php
/**
 * leads.php — visor de los leads que entran por el QR físico de Sumba Hills.
 *
 * Lee private/leads.csv (la fuente de verdad que escribe api/lead.php) y muestra
 * EXCLUSIVAMENTE las filas con source = 'sumba-hills-qr'. Los leads del formulario
 * de la landing ('sumbahills-brochure') y las filas de pruebas (diag, e2e-test…)
 * quedan fuera a propósito.
 *
 * No lee de Supabase: la RLS de la tabla `leads` es INSERT-only para `anon` (no hay
 * SELECT), así que la réplica no es consultable sin una clave de servicio — y el CSV
 * local ya es la fuente de verdad.
 *
 * Contraseña: hash bcrypt abajo. Para cambiarla, sustituir la constante por el
 * resultado de  php -r "echo password_hash('LA_NUEVA', PASSWORD_BCRYPT);"
 */

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const PASS_HASH = '$2y$12$dKhplk1rUKhDHs/sLfk5wuFvj9L/Kxc8yOcpmtxtITecgfMcQJlt6';
const CSV_FILE  = __DIR__ . '/private/leads.csv';
const QR_SOURCE = 'sumba-hills-qr';   // el valor que manda qr.html

// Condiciones del equipo que trabaja el QR (IDR). Un solo sitio donde tocarlas.
const PAY_FIXED_MONTH = 2000000;
const PAY_PER_LEAD    = 20000;
const PAY_PER_CLOSED  = 5000000;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: leads.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (password_verify($_POST['password'] ?? '', PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['sh_leads_auth'] = true;
        header('Location: leads.php');   // POST/Redirect/GET: recargar no reenvía la clave
        exit;
    }
    usleep(700000);                      // frena el intento por fuerza bruta
    $error = 'Incorrect password.';
}
$auth = !empty($_SESSION['sh_leads_auth']);

// Este panel es SOLO LECTURA a propósito (27-jul-2026): lo usan los operadores del QR,
// que no deben poder vaciar la lista. Hubo una acción de reset aquí y se retiró.
// Para poner los leads a cero: hPanel > File Manager > public_html/sumbahills/private/
// leads.csv, dejar únicamente la primera línea (la cabecera de columnas).

// --- Lectura del CSV -------------------------------------------------------
// Columnas: timestamp,email,name,whatsapp,source,property,ip
$rows      = [];
$csvMissing = false;
if ($auth) {
    if (!is_readable(CSV_FILE)) {
        $csvMissing = true;
    } else {
        $fh = fopen(CSV_FILE, 'r');
        fgetcsv($fh);                    // cabecera
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 5 || $r[4] !== QR_SOURCE) continue;
            $rows[] = [
                'ts'       => $r[0],
                'email'    => unquote($r[1]),
                'name'     => unquote($r[2]),
                'whatsapp' => unquote($r[3]),
            ];
        }
        fclose($fh);
        $rows = array_reverse($rows);    // el más reciente arriba
    }
}

/**
 * api/lead.php antepone una comilla simple a los valores que empiezan por = + - @
 * (anti inyección de fórmula en Excel). Todos los WhatsApp con prefijo internacional
 * llegan aquí como '+62… — se quita solo para mostrar.
 */
function unquote($s) {
    return (isset($s[0]) && $s[0] === "'") ? substr($s, 1) : $s;
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 2000000 → 'IDR 2.000.000' (separador de miles indonesio) */
function idr($n) { return 'IDR ' . number_format((float)$n, 0, ',', '.'); }

/** '2026-07-15T09:12:44+00:00' → '15 Jul 2026 · 09:12' */
function fecha($iso) {
    $t = strtotime($iso);
    return $t ? date('d M Y · H:i', $t) : $iso;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR leads · Sumba Hills</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<style>
:root{
  /* Misma paleta que qr.html (dossier real del cliente) */
  --tg:#8B5E3C;      /* Terracota/madera — acento principal */
  --tg-dark:#6B4429; /* hover */
  --dl:#3B2A22;      /* Marron oscuro (espresso) — titulares */
  --va:#2E2620;      /* Texto oscuro cálido */
  --rl:#F5F0E6;      /* Arena clara — fondo */
  --line:rgba(46,38,32,.16);
  --wa:#25D366;
  --sans:'Jost',ui-sans-serif,system-ui,sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--rl);color:var(--va);font-family:var(--sans);line-height:1.6;-webkit-font-smoothing:antialiased;min-height:100vh}
a{color:var(--tg)}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--tg);outline-offset:2px}
main{max-width:920px;margin:0 auto;padding:clamp(28px,6vh,52px) clamp(20px,6vw,40px) clamp(40px,7vh,64px)}
main.narrow{max-width:560px}
h1{font-family:var(--sans);font-weight:500;font-size:clamp(28px,6vw,44px);color:var(--dl);line-height:1.08;margin:0}
.by-line{font-size:12.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--tg);margin:8px 0 22px;font-weight:600}
.lede{font-size:clamp(15px,2vw,17px);color:rgba(46,52,55,.82);margin:0 0 28px;max-width:52ch}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:clamp(22px,4vw,32px);box-shadow:0 18px 40px -20px rgba(16,76,79,.25)}
.card.flush{padding:0;overflow:hidden}
form{display:flex;flex-direction:column;gap:16px}
label{font-size:11.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(46,52,55,.7);margin-bottom:7px;display:block}
input{font-family:var(--sans);font-size:16px;padding:14px 16px;border:1px solid var(--line);border-radius:9px;background:var(--rl);color:var(--va);width:100%;transition:border-color .15s,background .15s}
input:focus{border-color:var(--tg);background:#fff}
.err{font-size:12.5px;color:#a33;margin-top:5px}
button[type="submit"]{background:var(--tg);color:var(--rl);border:none;border-radius:9px;padding:16px 18px;font-family:var(--sans);font-weight:600;font-size:14px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;transition:background .15s,transform .1s}
button[type="submit"]:hover{background:var(--tg-dark)}
button[type="submit"]:active{transform:scale(.99)}
.note{font-size:12.5px;color:rgba(46,52,55,.55);margin-top:12px;text-align:center}

/* Cabecera del listado */
.bar{display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 22px}
.count{font-size:13px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--tg)}
.logout{font-size:12.5px;color:rgba(46,52,55,.55);text-decoration:none;border-bottom:1px solid var(--line)}
.logout:hover{color:var(--va)}

/* Tabla */
.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%;min-width:640px}
th{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(46,52,55,.6);text-align:left;padding:16px 20px;background:var(--rl);border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:16px 20px;border-bottom:1px solid var(--line);font-size:14.5px;vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(139,94,60,.045)}
.when{color:rgba(46,52,55,.6);font-size:13px;white-space:nowrap}
.name{font-weight:500;color:var(--dl)}
td a{text-decoration:none;border-bottom:1px solid transparent}
td a:hover{border-bottom-color:var(--tg)}
.wa{display:inline-flex;align-items:center;gap:7px;color:var(--va);white-space:nowrap}
.wa svg{width:16px;height:16px;flex:none;color:var(--wa)}
.wa:hover{color:var(--tg)}
.empty{padding:44px 24px;text-align:center;color:rgba(46,52,55,.55);font-size:14.5px}

/* Condiciones del equipo */
.terms{margin-top:26px}
.terms h2{font-family:var(--sans);font-weight:500;font-size:clamp(19px,3vw,23px);color:var(--dl);margin:0 0 3px}
.terms .sub{font-size:12.5px;color:rgba(46,52,55,.55);margin:0 0 14px}
.rows{margin:0}
.row{display:flex;justify-content:space-between;align-items:baseline;gap:20px;padding:13px 0;border-bottom:1px solid var(--line)}
.row:last-child{border-bottom:none}
.row dt{font-size:14.5px;color:var(--va)}
.row dd{margin:0;font-weight:600;color:var(--dl);white-space:nowrap;font-variant-numeric:tabular-nums}
.accrued{margin-top:16px;padding-top:16px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:baseline;gap:20px;flex-wrap:wrap}
.accrued .lbl{font-size:13px;color:rgba(46,52,55,.7)}
.accrued .val{font-size:20px;font-weight:600;color:var(--tg);white-space:nowrap;font-variant-numeric:tabular-nums}
.terms .foot{font-size:12.5px;color:rgba(46,52,55,.55);margin-top:12px}
footer{border-top:1px solid var(--line);max-width:920px;margin:40px auto 0;padding:20px clamp(20px,6vw,40px) 36px;font-size:12px;color:rgba(46,52,55,.55)}
@media (max-width:560px){th,td{padding:13px 14px}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<main class="narrow">
  <h1>QR leads</h1>
  <p class="by-line">Sumba Hills · by Lawang Properties</p>
  <p class="lede">Private page. Enter the password to see the leads captured through the QR code.</p>

  <div class="card">
    <form method="post" autocomplete="off">
      <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
        <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
      </div>
      <button type="submit">Enter</button>
    </form>
  </div>
</main>

<?php else: ?>
<main>
  <div class="bar">
    <div>
      <h1>QR leads</h1>
      <p class="by-line" style="margin-bottom:0">Sumba Hills · by Lawang Properties</p>
    </div>
    <a class="logout" href="?logout=1">Log out</a>
  </div>

  <?php /* Con el CSV ilegible NO se enseña un contador: un conteo que falla no es un cero. */ ?>
  <?php if (!$csvMissing): ?>
  <p class="count"><?= count($rows) ?> lead<?= count($rows) === 1 ? '' : 's' ?> from the QR code</p>
  <?php endif; ?>
  <p class="lede" style="margin-top:6px">Only submissions from the printed QR form (<code>qr.html</code>). Landing-page signups and test entries are not shown.</p>

  <div class="card flush">
    <?php if ($csvMissing): ?>
      <p class="empty">Could not read <code>private/leads.csv</code> on the server.<br>This is not "zero leads" — the file is missing or unreadable.</p>
    <?php elseif (!$rows): ?>
      <p class="empty">No QR leads yet.</p>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>When</th><th>Name</th><th>WhatsApp</th><th>Email</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $digits = preg_replace('/\D/', '', $r['whatsapp']); ?>
          <tr>
            <td class="when"><?= e(fecha($r['ts'])) ?></td>
            <td class="name"><?= e($r['name']) !== '' ? e($r['name']) : '—' ?></td>
            <td>
              <?php if ($digits !== ''): ?>
                <a class="wa" href="https://wa.me/<?= e($digits) ?>" target="_blank" rel="noopener">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.05 2C6.526 2 2.05 6.477 2.05 12c0 1.928.535 3.734 1.464 5.27L2 22l4.86-1.475A9.94 9.94 0 0 0 12.05 22c5.523 0 10-4.477 10-10S17.573 2 12.05 2zm0 18.15a8.13 8.13 0 0 1-4.152-1.136l-.298-.177-3.075.933.94-3.058-.194-.313A8.15 8.15 0 1 1 20.2 12.15a8.16 8.16 0 0 1-8.15 8z"/><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
                  <?= e($r['whatsapp']) ?>
                </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <section class="card terms">
    <h2>Team terms</h2>
    <p class="sub">Compensation for the team working the printed QR.</p>
    <dl class="rows">
      <div class="row"><dt>Fixed salary</dt><dd><?= idr(PAY_FIXED_MONTH) ?> / month</dd></div>
      <div class="row"><dt>Variable — per lead captured</dt><dd><?= idr(PAY_PER_LEAD) ?> / lead</dd></div>
      <div class="row"><dt>Variable — per closed lead</dt><dd><?= idr(PAY_PER_CLOSED) ?> / closed lead</dd></div>
    </dl>
    <?php /* Solo se calcula lo que este panel sabe de verdad: los leads del QR. */ ?>
    <?php if (!$csvMissing): ?>
    <div class="accrued">
      <span class="lbl"><?= count($rows) ?> lead<?= count($rows) === 1 ? '' : 's' ?> × <?= idr(PAY_PER_LEAD) ?> — variable earned on the leads listed above</span>
      <span class="val"><?= idr(count($rows) * PAY_PER_LEAD) ?></span>
    </div>
    <?php endif; ?>
    <p class="foot">All-time total, not this month. It leaves out the fixed salary and the closed-lead bonus: this panel tracks neither months worked nor which leads closed.</p>
  </section>
</main>
<?php endif; ?>

<footer>&copy; 2026 Sumba Hills · A Lawang project · Indonesia</footer>

</body>
</html>
