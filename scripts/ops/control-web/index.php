<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/wp-load.php';
nocache_headers();
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$action = (string) ($_GET['action'] ?? '');
if (!is_user_logged_in()) {
    if ($action !== '') { wp_send_json_error(['message' => 'Necesitas iniciar sesión.'], 401); }
    auth_redirect(); exit;
}
if ((int) get_current_user_id() !== 1 || !current_user_can('manage_options')) {
    if ($action !== '') { wp_send_json_error(['message' => 'Este centro es privado.'], 403); }
    status_header(403); exit('Este centro es privado.');
}
$store = dirname(__DIR__, 2) . '/control-private';

if ($action === 'state') {
    $path = $store . '/snapshot.json';
    if (!is_file($path)) { wp_send_json_error(['message' => 'Esperando la primera actualización.'], 503); }
    $state = json_decode((string) file_get_contents($path), true);
    if (!is_array($state)) { wp_send_json_error(['message' => 'El estado no es válido.'], 503); }
    wp_send_json_success($state);
}
if ($action === 'task') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_send_json_error(['message' => 'Método no permitido.'], 405); }
    if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_CV_NONCE'] ?? ''), 'cv-control')) {
        wp_send_json_error(['message' => 'La sesión venció. Recarga la página.'], 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, 12001);
    if ($raw === false || strlen($raw) > 12000) { wp_send_json_error(['message' => 'La instrucción es demasiado larga.'], 400); }
    $input = json_decode($raw, true);
    $text = is_array($input) && is_string($input['text'] ?? null) ? trim($input['text']) : '';
    if (strlen($text) < 8 || strlen($text) > 6000 || strpos($text, "\0") !== false) {
        wp_send_json_error(['message' => 'Escribe entre 8 y 6000 caracteres.'], 400);
    }
    if (!is_dir($store)) { wp_send_json_error(['message' => 'El servidor todavía no está preparado.'], 503); }
    $request = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'actor_wp_id' => 1, 'kind' => 'task', 'text' => $text];
    $file = fopen($store . '/requests.jsonl', 'ab');
    if ($file === false || !flock($file, LOCK_EX)) { wp_send_json_error(['message' => 'No se pudo guardar. Inténtalo de nuevo.'], 503); }
    $line = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    $ok = fwrite($file, $line) === strlen($line);
    fflush($file); flock($file, LOCK_UN); fclose($file);
    @chmod($store . '/requests.jsonl', 0600);
    if (!$ok) { wp_send_json_error(['message' => 'No se pudo guardar la instrucción.'], 503); }
    wp_send_json_success(['id' => $request['id'], 'message' => 'Guardado en Control OS.']);
}
if ($action === 'decision') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_send_json_error(['message' => 'Método no permitido.'], 405); }
    if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_CV_NONCE'] ?? ''), 'cv-control')) {
        wp_send_json_error(['message' => 'La sesión venció. Recarga la página.'], 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, 2001);
    if ($raw === false || strlen($raw) > 2000) { wp_send_json_error(['message' => 'Solicitud inválida.'], 400); }
    $input = json_decode($raw, true);
    $approvalId = is_array($input) ? (int) ($input['approval_id'] ?? 0) : 0;
    $decision = is_array($input) && is_string($input['decision'] ?? null) ? $input['decision'] : '';
    if ($approvalId <= 0 || !in_array($decision, ['approve','reject'], true)) {
        wp_send_json_error(['message' => 'Decisión inválida.'], 400);
    }
    if (!is_dir($store)) { wp_send_json_error(['message' => 'El servidor todavía no está preparado.'], 503); }
    $request = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'actor_wp_id' => 1,
                'kind' => 'decision', 'approval_id' => $approvalId, 'decision' => $decision];
    $file = fopen($store . '/decisions.jsonl', 'ab');
    if ($file === false || !flock($file, LOCK_EX)) { wp_send_json_error(['message' => 'No se pudo guardar la decisión.'], 503); }
    $line = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    $ok = fwrite($file, $line) === strlen($line);
    fflush($file); flock($file, LOCK_UN); fclose($file);
    @chmod($store . '/decisions.jsonl', 0600);
    if (!$ok) { wp_send_json_error(['message' => 'No se pudo guardar la decisión.'], 503); }
    wp_send_json_success(['id' => $request['id'], 'message' => 'Decisión guardada.']);
}
if ($action !== '') { wp_send_json_error(['message' => 'Acción desconocida.'], 404); }
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="cv-nonce" content="<?php echo esc_attr(wp_create_nonce('cv-control')); ?>">
<title>Control OS · Casa Viva</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="./" aria-label="Casa Viva Control OS"><span class="brand-mark">CV</span><span><strong>Casa Viva</strong><small>Control OS</small></span></a>
  <button id="refresh" class="icon-btn" aria-label="Actualizar">↻</button>
