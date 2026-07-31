<?php
/**
 * leads-estudio.php — visor de leads para EL ESTUDIO. Todas las fuentes.
 *
 * Por qué existe (31-jul-2026): `leads.php` enseña solo `sumba-hills-qr` y eso
 * es correcto —se le entrega al equipo de calle, que cobra por lead del QR, así
 * que ver leads que no son suyos les ensuciaría la liquidación—. El efecto
 * lateral era que los del formulario de la landing (`sumbahills-brochure`) no
 * tenían NINGUNA pantalla: para verlos había que consultar Supabase a mano.
 *
 * Dos páginas y no una con roles, a propósito: la de los operadores no puede
 * tener dentro, ni apagado, el código que enseña lo que no deben ver. El día que
 * un `if` se equivoque, el fallo es una fuga hacia un tercero.
 *
 * Va con la paleta de Sumba Hills (31-jul-2026, decisión del owner). Nació con
 * la identidad de AxisWorks para que no se confundiera con la página del
 * cliente, pero se mira alternándola con `/leads` y con `qr.html`, y saltar
 * entre dos estilos cansa. Lo que la distingue es el título y el sello del pie,
 * no el color.
 *
 * Contraseña: DISTINTA de la de `leads.php`, y su hash NO está en este fichero
 * (ver el bloque de HASH_FILE, más abajo). Para generar una nueva:
 *   python -c "import bcrypt;print(bcrypt.hashpw(b'LA_NUEVA', bcrypt.gensalt(12)).decode())"
 *   y sustituir el `$2b$` inicial por `$2y$` (PHP no reconoce el prefijo `$2b$`).
 *
 * Solo lectura, igual que la otra: aquí no se borra ni se edita nada.
 */

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/**
 * El hash NO va aqui dentro: este repo es PUBLICO (github.com/jvrcervantes-oss/
 * sumbahills, comprobado el 31-jul-2026 sin autenticar). Un hash bcrypt
 * publicado se ataca offline, sin limite de intentos y sin que nadie lo note.
 *
 * Vive en `private/estudio.hash`, que el .htaccess de la raiz devuelve 404 y el
 * .gitignore no sube. Mismo patron que `contracts/private/mail.php` en Lawang.
 * Si el fichero no esta, la pagina no deja entrar a nadie: falla cerrada.
 */
const HASH_FILE = __DIR__ . '/private/estudio.hash';
const CSV_FILE  = __DIR__ . '/private/leads.csv';

/**
 * Devuelve el hash guardado, o '' si no hay fichero.
 *
 * Limpia lo que un editor de servidor suele colar sin avisar: el BOM de UTF-8
 * (tres bytes invisibles al principio — `trim()` NO los quita, y con ellos
 * `password_verify` falla siempre), comillas de un copiado descuidado y saltos
 * de línea de Windows. Sin esto, un fichero "que se ve bien" rechaza la
 * contraseña correcta y no hay forma de saber por qué.
 */
function passHash() {
    if (!is_readable(HASH_FILE)) return '';
    $h = (string)file_get_contents(HASH_FILE);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);   // BOM
    return trim($h, " \t\n\r\0\x0B\"'");
}

/** ¿Es un hash bcrypt de verdad? Si no lo es, el problema es el FICHERO, no la
 *  contraseña que teclea la persona — y hay que poder decirlo, porque
 *  `password_verify` devuelve false igual en los dos casos. */
function hashValido($h) {
    return (bool)preg_match('#^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$#', $h);
}

/** Fuentes conocidas -> cómo se llaman en cristiano. Lo que no esté aquí se
 *  enseña con su valor crudo: inventarle un nombre bonito a una fuente nueva
 *  la haría parecer prevista. */
const FUENTES = [
    'sumba-hills-qr'       => 'QR impreso (equipo de calle)',
    'sumbahills-web'       => 'Consulta desde la web',
    'sumbahills-brochure'  => 'Descarga del folleto (web anterior)',
];

/** Filas de prueba. No se ocultan —desaparecer datos es peor— se marcan y se
 *  dejan fuera del recuento de leads reales. */
function esPrueba(array $r) {
    // strpos y no str_contains: str_contains es PHP 8 y en el codigo ya
    // desplegado no hay ni una llamada que lo confirme. No merece la pena
    // arriesgar un fatal en produccion por ahorrar seis caracteres.
    $s = strtolower(($r['source'] ?? '') . ' ' . ($r['email'] ?? ''));
    foreach (array('test', 'diag', 'e2e') as $marca) {
        if (strpos($s, $marca) !== false) return true;
    }
    return false;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: leads-estudio');
    exit;
}

