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
        if (is_array($row) && (int) ($row['approval_id'] ?? 0) === $approvalId && (int) ($row['task_id'] ?? 0) > 0 && ($row['actionable'] ?? false) === true && ($row['task_status'] ?? '') === 'waiting_approval') { return true; }
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
<body class="cv-shell">
<header class="topbar">
  <a class="brand" href="./" aria-label="Casa Viva Control OS"><span><img class="cv-logo" src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%204890%201000%22%20role%3D%22img%22%20aria-label%3D%22Casa%20Viva%22%3E%3Ctitle%3ECasa%20Viva%3C%2Ftitle%3E%3Cpath%20fill%3D%22%230D3B45%22%20d%3D%22M473.3%20995.1C211.9%20995.1%200%20772.4%200%20497.6C0%20222.8%20211.9%200%20473.3%200C582%200%20682.2%2038.6%20762.1%20103.4V196.7C700.9%20127.4%20618.5%2085%20527.9%2085C338.9%2085%20185.7%20269.7%20185.7%20497.6C185.7%20725.5%20338.9%20910.2%20527.9%20910.2C535.3%20910.2%20542.7%20909.9%20550%20909.3L575.3%20983.6C542.4%20991.1%20508.3%20995.1%20473.3%20995.1ZM322.8%20245.1H555.8V264.6Q542.2%20264.6%20532.6%20269.6L677.4%20694.3L863.9%20267.8Q855.6%20264.6%20844.7%20264.6V245.1H983V264.6Q951.5%20264.6%20941.7%20291.3H921.6L611.7%201000L370.1%20291.3H364.1Q354.4%20264.6%20322.8%20264.6ZM1960.6%20563.8H1846.7L1820.5%20628H1820.7Q1817%20637.2%201819.3%20643.7Q1821.7%20650.1%201827.2%20653.6Q1832.7%20657.1%201838.3%20657.1H1842.4V661.7H1745.4V657.1H1749.6Q1760.2%20657.1%201771.5%20650.4Q1781.5%20644.5%201788.4%20631.6L1895.1%20386.4Q1896%20384.5%201899%20377.4Q1902%20370.2%201906%20361Q1909.9%20351.7%201912.7%20343.2Q1915.4%20334.6%201915.9%20330H1920L2053.4%20630.5Q2060.5%20644.2%202070.9%20650.4Q2082.2%20657.1%202092.8%20657.1H2096.5V661.7H1963.9V657.1H1968.1Q1976.9%20657.1%201983.8%20649.4Q1990.4%20642.1%201986.1%20629.1ZM4754%20563.8H4640.2L4614%20628H4614.2Q4610.5%20637.2%204612.8%20643.7Q4615.1%20650.1%204620.6%20653.6Q4626.2%20657.1%204631.7%20657.1H4635.9V661.7H4538.9V657.1H4543Q4553.7%20657.1%204565%20650.4Q4574.9%20644.5%204581.8%20631.6L4688.6%20386.4Q4689.5%20384.5%204692.5%20377.4Q4695.5%20370.2%204699.4%20361Q4703.3%20351.7%204706.1%20343.2Q4708.9%20334.6%204709.3%20330H4713.5L4846.9%20630.5Q4853.9%20644.2%204864.3%20650.4Q4875.6%20657.1%204886.3%20657.1H4890V661.7H4757.4V657.1H4761.5Q4770.3%20657.1%204777.2%20649.4Q4783.9%20642.1%204779.5%20629.1ZM2819.4%20563.8H2705.5L2679.3%20628H2679.5Q2675.8%20637.2%202678.1%20643.7Q2680.4%20650.1%202686%20653.6Q2691.5%20657.1%202697.1%20657.1H2701.2V661.7H2604.2V657.1H2608.4Q2619%20657.1%202630.3%20650.4Q2640.2%20644.5%202647.2%20631.6L2753.9%20386.4Q2754.8%20384.5%202757.8%20377.4Q2760.8%20370.2%202764.7%20361Q2768.7%20351.7%202771.4%20343.2Q2774.2%20334.6%202774.7%20330H2778.8L2912.2%20630.5Q2919.3%20644.2%202929.7%20650.4Q2941%20657.1%202951.6%20657.1H2955.3V661.7H2822.7V657.1H2826.9Q2835.6%20657.1%202842.6%20649.4Q2849.2%20642.1%202844.8%20629.1ZM1592.5%20603.9Q1581.9%20622%201564.3%20636.5Q1546.8%20651.1%201521.6%20659.6Q1496.4%20668.2%201462.7%20668.2Q1409.1%20667.7%201368.5%20647.4Q1327.8%20627%201305.4%20589.4Q1283%20551.7%201283%20499.5Q1283%20448.3%201305.2%20410.6Q1327.4%20373%201368%20352.4Q1408.7%20331.8%201464.6%20331.8Q1500.6%20331.8%201529.2%20339.9Q1557.9%20348%201577.7%20359.1L1581.4%20433.5H1577.3Q1565.7%20389.6%201536.4%20369.5Q1507.1%20349.4%201467.8%20349.4Q1429.9%20349.4%201402.4%20367.6Q1374.9%20385.9%201359.9%20419.4Q1344.9%20452.9%201344.9%20498.6Q1344.9%20544.3%201359.5%20578.3Q1374%20612.3%201400.6%20631Q1427.1%20649.7%201462.7%20650.6Q1497.4%20650.6%201523%20638.8Q1548.6%20627%201565%20599.8Q1581.4%20572.5%201587.9%20526.8H1591.6ZM3437.9%20371.6%203522.3%20579.5%203611%20369.8Q3615.4%20357.2%203609%20350.3Q3602%20342.9%203592.8%20342.9H3588.6V338.3H3685.6V342.9H3681.5Q3671.3%20342.9%203659.8%20349.6Q3649.7%20355.5%203642.8%20368.1L3532.3%20613.6Q3531.4%20615.5%203528.1%20622.6Q3524.9%20629.8%203521.2%20639Q3517.5%20648.3%203514.7%20656.8Q3511.9%20665.4%203511.5%20670H3507.3L3371.7%20372L3371.5%20372Q3364.1%20356.3%203352.8%20349.6Q3341.5%20342.9%203330.9%20342.9H3327.2V338.3H3459.7V342.9H3455.6Q3446.8%20342.9%203440.1%20350.3Q3433.4%20357.7%203438.5%20371.6ZM4224.2%20371.6%204308.6%20579.5%204397.3%20369.8Q4401.7%20357.2%204395.2%20350.3Q4388.3%20342.9%204379%20342.9H4374.9V338.3H4471.9V342.9H4467.7Q4457.6%20342.9%204446%20349.6Q4436%20355.5%204429.1%20368.1L4318.5%20613.6Q4317.6%20615.5%204314.4%20622.6Q4311.1%20629.8%204307.4%20639Q4303.7%20648.3%204301%20656.8Q4298.2%20665.4%204297.7%20670H4293.6L4158%20372L4157.8%20372Q4150.4%20356.3%204139.1%20349.6Q4127.7%20342.9%204117.1%20342.9H4113.4V338.3H4246V342.9H4241.8Q4233.1%20342.9%204226.4%20350.3Q4219.7%20357.7%204224.7%20371.6ZM2369.1%20332.8Q2381.1%20333.7%202393.3%20335.3Q2397.7%20335.9%202401.7%20336.5H2429.1L2428.7%20346.2H2428.4L2425%20400.7H2420.3Q2420.3%20377.1%202403.5%20363.3Q2386.6%20349.4%202359.8%20349.4Q2333%20349.4%202317.1%20363.3Q2301.2%20377.1%202300.7%20397Q2299.8%20408.5%202307.4%20419.4Q2315%20430.2%202328%20439.5L2417.1%20505.5Q2436.1%20518%202444.8%20535.8Q2453.6%20553.6%202452.7%20575.8Q2451.3%20618.3%202422%20643.2Q2392.6%20668.2%202342.3%20668.2Q2325.2%20668.2%202307.4%20665.1Q2289.6%20662.1%202274.8%20655.9Q2260%20649.7%202251.3%20640.9Q2248.5%20631.7%202249%20618.5Q2249.4%20605.3%202252.7%20591.2Q2255.9%20577.1%202260.5%20567.4H2264.7Q2262.8%20591%202272.5%20610.2Q2282.2%20629.3%202300.9%20640.2Q2319.6%20651.1%202345%20650.6Q2373.7%20649.7%202391%20634.4Q2408.3%20619.2%202408.3%20595.2Q2408.3%20581.3%202400.9%20570Q2393.6%20558.7%202377.8%20549.4L2294.2%20486.1Q2273%20472.3%202264%20452.6Q2255%20433%202256.4%20411.8Q2257.7%20389.6%202268.8%20371.3Q2279.9%20353.1%202300%20342.5Q2320.1%20331.8%202348.3%20331.8Q2357.1%20331.8%202369.1%20332.8ZM3963.7%20338.3V342.9H3957.7Q3945.7%20342.9%203937.2%20351.5Q3928.6%20360%203928.2%20372V628Q3928.6%20640%203937.2%20648.5Q3945.7%20657.1%203957.7%20657.1H3963.7V661.7H3836.2V657.1H3842.2Q3854.3%20657.1%203862.8%20648.5Q3871.4%20640%203871.8%20628V372Q3871.4%20360%203862.8%20351.5Q3854.3%20342.9%203842.2%20342.9H3836.2V338.3ZM710.5%20846.6C765.2%20804.9%20809.5%20745%20837.4%20674V815.5C790.8%20874.4%20731.5%20921.8%20663.9%20953.1ZM2763.8%20421%202712.7%20546.2H2812.5ZM1905%20421%201853.9%20546.2H1953.8ZM4698.5%20421%204647.4%20546.2H4747.2Z%22%2F%3E%3C%2Fsvg%3E" alt="" width="132" height="27"><small>Control OS</small></span></a>
  <div class="fresh"><span id="connection" class="dot"></span><span id="freshness">Cargando estado…</span></div><button id="refresh" class="icon-btn" aria-label="Actualizar">↻</button>