</header>
<main>
  <section class="command-card">
    <div class="command-head">
      <div><span class="eyebrow">CENTRO ÚNICO</span><h1>¿Qué necesita atención?</h1></div>
      <div class="fresh"><span id="connection" class="dot"></span><span id="freshness">Conectando…</span></div>
    </div>
    <form id="task-form">
      <label class="sr-only" for="task-text">Orden para Casa Viva</label>
      <textarea id="task-text" minlength="8" maxlength="6000" rows="3" required placeholder="Ej.: revisa por qué el pedido 6452 sigue pendiente"></textarea>
      <div class="command-actions"><span>Escribe como hablas.</span><button type="submit" class="primary">Enviar</button></div>
      <p id="task-feedback" class="feedback" role="status"></p>
    </form>
  </section>
  <div id="error" class="notice" role="alert" hidden></div>
  <nav class="tabs" aria-label="Secciones">
    <button class="selected" data-tab="today">Hoy</button>
    <button data-tab="work">Trabajo</button>
    <button data-tab="lennys">Lennys</button>
    <button data-tab="system">Sistema</button>
  </nav>
  <section id="today" class="view">
    <div class="metrics">
      <article><strong id="m-attention">—</strong><span>Atención</span></article>
      <article><strong id="m-decisions">—</strong><span>Decisiones</span></article>
      <article><strong id="m-lennys">—</strong><span>Lennys</span></article>
      <article><strong id="m-errors">—</strong><span>Errores</span></article>
    </div>
    <div class="section-head"><div><span class="eyebrow">PRIMERO</span><h2>Decisiones tuyas</h2></div><span id="decision-count" class="count">0</span></div>
    <div id="decisions-list" class="stack"></div>
    <div class="section-head"><div><span class="eyebrow">PRIORIDAD</span><h2>Lo que merece atención</h2></div></div>
    <div id="today-list" class="stack"></div>
  </section>
  <section id="work" class="view" hidden>
    <div class="section-head"><div><span class="eyebrow">TRABAJO</span><h2>Tareas abiertas</h2></div><span id="task-count" class="count">0</span></div>
    <div class="filters">
      <button class="filter selected" data-filter="all">Todas</button>
      <button class="filter" data-filter="p0p1">Urgentes</button>
      <button class="filter" data-filter="dev">Programación</button>
      <button class="filter" data-filter="approval">Aprobación</button>
    </div>
    <div id="tasks-list" class="stack"></div>
  </section>
  <section id="lennys" class="view" hidden>
    <div class="section-head"><div><span class="eyebrow">OPERACIÓN</span><h2>Asuntos de Lennys</h2></div><span id="lennys-count" class="count">0</span></div>
    <p class="section-copy">Un asunto puede contener varios mensajes, audios o fotos. Aquí no se cuentan fragmentos como tareas separadas.</p>
    <div id="lennys-list" class="stack"></div>
  </section>
  <section id="system" class="view" hidden>
    <div class="section-head"><div><span class="eyebrow">SALUD</span><h2>Sistema</h2></div><span id="system-badge" class="health-badge">Comprobando</span></div>
    <div id="system-grid" class="system-grid"></div>
    <div class="section-head activity-head"><div><span class="eyebrow">HISTORIAL</span><h2>Actividad reciente</h2></div></div>
    <div id="activity-list" class="stack compact"></div>
  </section>
</main>
<footer>Casa Viva Control OS · Una sola cola, una sola verdad operativa.</footer>
<script src="app.js"></script>
</body>
</html>