$hash = passHash();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sin hash no se compara nada: password_verify('', '') devuelve false, pero
    // no hay que fiarse de un caso limite para cerrar una puerta.
    if ($hash !== '' && password_verify($_POST['password'] ?? '', $hash)) {
        session_regenerate_id(true);
        $_SESSION['sh_estudio_auth'] = true;
        header('Location: leads-estudio');   // POST/Redirect/GET
        exit;
    }
    usleep(700000);
    $error = 'Contraseña incorrecta.';
}
$auth = !empty($_SESSION['sh_estudio_auth']);

// --- Lectura del CSV -------------------------------------------------------
// Columnas: timestamp,email,name,whatsapp,source,property,ip
// La IP se lee pero NO se enseña: no hace falta para trabajar el lead y es un
// dato personal más que pasearía por pantalla sin motivo.
$rows = $conteo = [];
$csvMissing = false;
$filtro = isset($_GET['source']) ? (string)$_GET['source'] : '';

if ($auth) {
    if (!is_readable(CSV_FILE)) {
        $csvMissing = true;
    } else {
        $fh = fopen(CSV_FILE, 'r');
        fgetcsv($fh);                              // cabecera
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 5) continue;
            $fila = [
                'ts'       => $r[0],
                'email'    => unquote($r[1]),
                'name'     => unquote($r[2]),
                'whatsapp' => unquote($r[3]),
                'source'   => $r[4],
                'property' => unquote($r[5] ?? ''),
            ];
            $fila['prueba'] = esPrueba($fila);
            // El contador cuenta SIEMPRE sobre el total, no sobre lo filtrado:
            // si no, filtrar cambiaría los números de las pestañas de al lado.
            if (!$fila['prueba']) {
                $conteo[$fila['source']] = ($conteo[$fila['source']] ?? 0) + 1;
            }
            if ($filtro !== '' && $fila['source'] !== $filtro) continue;
            $rows[] = $fila;
        }
        fclose($fh);
        $rows = array_reverse($rows);              // el más reciente arriba
    }
}

$reales = 0;
foreach ($rows as $r) { if (!$r['prueba']) $reales++; }
$pruebas = count($rows) - $reales;

/** api/lead.php antepone `'` a lo que empieza por = + - @ (anti fórmula en Excel). */
function unquote($s) {
    return (isset($s[0]) && $s[0] === "'") ? substr($s, 1) : $s;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fecha($iso) {
    $t = strtotime($iso);
    return $t ? date('d M Y · H:i', $t) : $iso;
}
function nombreFuente($s) { return FUENTES[$s] ?? $s; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leads Sumba Hills · AxisWorks</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<style>
/* Paleta de Sumba Hills: crema y tierra, la misma de `qr.html` y de la pagina
   del equipo. Es una herramienta interna del estudio, pero mirando datos de
   ESTE proyecto, y saltar de una a otra con dos estilos distintos cansa.
   Lo que la distingue no es el color: es el titulo y el sello de abajo. */
:root{
  --terra:#8B5E3C;      /* terracota/madera: el acento */
  --terra-d:#6B4429;    /* hover */
  --espresso:#3B2A22;   /* titulares */
  --arena:#F5F0E6;      /* fondo */
  --bg:var(--arena);--surface:#FFFFFF;--line:rgba(46,38,32,.16);
  --tx:#2E2620;--dim:rgba(46,38,32,.72);--faint:rgba(46,38,32,.5);
  --signal:var(--terra);
  --sans:'Jost',ui-sans-serif,system-ui,sans-serif;
  --disp:var(--sans);
  --mono:var(--sans);   /* Sumba Hills no usa monoespaciada; los numeros se
                           alinean con font-variant-numeric, no con la fuente */
  color-scheme:light;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font:15px/1.55 var(--sans);min-height:100vh;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--signal);outline-offset:2px}
main{max-width:1000px;margin:0 auto;padding:38px 22px 70px}
main.narrow{max-width:430px;padding-top:12vh}
.wm{font-weight:600;letter-spacing:.2em;font-size:11.5px;color:var(--terra);margin-bottom:26px}
.wm x{color:var(--signal);font-style:normal;padding:0 .05em}
h1{font-family:var(--disp);font-weight:500;font-size:clamp(26px,5vw,38px);color:var(--espresso);
  line-height:1.1}
.lede{color:var(--dim);margin-top:8px;max-width:64ch;font-size:14px}
.bar{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap}
.logout{font-size:12.5px;color:var(--faint);text-decoration:none;
  border:1px solid var(--line);border-radius:7px;padding:6px 11px}
.logout:hover{color:var(--tx);border-color:var(--signal)}

/* Fuentes: son el indice de la pagina, asi que van arriba y se pueden clicar */
.fuentes{display:flex;flex-wrap:wrap;gap:7px;margin:26px 0 6px}
.f{display:block;text-decoration:none;border:1px solid var(--line);border-radius:11px;
  padding:9px 13px;min-width:150px;background:var(--surface)}
.f:hover{border-color:var(--signal)}
.f.on{border-color:var(--signal)}
.f .n{font-family:var(--mono);font-size:20px;font-weight:600;font-variant-numeric:tabular-nums}
.f .l{display:block;font-size:12px;color:var(--dim);margin-top:1px}
.f .s{display:block;font-family:var(--mono);font-size:10px;color:var(--faint);letter-spacing:.04em}

.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;margin-top:22px;
  overflow:hidden}
