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
    status_header(401);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · Casa Viva Control</title><link rel="stylesheet" href="style.css"></head><body><main><section class="command-card"><h1>Entrar a Control OS</h1><p>Usa tu cuenta de Casa Viva.</p><p><a class="primary" href="<?php echo esc_url(wp_login_url(home_url('/control/'))); ?>">1. Iniciar sesión</a></p><p>Si después ves Operaciones o tu área de gestora, vuelve a esta página.</p><p><a class="primary" href="<?php echo esc_url(home_url('/control/')); ?>">2. Abrir Control OS</a></p><p><a class="primary" href="<?php echo esc_url(wp_lostpassword_url()); ?>">He olvidado mi contraseña</a></p></section></main></body></html><?php
    exit;
}
// Control access: two verified owner accounts, with WordPress capabilities checked each time.
// Lennys (14 / Lengonga) can consult; requests and decisions remain Ernesto-only.
$actorId = (int) get_current_user_id();
$canRead = in_array($actorId, [1, 14], true) && current_user_can('manage_options');
$canWrite = $actorId === 1 && current_user_can('manage_options');
if (!$canRead) {
    if ($action !== '') { wp_send_json_error(['message' => 'Este centro es privado.'], 403); }
    status_header(403); exit('Este centro es privado.');
}
if (in_array($action, ['task', 'decision'], true) && !$canWrite) {
    wp_send_json_error(['message' => 'Tu acceso web es de consulta. Solicita cambios y aprueba por WhatsApp.'], 403);
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
    $request = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'actor_wp_id' => $actorId, 'kind' => 'task', 'text' => $text];
    $file = fopen($store . '/requests.jsonl', 'ab');
    if ($file === false || !flock($file, LOCK_EX)) { wp_send_json_error(['message' => 'No se pudo guardar. Inténtalo de nuevo.'], 503); }
    $line = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    $ok = fwrite($file, $line) === strlen($line);
    fflush($file); flock($file, LOCK_UN); fclose($file);
    @chmod($store . '/requests.jsonl', 0600);
    if (!$ok) { wp_send_json_error(['message' => 'No se pudo guardar la instrucción.'], 503); }
    wp_send_json_success(['id' => $request['id'], 'message' => 'Guardado en Control OS.']);
}
function cv_control_approval_linked($snapshot, int $approvalId, int $now): bool {
    if (!is_array($snapshot) || ($snapshot['approval_complete'] ?? false) !== true || $approvalId <= 0) { return false; }
    $snapshotTime = strtotime((string) ($snapshot['at'] ?? ''));
    if ($snapshotTime === false || abs($now - $snapshotTime) > 180 || !is_array($snapshot['decisions'] ?? null)) { return false; }
    foreach ($snapshot['decisions'] as $row) {
        if (is_array($row) && (int) ($row['approval_id'] ?? 0) === $approvalId && (int) ($row['task_id'] ?? 0) > 0) { return true; }
    }
    return false;
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
    $snapshot = json_decode((string) @file_get_contents($store . '/snapshot.json'), true);
    if (!cv_control_approval_linked($snapshot, $approvalId, time())) { wp_send_json_error(['message' => 'No hay una aprobación vigente y vinculada en el estado verificado. Actualiza.'], 409); }
    $request = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'actor_wp_id' => $actorId,
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
<meta name="cv-access" content="<?php echo $canWrite ? 'write' : 'read'; ?>">
<meta name="cv-nonce" content="<?php echo esc_attr(wp_create_nonce('cv-control')); ?>">
<title>Control OS · Casa Viva</title>
<link rel="stylesheet" href="style.css?v=<?php echo esc_attr(substr(hash_file('sha256', __DIR__ . '/style.css'), 0, 12)); ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="./" aria-label="Casa Viva Control OS"><span class="brand-mark">CV</span><span><strong>Casa Viva</strong><small>Control OS</small></span></a>
  <button id="refresh" class="icon-btn" aria-label="Actualizar">↻</button>
</header>
<main>
  <p class="section-copy"><a href="<?php echo esc_url(home_url('/centro-operaciones/')); ?>">Pedidos e inventario</a> · <?php echo esc_html(wp_get_current_user()->display_name); ?> · <?php echo $canWrite ? 'Control OS' : 'Acceso de consulta. Cambios y aprobaciones por WhatsApp.'; ?></p>
  <section class="command-card">
    <div class="command-head">
      <div><span class="eyebrow">CASA VIVA</span><h1><?php echo $canWrite ? '¿Qué quieres hacer?' : 'Estado de Control OS'; ?></h1></div>
      <div class="fresh"><span id="connection" class="dot"></span><span id="freshness">Cargando estado…</span></div>
    </div>
    <div class="quick-actions" aria-label="Consultas sin IA" <?php if (!$canWrite) { echo 'hidden'; } ?>>
      <button type="button" data-query="salud del sistema">Salud del sistema</button>
      <button type="button" data-query="estado de wordpress">WordPress</button>
      <button type="button" data-query="estado del bot">Código del bot</button>
      <button type="button" data-query="revisar errores del bot">Errores del bot</button>
    </div>
    <form id="task-form" <?php if (!$canWrite) { echo 'hidden'; } ?>>
      <label class="sr-only" for="task-text">Tu solicitud</label>
      <textarea id="task-text" minlength="8" maxlength="6000" rows="2" required placeholder="Escribe aquí lo que necesitas"></textarea>
      <div class="command-actions"><span>Las consultas de estado no gastan IA.</span><button type="submit" class="primary">Enviar solicitud</button></div>
      <p id="task-feedback" class="feedback" role="status" aria-live="polite"></p>
    </form>
    <p class="capability-note" <?php if (!$canWrite) { echo 'hidden'; } ?>>Los demás mensajes quedan para revisión. La programación automática de cambios todavía está en preparación.</p>
  </section>
  <div id="error" class="notice" role="alert" hidden></div>
  <nav class="tabs" aria-label="Secciones">
    <button class="selected" data-tab="work" aria-pressed="true">Mis solicitudes</button>
    <button data-tab="today" aria-pressed="false">Aprobaciones <span id="decision-count">—</span></button>
    <button data-tab="incidents" aria-pressed="false">Incidencias</button>
    <button data-tab="orders" aria-pressed="false">Pedidos</button>
    <button data-tab="conversations" aria-pressed="false">Conversaciones</button>
    <button data-tab="developers" aria-pressed="false">Programadores</button>
    <button data-tab="ai" aria-pressed="false">IA</button>
    <button data-tab="system" aria-pressed="false">Sistema</button>
  </nav>
  <section id="work" class="view">
    <div class="section-head"><h2 id="list-title">Empezamos desde ahora</h2><span id="task-count" class="count">—</span></div>
    <p id="list-help" class="section-copy">Tus solicitudes nuevas aparecerán aquí, con la más reciente primero. Lo anterior sigue disponible en el historial.</p>
    <label class="sr-only" for="task-search">Buscar solicitud por número o texto</label>
    <input id="task-search" class="task-search" type="search" placeholder="Buscar por número o texto">
    <div class="filters">
      <button class="filter selected" data-filter="new">Desde ahora</button>
      <button class="filter" data-filter="pending">Pendientes nuevas</button>
      <button class="filter" data-filter="done">Terminadas nuevas</button>
      <button class="filter" data-filter="history">Historial anterior</button>
    </div>
    <div id="tasks-list" class="stack"></div>
    <details id="legacy-details" hidden><summary>Mensajes anteriores de Lennys</summary>
      <p class="section-copy">Conversaciones conservadas como referencia. Un saludo o fragmento no equivale a una solicitud ejecutable.</p>
      <div id="lennys-list" class="stack"></div>
    </details>
  </section>
  <section id="today" class="view" hidden>
    <div class="section-head"><h2>Necesita tu aprobación</h2></div>
    <p class="section-copy">Aquí solo aparecen decisiones reales pendientes, incluso si son anteriores a la limpieza. Aprobar no significa desplegar un cambio de código.</p>
    <p id="approval-integrity" class="section-copy">Comprobando integridad…</p><div id="decisions-list" class="stack"></div>
    <h3>Solicitudes sin aprobación visible</h3><div id="approval-review-list" class="stack"></div>
  </section>
  <section id="incidents" class="view" hidden><h2>Incidencias</h2><p class="section-copy">Muestra del registro de errores; requiere confirmar cada caso.</p><div id="incidents-list" class="stack"></div></section>
  <section id="orders" class="view" hidden><h2>Pedidos</h2><p class="section-copy">Consulta el registro existente de pedidos e inventario. El snapshot de Control OS no contiene un listado de pedidos.</p><a class="primary" href="<?php echo esc_url(home_url('/centro-operaciones/')); ?>">Abrir pedidos reales</a></section>
  <section id="conversations" class="view" hidden><h2>Conversaciones</h2><p class="section-copy">Asuntos de Lennys registrados en Control OS, no el chat en directo. Un mensaje breve no confirma una incidencia.</p><div id="conversation-list" class="stack"></div></section>
  <section id="developers" class="view" hidden><h2>Programadores</h2><p class="section-copy">Tareas identificadas por estado o ejecutor registrado. No certifica ejecución en vivo.</p><div id="developer-list" class="stack"></div></section>
  <section id="ai" class="view" hidden><h2>Inteligencia artificial</h2><p id="ai-status" class="section-copy">Esperando estado.</p></section>
  <section id="system" class="view" hidden>
    <div class="section-head"><h2>Estado de los servicios</h2><span id="system-badge" class="health-badge">Comprobando</span></div>
    <div id="system-grid" class="system-grid"></div>
    <p class="section-copy">Estos indicadores muestran la conexión de los servicios; no certifican el ciclo automático de programación.</p>
    <details><summary>Información técnica y actividad</summary><div id="activity-list" class="stack compact"></div></details>
  </section>
</main>
<footer>Casa Viva Control OS · Una sola cola, una sola verdad operativa.</footer>
<script src="app.js?v=<?php echo esc_attr(substr(hash_file('sha256', __DIR__ . '/app.js'), 0, 12)); ?>"></script>
</body>
</html>