</header><aside class="cv-sidebar"><a class="cv-wordmark" href="./" aria-label="Casa Viva Control OS"><img class="cv-logo" src="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%204890%201000%22%20role%3D%22img%22%20aria-label%3D%22Casa%20Viva%22%3E%3Ctitle%3ECasa%20Viva%3C%2Ftitle%3E%3Cpath%20fill%3D%22%230D3B45%22%20d%3D%22M473.3%20995.1C211.9%20995.1%200%20772.4%200%20497.6C0%20222.8%20211.9%200%20473.3%200C582%200%20682.2%2038.6%20762.1%20103.4V196.7C700.9%20127.4%20618.5%2085%20527.9%2085C338.9%2085%20185.7%20269.7%20185.7%20497.6C185.7%20725.5%20338.9%20910.2%20527.9%20910.2C535.3%20910.2%20542.7%20909.9%20550%20909.3L575.3%20983.6C542.4%20991.1%20508.3%20995.1%20473.3%20995.1ZM322.8%20245.1H555.8V264.6Q542.2%20264.6%20532.6%20269.6L677.4%20694.3L863.9%20267.8Q855.6%20264.6%20844.7%20264.6V245.1H983V264.6Q951.5%20264.6%20941.7%20291.3H921.6L611.7%201000L370.1%20291.3H364.1Q354.4%20264.6%20322.8%20264.6ZM1960.6%20563.8H1846.7L1820.5%20628H1820.7Q1817%20637.2%201819.3%20643.7Q1821.7%20650.1%201827.2%20653.6Q1832.7%20657.1%201838.3%20657.1H1842.4V661.7H1745.4V657.1H1749.6Q1760.2%20657.1%201771.5%20650.4Q1781.5%20644.5%201788.4%20631.6L1895.1%20386.4Q1896%20384.5%201899%20377.4Q1902%20370.2%201906%20361Q1909.9%20351.7%201912.7%20343.2Q1915.4%20334.6%201915.9%20330H1920L2053.4%20630.5Q2060.5%20644.2%202070.9%20650.4Q2082.2%20657.1%202092.8%20657.1H2096.5V661.7H1963.9V657.1H1968.1Q1976.9%20657.1%201983.8%20649.4Q1990.4%20642.1%201986.1%20629.1ZM4754%20563.8H4640.2L4614%20628H4614.2Q4610.5%20637.2%204612.8%20643.7Q4615.1%20650.1%204620.6%20653.6Q4626.2%20657.1%204631.7%20657.1H4635.9V661.7H4538.9V657.1H4543Q4553.7%20657.1%204565%20650.4Q4574.9%20644.5%204581.8%20631.6L4688.6%20386.4Q4689.5%20384.5%204692.5%20377.4Q4695.5%20370.2%204699.4%20361Q4703.3%20351.7%204706.1%20343.2Q4708.9%20334.6%204709.3%20330H4713.5L4846.9%20630.5Q4853.9%20644.2%204864.3%20650.4Q4875.6%20657.1%204886.3%20657.1H4890V661.7H4757.4V657.1H4761.5Q4770.3%20657.1%204777.2%20649.4Q4783.9%20642.1%204779.5%20629.1ZM2819.4%20563.8H2705.5L2679.3%20628H2679.5Q2675.8%20637.2%202678.1%20643.7Q2680.4%20650.1%202686%20653.6Q2691.5%20657.1%202697.1%20657.1H2701.2V661.7H2604.2V657.1H2608.4Q2619%20657.1%202630.3%20650.4Q2640.2%20644.5%202647.2%20631.6L2753.9%20386.4Q2754.8%20384.5%202757.8%20377.4Q2760.8%20370.2%202764.7%20361Q2768.7%20351.7%202771.4%20343.2Q2774.2%20334.6%202774.7%20330H2778.8L2912.2%20630.5Q2919.3%20644.2%202929.7%20650.4Q2941%20657.1%202951.6%20657.1H2955.3V661.7H2822.7V657.1H2826.9Q2835.6%20657.1%202842.6%20649.4Q2849.2%20642.1%202844.8%20629.1ZM1592.5%20603.9Q1581.9%20622%201564.3%20636.5Q1546.8%20651.1%201521.6%20659.6Q1496.4%20668.2%201462.7%20668.2Q1409.1%20667.7%201368.5%20647.4Q1327.8%20627%201305.4%20589.4Q1283%20551.7%201283%20499.5Q1283%20448.3%201305.2%20410.6Q1327.4%20373%201368%20352.4Q1408.7%20331.8%201464.6%20331.8Q1500.6%20331.8%201529.2%20339.9Q1557.9%20348%201577.7%20359.1L1581.4%20433.5H1577.3Q1565.7%20389.6%201536.4%20369.5Q1507.1%20349.4%201467.8%20349.4Q1429.9%20349.4%201402.4%20367.6Q1374.9%20385.9%201359.9%20419.4Q1344.9%20452.9%201344.9%20498.6Q1344.9%20544.3%201359.5%20578.3Q1374%20612.3%201400.6%20631Q1427.1%20649.7%201462.7%20650.6Q1497.4%20650.6%201523%20638.8Q1548.6%20627%201565%20599.8Q1581.4%20572.5%201587.9%20526.8H1591.6ZM3437.9%20371.6%203522.3%20579.5%203611%20369.8Q3615.4%20357.2%203609%20350.3Q3602%20342.9%203592.8%20342.9H3588.6V338.3H3685.6V342.9H3681.5Q3671.3%20342.9%203659.8%20349.6Q3649.7%20355.5%203642.8%20368.1L3532.3%20613.6Q3531.4%20615.5%203528.1%20622.6Q3524.9%20629.8%203521.2%20639Q3517.5%20648.3%203514.7%20656.8Q3511.9%20665.4%203511.5%20670H3507.3L3371.7%20372L3371.5%20372Q3364.1%20356.3%203352.8%20349.6Q3341.5%20342.9%203330.9%20342.9H3327.2V338.3H3459.7V342.9H3455.6Q3446.8%20342.9%203440.1%20350.3Q3433.4%20357.7%203438.5%20371.6ZM4224.2%20371.6%204308.6%20579.5%204397.3%20369.8Q4401.7%20357.2%204395.2%20350.3Q4388.3%20342.9%204379%20342.9H4374.9V338.3H4471.9V342.9H4467.7Q4457.6%20342.9%204446%20349.6Q4436%20355.5%204429.1%20368.1L4318.5%20613.6Q4317.6%20615.5%204314.4%20622.6Q4311.1%20629.8%204307.4%20639Q4303.7%20648.3%204301%20656.8Q4298.2%20665.4%204297.7%20670H4293.6L4158%20372L4157.8%20372Q4150.4%20356.3%204139.1%20349.6Q4127.7%20342.9%204117.1%20342.9H4113.4V338.3H4246V342.9H4241.8Q4233.1%20342.9%204226.4%20350.3Q4219.7%20357.7%204224.7%20371.6ZM2369.1%20332.8Q2381.1%20333.7%202393.3%20335.3Q2397.7%20335.9%202401.7%20336.5H2429.1L2428.7%20346.2H2428.4L2425%20400.7H2420.3Q2420.3%20377.1%202403.5%20363.3Q2386.6%20349.4%202359.8%20349.4Q2333%20349.4%202317.1%20363.3Q2301.2%20377.1%202300.7%20397Q2299.8%20408.5%202307.4%20419.4Q2315%20430.2%202328%20439.5L2417.1%20505.5Q2436.1%20518%202444.8%20535.8Q2453.6%20553.6%202452.7%20575.8Q2451.3%20618.3%202422%20643.2Q2392.6%20668.2%202342.3%20668.2Q2325.2%20668.2%202307.4%20665.1Q2289.6%20662.1%202274.8%20655.9Q2260%20649.7%202251.3%20640.9Q2248.5%20631.7%202249%20618.5Q2249.4%20605.3%202252.7%20591.2Q2255.9%20577.1%202260.5%20567.4H2264.7Q2262.8%20591%202272.5%20610.2Q2282.2%20629.3%202300.9%20640.2Q2319.6%20651.1%202345%20650.6Q2373.7%20649.7%202391%20634.4Q2408.3%20619.2%202408.3%20595.2Q2408.3%20581.3%202400.9%20570Q2393.6%20558.7%202377.8%20549.4L2294.2%20486.1Q2273%20472.3%202264%20452.6Q2255%20433%202256.4%20411.8Q2257.7%20389.6%202268.8%20371.3Q2279.9%20353.1%202300%20342.5Q2320.1%20331.8%202348.3%20331.8Q2357.1%20331.8%202369.1%20332.8ZM3963.7%20338.3V342.9H3957.7Q3945.7%20342.9%203937.2%20351.5Q3928.6%20360%203928.2%20372V628Q3928.6%20640%203937.2%20648.5Q3945.7%20657.1%203957.7%20657.1H3963.7V661.7H3836.2V657.1H3842.2Q3854.3%20657.1%203862.8%20648.5Q3871.4%20640%203871.8%20628V372Q3871.4%20360%203862.8%20351.5Q3854.3%20342.9%203842.2%20342.9H3836.2V338.3ZM710.5%20846.6C765.2%20804.9%20809.5%20745%20837.4%20674V815.5C790.8%20874.4%20731.5%20921.8%20663.9%20953.1ZM2763.8%20421%202712.7%20546.2H2812.5ZM1905%20421%201853.9%20546.2H1953.8ZM4698.5%20421%204647.4%20546.2H4747.2Z%22%2F%3E%3C%2Fsvg%3E" alt="" width="172" height="35"><small>Control OS</small></a><button class="cv-assistant-toggle" type="button" aria-expanded="false" aria-controls="assistant-panel"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg> Pregúntale al asistente</button><nav class="tabs" aria-label="Secciones"><div class="nav-group" data-nav-group="0"><h2>Inicio</h2><button type="button" class="selected" data-tab="home" aria-pressed="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 3l9 7v10H3z M9 20v-7h6v7"/></svg><span>Inicio ejecutivo</span></button><button type="button" data-tab="today" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg><span>Aprobaciones</span> <span id="decision-count">—</span></button><button type="button" data-tab="work" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Mis solicitudes</span></button></div><div class="nav-group" data-nav-group="1"><h2>Negocio</h2><button type="button" data-tab="orders" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l2-5h14l2 5v3H3z M5 12v8h14v-8M10 20v-5h4v5"/></svg><span>Comercio · Pedidos</span></button><button type="button" data-tab="conversations" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 0 1-8 8H4l2-4a8 8 0 1 1 14-4z"/></svg><span>CRM y WhatsApp</span></button></div><div class="nav-group" data-nav-group="2"><h2>Operaciones</h2><button type="button" data-tab="incidents" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 21h20z M12 9v5m0 3v.5"/></svg><span>Incidencias</span></button></div><div class="nav-group" data-nav-group="3"><h2>Inteligencia</h2><button type="button" data-tab="ai" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg><span>Agentes IA</span></button><button type="button" data-tab="developers" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6 2 12l6 6m8-12 6 6-6 6m-2-15-4 18"/></svg><span>Evolution Engine</span></button></div><div class="nav-group" data-nav-group="4"><h2>Sistema</h2><button type="button" data-tab="system" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h16M8 5v4m8 1v4m-6 1v4"/></svg><span>Sistema</span></button></div></nav><div class="cv-account"><?php echo esc_html(wp_get_current_user()->display_name); ?><small><?php echo $canWrite ? 'Administración' : 'Consulta'; ?></small></div></aside>
<main>
  <p class="section-copy"><a href="<?php echo esc_url(home_url('/centro-operaciones/')); ?>">Pedidos e inventario</a> · <?php echo esc_html(wp_get_current_user()->display_name); ?> · <?php echo $canWrite ? 'Control OS' : 'Acceso de consulta. Cambios y aprobaciones por WhatsApp.'; ?></p>
  <section class="command-card" id="assistant-panel" hidden>
    <div class="command-head">
      <div><span class="eyebrow">ASISTENTE</span><h1><?php echo $canWrite ? '¿Qué quieres hacer?' : 'Estado de Control OS'; ?></h1></div>
      
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

  <section id="home" class="view"><h2>Inicio ejecutivo</h2><p class="section-copy">Consulta el estado recibido y abre el área que necesita atención.</p><div id="home-list" class="stack"></div><p id="coverage" class="section-copy">Esperando fuentes y alcance de la consulta.</p></section>
  <section id="work" class="view" hidden>
    <div class="section-head"><h2 id="list-title">Empezamos desde ahora</h2><span id="task-count" class="count">—</span></div>
    <p id="list-help" class="section-copy">Tus solicitudes nuevas aparecerán aquí, con la más reciente primero. Lo anterior sigue disponible en el historial.</p>
    <label class="sr-only" for="task-search">Buscar solicitud por número o texto</label>
    <input id="task-search" class="task-search" type="search" placeholder="Buscar por número o texto">
    <div class="filters">
      <button class="filter selected" data-filter="new">Desde ahora</button>
      <button class="filter" data-filter="pending">Todas las pendientes</button>
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
  <section id="developers" class="view" hidden><h2>Programadores</h2><p id="developer-status" class="section-copy">Esperando estado del servicio.</p><p id="local-status" class="section-copy">Esperando último aviso local.</p><p class="section-copy">Tareas identificadas por estado o ejecutor registrado. No certifica ejecución en vivo.</p><div id="developer-list" class="stack"></div></section>
  <section id="ai" class="view" hidden><h2>Inteligencia artificial</h2><p id="ai-status" class="section-copy">Esperando estado.</p><div id="ai-list" class="stack"></div></section>
  <section id="system" class="view" hidden>
    <div class="section-head"><h2>Estado de los servicios</h2><span id="system-badge" class="health-badge">Comprobando</span></div>
    <div id="system-grid" class="system-grid"></div>
    <p class="section-copy">Estos indicadores muestran la conexión de los servicios; no certifican el ciclo automático de programación.</p>
    <details><summary>Información técnica y actividad</summary><div id="activity-list" class="stack compact"></div></details>
  </section>
</main><button class="cv-assistant-fab cv-assistant-toggle" type="button" aria-label="Abrir asistente" aria-expanded="false" aria-controls="assistant-panel"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg></button><nav class="cv-bottom" aria-label="Navegación móvil"><button type="button" data-group="0" aria-pressed="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 3l9 7v10H3z M9 20v-7h6v7"/></svg><span>Inicio</span></button><button type="button" data-group="1" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v13H3z M8 7V4h8v3M3 12h18"/></svg><span>Negocio</span></button><button type="button" data-group="2" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5h13v12H2z M15 9h4l3 5v3h-7M5 19a2 2 0 1 0 4 0m8 0a2 2 0 1 0 4 0"/></svg><span>Operaciones</span></button><button type="button" data-group="3" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg><span>IA</span></button><button type="button" data-group="4" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h.1m7 0h.1m7 0h.1"/></svg><span>Más</span></button></nav>
<footer>Casa Viva Control OS · Una sola cola, una sola verdad operativa.</footer>
<script src="app.js?v=<?php echo esc_attr(substr(hash_file('sha256', __DIR__ . '/app.js'), 0, 12)); ?>"></script>
</body>
</html>