.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%;min-width:720px}
th{font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.11em;text-transform:uppercase;
  color:var(--faint);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:13px 16px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:color-mix(in srgb,var(--signal) 6%,transparent)}
.when{font-family:var(--mono);font-size:11.5px;color:var(--faint);white-space:nowrap}
.name{font-weight:500}
.src{font-size:11.5px;color:var(--dim);border:1px solid var(--line);
  border-radius:6px;padding:2px 8px;white-space:nowrap}
td a{text-decoration:none;border-bottom:1px solid transparent}
td a:hover{border-bottom-color:var(--signal)}
tr.prueba{opacity:.5}
tr.prueba .name::after{content:"prueba";font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;
  text-transform:uppercase;color:var(--signal);margin-left:8px;vertical-align:1px}
.empty{padding:40px 20px;text-align:center;color:var(--dim);font-size:14px}
.aviso{border-left:2px solid var(--signal);padding:12px 15px;margin-top:22px;background:var(--surface);
  font-size:13.5px;color:var(--dim)}
.aviso b{color:var(--tx);font-weight:600}
code{font-family:var(--mono);font-size:.86em;color:var(--dim)}

/* Login */
form{display:flex;flex-direction:column;gap:13px;margin-top:24px}
label{font-family:var(--mono);font-size:10px;letter-spacing:.11em;text-transform:uppercase;
  color:var(--faint);display:block;margin-bottom:7px}
input{font:16px var(--sans);padding:14px 16px;border:1px solid var(--line);border-radius:9px;
  background:var(--bg);color:var(--tx);width:100%}
input:focus{border-color:var(--signal)}
button[type=submit]{background:var(--terra);color:var(--arena);border:0;border-radius:9px;
  padding:15px;font:600 14px var(--sans);letter-spacing:.06em;text-transform:uppercase;cursor:pointer}
button[type=submit]:hover{background:var(--terra-d)}
.err{font-size:12.5px;color:var(--signal);margin-top:7px}
footer{max-width:1000px;margin:34px auto 0;padding:18px 22px 40px;border-top:1px solid var(--line);
  font-size:12px;color:var(--faint)}
@media (max-width:560px){th,td{padding:11px 12px}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<main class="narrow">
  <p class="wm">AXIS<x>&#10005;</x>WORKS</p>
  <h1>Leads de Sumba Hills</h1>
  <p class="lede">Vista interna del estudio: <b>todas</b> las fuentes de captación.
     No es la página que usa el equipo del QR.</p>
  <?php if ($hash === ''): ?>
    <p class="err" style="margin:22px 0 0">Falta <code>private/estudio.hash</code> en el
      servidor, así que esta página no deja entrar a nadie. Créalo con el hash bcrypt de
      la contraseña — está en <code>private/sumbahills_accesos.env</code> del repo de la
      agencia.</p>
  <?php elseif (!hashValido($hash)): ?>
    <?php /* El diagnostico que faltaba: fichero corrupto y contrasena mal tecleada
              daban el MISMO "contrasena incorrecta", asi que no habia forma de saber
              cual de los dos era. Esta pagina es del estudio, aqui si se detalla. */ ?>
    <p class="err" style="margin:22px 0 0"><b>El fichero <code>private/estudio.hash</code>
      existe pero no contiene un hash bcrypt válido</b>, así que ninguna contraseña va a
      funcionar. Tiene <?= strlen($hash) ?> caracteres y debería tener 60, empezando por
      <code>$2y$</code>. Suele pasar por pegarlo partido en dos líneas, por comillas
      alrededor, o porque el editor guardó el archivo con codificación
      <code>UTF-8 con BOM</code>. Vuelve a pegarlo en una sola línea y guarda como
      <code>UTF-8 sin BOM</code>.</p>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <div>
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
      <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    </div>
    <button type="submit">Entrar</button>
  </form>
  <?php endif; /* hash presente */ ?>
</main>

<?php else: ?>
<main>
  <p class="wm">AXIS<x>&#10005;</x>WORKS</p>
  <div class="bar">
    <div>
      <h1>Leads de Sumba Hills</h1>
      <p class="lede">Todo lo que ha entrado por cualquier vía. La página que usa el equipo
        de calle es otra (<code>/leads</code>) y solo enseña el QR, porque su liquidación
        se calcula sobre esos.</p>
    </div>
    <a class="logout" href="?logout=1">Salir</a>
  </div>

  <?php if ($csvMissing): ?>
    <div class="aviso"><b>No se pudo leer <code>private/leads.csv</code> en el servidor.</b>
      Esto no es "cero leads": el fichero falta o no es legible. No te fíes de ningún número
      de esta página hasta que se resuelva.</div>
  <?php else: ?>

  <div class="fuentes">
    <?php
      $todas = array('' => array('Todas las fuentes', array_sum($conteo)));
      foreach ($conteo as $s => $n) { $todas[$s] = array(nombreFuente($s), $n); }
      foreach ($todas as $s => $par):
        $etiqueta = $par[0]; $n = $par[1];
        $on = ($filtro === $s) ? ' on' : '';
    ?>
      <a class="f<?= $on ?>" href="<?= $s === '' ? 'leads-estudio' : 'leads-estudio?source=' . urlencode($s) ?>">
        <span class="n"><?= $n ?></span>
        <span class="l"><?= e($etiqueta) ?></span>
        <?php if ($s !== ''): ?><span class="s"><?= e($s) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php if (!$rows): ?>
      <p class="empty">Todavía no hay leads<?= $filtro !== '' ? ' de esta fuente' : '' ?>.</p>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Cuándo</th><th>Nombre</th><th>WhatsApp</th><th>Email</th><th>Fuente</th><th>Interés</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $digits = preg_replace('/\D/', '', $r['whatsapp']); ?>
          <tr class="<?= $r['prueba'] ? 'prueba' : '' ?>">
            <td class="when"><?= e(fecha($r['ts'])) ?></td>
            <td class="name"><?= $r['name'] !== '' ? e($r['name']) : '—' ?></td>
            <td><?= $digits !== ''
                  ? '<a href="https://wa.me/' . e($digits) . '" target="_blank" rel="noopener">' . e($r['whatsapp']) . '</a>'
                  : '—' ?></td>
            <td><?= $r['email'] !== ''
                  ? '<a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a>'
                  : '—' ?></td>
            <td><span class="src"><?= e($r['source']) ?></span></td>
            <td><?= $r['property'] !== '' ? e($r['property']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($pruebas): ?>
    <div class="aviso"><b><?= $pruebas ?> fila(s) de prueba</b> en esta vista, atenuadas y
      fuera del recuento de arriba. Se dejan visibles a propósito: esconderlas haría creer
      que el CSV solo tiene leads reales.</div>
  <?php endif; ?>

  <div class="aviso">Datos personales de gente real (RGPD · UU PDP). Solo lectura: aquí no se
    borra ni se edita. La fuente de verdad es <code>private/leads.csv</code>, que escribe
    <code>api/lead.php</code>; la copia en Supabase es una réplica y puede ir por detrás.</div>

  <?php endif; /* csvMissing */ ?>
</main>

<footer>Herramienta interna de AxisWorks · Sumba Hills (proyecto de Lawang) · no indexada</footer>
<?php endif; /* auth */ ?>

</body>
</html>
