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
    $localPath = $store . '/infrastructure.json';
    if (is_file($localPath)) {
        $local = json_decode((string) file_get_contents($localPath, false, null, 0, 4096), true);
        if (is_array($local) && is_string($local['at'] ?? null)) {
            $state['infrastructure'] = ['at' => $local['at'], 'ok' => ($local['ok'] ?? false) === true];
            foreach (['vivabot', 'receiver', 'bridge'] as $service) {
                $status = $local[$service]['status'] ?? '';
                if (in_array($status, ['online', 'stopped', 'active', 'inactive', 'failed'], true)) {
                    $state['infrastructure'][$service] = ['status' => $status];
                }
            }
        }
    }
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
  <a class="brand" href="./" aria-label="Casa Viva Control OS"><span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4890 1000" class="cv-logo" aria-hidden="true" focusable="false"><path fill="currentColor" d="M473.3 995.1C211.9 995.1 0 772.4 0 497.6C0 222.8 211.9 0 473.3 0C582 0 682.2 38.6 762.1 103.4V196.7C700.9 127.4 618.5 85 527.9 85C338.9 85 185.7 269.7 185.7 497.6C185.7 725.5 338.9 910.2 527.9 910.2C535.3 910.2 542.7 909.9 550 909.3L575.3 983.6C542.4 991.1 508.3 995.1 473.3 995.1ZM322.8 245.1H555.8V264.6Q542.2 264.6 532.6 269.6L677.4 694.3L863.9 267.8Q855.6 264.6 844.7 264.6V245.1H983V264.6Q951.5 264.6 941.7 291.3H921.6L611.7 1000L370.1 291.3H364.1Q354.4 264.6 322.8 264.6ZM1960.6 563.8H1846.7L1820.5 628H1820.7Q1817 637.2 1819.3 643.7Q1821.7 650.1 1827.2 653.6Q1832.7 657.1 1838.3 657.1H1842.4V661.7H1745.4V657.1H1749.6Q1760.2 657.1 1771.5 650.4Q1781.5 644.5 1788.4 631.6L1895.1 386.4Q1896 384.5 1899 377.4Q1902 370.2 1906 361Q1909.9 351.7 1912.7 343.2Q1915.4 334.6 1915.9 330H1920L2053.4 630.5Q2060.5 644.2 2070.9 650.4Q2082.2 657.1 2092.8 657.1H2096.5V661.7H1963.9V657.1H1968.1Q1976.9 657.1 1983.8 649.4Q1990.4 642.1 1986.1 629.1ZM4754 563.8H4640.2L4614 628H4614.2Q4610.5 637.2 4612.8 643.7Q4615.1 650.1 4620.6 653.6Q4626.2 657.1 4631.7 657.1H4635.9V661.7H4538.9V657.1H4543Q4553.7 657.1 4565 650.4Q4574.9 644.5 4581.8 631.6L4688.6 386.4Q4689.5 384.5 4692.5 377.4Q4695.5 370.2 4699.4 361Q4703.3 351.7 4706.1 343.2Q4708.9 334.6 4709.3 330H4713.5L4846.9 630.5Q4853.9 644.2 4864.3 650.4Q4875.6 657.1 4886.3 657.1H4890V661.7H4757.4V657.1H4761.5Q4770.3 657.1 4777.2 649.4Q4783.9 642.1 4779.5 629.1ZM2819.4 563.8H2705.5L2679.3 628H2679.5Q2675.8 637.2 2678.1 643.7Q2680.4 650.1 2686 653.6Q2691.5 657.1 2697.1 657.1H2701.2V661.7H2604.2V657.1H2608.4Q2619 657.1 2630.3 650.4Q2640.2 644.5 2647.2 631.6L2753.9 386.4Q2754.8 384.5 2757.8 377.4Q2760.8 370.2 2764.7 361Q2768.7 351.7 2771.4 343.2Q2774.2 334.6 2774.7 330H2778.8L2912.2 630.5Q2919.3 644.2 2929.7 650.4Q2941 657.1 2951.6 657.1H2955.3V661.7H2822.7V657.1H2826.9Q2835.6 657.1 2842.6 649.4Q2849.2 642.1 2844.8 629.1ZM1592.5 603.9Q1581.9 622 1564.3 636.5Q1546.8 651.1 1521.6 659.6Q1496.4 668.2 1462.7 668.2Q1409.1 667.7 1368.5 647.4Q1327.8 627 1305.4 589.4Q1283 551.7 1283 499.5Q1283 448.3 1305.2 410.6Q1327.4 373 1368 352.4Q1408.7 331.8 1464.6 331.8Q1500.6 331.8 1529.2 339.9Q1557.9 348 1577.7 359.1L1581.4 433.5H1577.3Q1565.7 389.6 1536.4 369.5Q1507.1 349.4 1467.8 349.4Q1429.9 349.4 1402.4 367.6Q1374.9 385.9 1359.9 419.4Q1344.9 452.9 1344.9 498.6Q1344.9 544.3 1359.5 578.3Q1374 612.3 1400.6 631Q1427.1 649.7 1462.7 650.6Q1497.4 650.6 1523 638.8Q1548.6 627 1565 599.8Q1581.4 572.5 1587.9 526.8H1591.6ZM3437.9 371.6 3522.3 579.5 3611 369.8Q3615.4 357.2 3609 350.3Q3602 342.9 3592.8 342.9H3588.6V338.3H3685.6V342.9H3681.5Q3671.3 342.9 3659.8 349.6Q3649.7 355.5 3642.8 368.1L3532.3 613.6Q3531.4 615.5 3528.1 622.6Q3524.9 629.8 3521.2 639Q3517.5 648.3 3514.7 656.8Q3511.9 665.4 3511.5 670H3507.3L3371.7 372L3371.5 372Q3364.1 356.3 3352.8 349.6Q3341.5 342.9 3330.9 342.9H3327.2V338.3H3459.7V342.9H3455.6Q3446.8 342.9 3440.1 350.3Q3433.4 357.7 3438.5 371.6ZM4224.2 371.6 4308.6 579.5 4397.3 369.8Q4401.7 357.2 4395.2 350.3Q4388.3 342.9 4379 342.9H4374.9V338.3H4471.9V342.9H4467.7Q4457.6 342.9 4446 349.6Q4436 355.5 4429.1 368.1L4318.5 613.6Q4317.6 615.5 4314.4 622.6Q4311.1 629.8 4307.4 639Q4303.7 648.3 4301 656.8Q4298.2 665.4 4297.7 670H4293.6L4158 372L4157.8 372Q4150.4 356.3 4139.1 349.6Q4127.7 342.9 4117.1 342.9H4113.4V338.3H4246V342.9H4241.8Q4233.1 342.9 4226.4 350.3Q4219.7 357.7 4224.7 371.6ZM2369.1 332.8Q2381.1 333.7 2393.3 335.3Q2397.7 335.9 2401.7 336.5H2429.1L2428.7 346.2H2428.4L2425 400.7H2420.3Q2420.3 377.1 2403.5 363.3Q2386.6 349.4 2359.8 349.4Q2333 349.4 2317.1 363.3Q2301.2 377.1 2300.7 397Q2299.8 408.5 2307.4 419.4Q2315 430.2 2328 439.5L2417.1 505.5Q2436.1 518 2444.8 535.8Q2453.6 553.6 2452.7 575.8Q2451.3 618.3 2422 643.2Q2392.6 668.2 2342.3 668.2Q2325.2 668.2 2307.4 665.1Q2289.6 662.1 2274.8 655.9Q2260 649.7 2251.3 640.9Q2248.5 631.7 2249 618.5Q2249.4 605.3 2252.7 591.2Q2255.9 577.1 2260.5 567.4H2264.7Q2262.8 591 2272.5 610.2Q2282.2 629.3 2300.9 640.2Q2319.6 651.1 2345 650.6Q2373.7 649.7 2391 634.4Q2408.3 619.2 2408.3 595.2Q2408.3 581.3 2400.9 570Q2393.6 558.7 2377.8 549.4L2294.2 486.1Q2273 472.3 2264 452.6Q2255 433 2256.4 411.8Q2257.7 389.6 2268.8 371.3Q2279.9 353.1 2300 342.5Q2320.1 331.8 2348.3 331.8Q2357.1 331.8 2369.1 332.8ZM3963.7 338.3V342.9H3957.7Q3945.7 342.9 3937.2 351.5Q3928.6 360 3928.2 372V628Q3928.6 640 3937.2 648.5Q3945.7 657.1 3957.7 657.1H3963.7V661.7H3836.2V657.1H3842.2Q3854.3 657.1 3862.8 648.5Q3871.4 640 3871.8 628V372Q3871.4 360 3862.8 351.5Q3854.3 342.9 3842.2 342.9H3836.2V338.3ZM710.5 846.6C765.2 804.9 809.5 745 837.4 674V815.5C790.8 874.4 731.5 921.8 663.9 953.1ZM2763.8 421 2712.7 546.2H2812.5ZM1905 421 1853.9 546.2H1953.8ZM4698.5 421 4647.4 546.2H4747.2Z"/></svg><small>Control OS</small></span></a>
  <div class="fresh"><span id="connection" class="dot"></span><span id="freshness">Cargando estado…</span></div><button id="refresh" class="icon-btn" aria-label="Actualizar">↻</button>
</header><aside class="cv-sidebar"><a class="cv-wordmark" href="./" aria-label="Casa Viva Control OS"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4890 1000" class="cv-logo" aria-hidden="true" focusable="false"><path fill="currentColor" d="M473.3 995.1C211.9 995.1 0 772.4 0 497.6C0 222.8 211.9 0 473.3 0C582 0 682.2 38.6 762.1 103.4V196.7C700.9 127.4 618.5 85 527.9 85C338.9 85 185.7 269.7 185.7 497.6C185.7 725.5 338.9 910.2 527.9 910.2C535.3 910.2 542.7 909.9 550 909.3L575.3 983.6C542.4 991.1 508.3 995.1 473.3 995.1ZM322.8 245.1H555.8V264.6Q542.2 264.6 532.6 269.6L677.4 694.3L863.9 267.8Q855.6 264.6 844.7 264.6V245.1H983V264.6Q951.5 264.6 941.7 291.3H921.6L611.7 1000L370.1 291.3H364.1Q354.4 264.6 322.8 264.6ZM1960.6 563.8H1846.7L1820.5 628H1820.7Q1817 637.2 1819.3 643.7Q1821.7 650.1 1827.2 653.6Q1832.7 657.1 1838.3 657.1H1842.4V661.7H1745.4V657.1H1749.6Q1760.2 657.1 1771.5 650.4Q1781.5 644.5 1788.4 631.6L1895.1 386.4Q1896 384.5 1899 377.4Q1902 370.2 1906 361Q1909.9 351.7 1912.7 343.2Q1915.4 334.6 1915.9 330H1920L2053.4 630.5Q2060.5 644.2 2070.9 650.4Q2082.2 657.1 2092.8 657.1H2096.5V661.7H1963.9V657.1H1968.1Q1976.9 657.1 1983.8 649.4Q1990.4 642.1 1986.1 629.1ZM4754 563.8H4640.2L4614 628H4614.2Q4610.5 637.2 4612.8 643.7Q4615.1 650.1 4620.6 653.6Q4626.2 657.1 4631.7 657.1H4635.9V661.7H4538.9V657.1H4543Q4553.7 657.1 4565 650.4Q4574.9 644.5 4581.8 631.6L4688.6 386.4Q4689.5 384.5 4692.5 377.4Q4695.5 370.2 4699.4 361Q4703.3 351.7 4706.1 343.2Q4708.9 334.6 4709.3 330H4713.5L4846.9 630.5Q4853.9 644.2 4864.3 650.4Q4875.6 657.1 4886.3 657.1H4890V661.7H4757.4V657.1H4761.5Q4770.3 657.1 4777.2 649.4Q4783.9 642.1 4779.5 629.1ZM2819.4 563.8H2705.5L2679.3 628H2679.5Q2675.8 637.2 2678.1 643.7Q2680.4 650.1 2686 653.6Q2691.5 657.1 2697.1 657.1H2701.2V661.7H2604.2V657.1H2608.4Q2619 657.1 2630.3 650.4Q2640.2 644.5 2647.2 631.6L2753.9 386.4Q2754.8 384.5 2757.8 377.4Q2760.8 370.2 2764.7 361Q2768.7 351.7 2771.4 343.2Q2774.2 334.6 2774.7 330H2778.8L2912.2 630.5Q2919.3 644.2 2929.7 650.4Q2941 657.1 2951.6 657.1H2955.3V661.7H2822.7V657.1H2826.9Q2835.6 657.1 2842.6 649.4Q2849.2 642.1 2844.8 629.1ZM1592.5 603.9Q1581.9 622 1564.3 636.5Q1546.8 651.1 1521.6 659.6Q1496.4 668.2 1462.7 668.2Q1409.1 667.7 1368.5 647.4Q1327.8 627 1305.4 589.4Q1283 551.7 1283 499.5Q1283 448.3 1305.2 410.6Q1327.4 373 1368 352.4Q1408.7 331.8 1464.6 331.8Q1500.6 331.8 1529.2 339.9Q1557.9 348 1577.7 359.1L1581.4 433.5H1577.3Q1565.7 389.6 1536.4 369.5Q1507.1 349.4 1467.8 349.4Q1429.9 349.4 1402.4 367.6Q1374.9 385.9 1359.9 419.4Q1344.9 452.9 1344.9 498.6Q1344.9 544.3 1359.5 578.3Q1374 612.3 1400.6 631Q1427.1 649.7 1462.7 650.6Q1497.4 650.6 1523 638.8Q1548.6 627 1565 599.8Q1581.4 572.5 1587.9 526.8H1591.6ZM3437.9 371.6 3522.3 579.5 3611 369.8Q3615.4 357.2 3609 350.3Q3602 342.9 3592.8 342.9H3588.6V338.3H3685.6V342.9H3681.5Q3671.3 342.9 3659.8 349.6Q3649.7 355.5 3642.8 368.1L3532.3 613.6Q3531.4 615.5 3528.1 622.6Q3524.9 629.8 3521.2 639Q3517.5 648.3 3514.7 656.8Q3511.9 665.4 3511.5 670H3507.3L3371.7 372L3371.5 372Q3364.1 356.3 3352.8 349.6Q3341.5 342.9 3330.9 342.9H3327.2V338.3H3459.7V342.9H3455.6Q3446.8 342.9 3440.1 350.3Q3433.4 357.7 3438.5 371.6ZM4224.2 371.6 4308.6 579.5 4397.3 369.8Q4401.7 357.2 4395.2 350.3Q4388.3 342.9 4379 342.9H4374.9V338.3H4471.9V342.9H4467.7Q4457.6 342.9 4446 349.6Q4436 355.5 4429.1 368.1L4318.5 613.6Q4317.6 615.5 4314.4 622.6Q4311.1 629.8 4307.4 639Q4303.7 648.3 4301 656.8Q4298.2 665.4 4297.7 670H4293.6L4158 372L4157.8 372Q4150.4 356.3 4139.1 349.6Q4127.7 342.9 4117.1 342.9H4113.4V338.3H4246V342.9H4241.8Q4233.1 342.9 4226.4 350.3Q4219.7 357.7 4224.7 371.6ZM2369.1 332.8Q2381.1 333.7 2393.3 335.3Q2397.7 335.9 2401.7 336.5H2429.1L2428.7 346.2H2428.4L2425 400.7H2420.3Q2420.3 377.1 2403.5 363.3Q2386.6 349.4 2359.8 349.4Q2333 349.4 2317.1 363.3Q2301.2 377.1 2300.7 397Q2299.8 408.5 2307.4 419.4Q2315 430.2 2328 439.5L2417.1 505.5Q2436.1 518 2444.8 535.8Q2453.6 553.6 2452.7 575.8Q2451.3 618.3 2422 643.2Q2392.6 668.2 2342.3 668.2Q2325.2 668.2 2307.4 665.1Q2289.6 662.1 2274.8 655.9Q2260 649.7 2251.3 640.9Q2248.5 631.7 2249 618.5Q2249.4 605.3 2252.7 591.2Q2255.9 577.1 2260.5 567.4H2264.7Q2262.8 591 2272.5 610.2Q2282.2 629.3 2300.9 640.2Q2319.6 651.1 2345 650.6Q2373.7 649.7 2391 634.4Q2408.3 619.2 2408.3 595.2Q2408.3 581.3 2400.9 570Q2393.6 558.7 2377.8 549.4L2294.2 486.1Q2273 472.3 2264 452.6Q2255 433 2256.4 411.8Q2257.7 389.6 2268.8 371.3Q2279.9 353.1 2300 342.5Q2320.1 331.8 2348.3 331.8Q2357.1 331.8 2369.1 332.8ZM3963.7 338.3V342.9H3957.7Q3945.7 342.9 3937.2 351.5Q3928.6 360 3928.2 372V628Q3928.6 640 3937.2 648.5Q3945.7 657.1 3957.7 657.1H3963.7V661.7H3836.2V657.1H3842.2Q3854.3 657.1 3862.8 648.5Q3871.4 640 3871.8 628V372Q3871.4 360 3862.8 351.5Q3854.3 342.9 3842.2 342.9H3836.2V338.3ZM710.5 846.6C765.2 804.9 809.5 745 837.4 674V815.5C790.8 874.4 731.5 921.8 663.9 953.1ZM2763.8 421 2712.7 546.2H2812.5ZM1905 421 1853.9 546.2H1953.8ZM4698.5 421 4647.4 546.2H4747.2Z"/></svg><small>Control OS</small></a><button class="cv-assistant-toggle" type="button" aria-expanded="false" aria-controls="assistant-panel"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg> Pregúntale al asistente</button><nav class="tabs" aria-label="Secciones"><div class="nav-group" data-nav-group="0"><h2>Inicio</h2><button type="button" class="selected" data-tab="home" aria-pressed="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 3l9 7v10H3z M9 20v-7h6v7"/></svg><span>Inicio ejecutivo</span></button><button type="button" data-tab="today" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg><span>Aprobaciones</span> <span id="decision-count">—</span></button><button type="button" data-tab="work" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Mis solicitudes</span></button></div><div class="nav-group" data-nav-group="1"><h2>Negocio</h2><button type="button" data-tab="orders" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l2-5h14l2 5v3H3z M5 12v8h14v-8M10 20v-5h4v5"/></svg><span>Comercio · Pedidos</span></button><button type="button" data-tab="conversations" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8 8 0 0 1-8 8H4l2-4a8 8 0 1 1 14-4z"/></svg><span>CRM y WhatsApp</span></button></div><div class="nav-group" data-nav-group="2"><h2>Operaciones</h2><button type="button" data-tab="incidents" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 21h20z M12 9v5m0 3v.5"/></svg><span>Incidencias</span></button></div><div class="nav-group" data-nav-group="3"><h2>Inteligencia</h2><button type="button" data-tab="ai" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg><span>Agentes IA</span></button><button type="button" data-tab="developers" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6 2 12l6 6m8-12 6 6-6 6m-2-15-4 18"/></svg><span>Evolution Engine</span></button></div><div class="nav-group" data-nav-group="4"><h2>Sistema</h2><button type="button" data-tab="system" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h16M8 5v4m8 1v4m-6 1v4"/></svg><span>Sistema</span></button></div></nav><div class="cv-account"><?php echo esc_html(wp_get_current_user()->display_name); ?><small><?php echo $canWrite ? 'Administración' : 'Consulta'; ?></small></div></aside>
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
    <div class="section-head"><h2>Infraestructura · VPS</h2><span id="system-badge" class="health-badge">Comprobando</span></div>
    <p><a class="primary" href="https://hpanel.hostinger.com/" target="_blank" rel="noopener noreferrer">Abrir Hostinger</a></p>
    <p id="infra-freshness" class="section-copy" aria-live="polite"></p>
    <div id="system-grid" class="system-grid"></div>
    <p class="section-copy">Estos indicadores muestran la conexión de los servicios; no certifican el ciclo automático de programación.</p>
    <details><summary>Información técnica y actividad</summary><div id="activity-list" class="stack compact"></div></details>
  </section>
</main><button class="cv-assistant-fab cv-assistant-toggle" type="button" aria-label="Abrir asistente" aria-expanded="false" aria-controls="assistant-panel"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg></button><nav class="cv-bottom" aria-label="Navegación móvil"><button type="button" data-group="0" aria-pressed="true"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 3l9 7v10H3z M9 20v-7h6v7"/></svg><span>Inicio</span></button><button type="button" data-group="1" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v13H3z M8 7V4h8v3M3 12h18"/></svg><span>Negocio</span></button><button type="button" data-group="2" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5h13v12H2z M15 9h4l3 5v3h-7M5 19a2 2 0 1 0 4 0m8 0a2 2 0 1 0 4 0"/></svg><span>Operaciones</span></button><button type="button" data-group="3" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/></svg><span>IA</span></button><button type="button" data-group="4" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h.1m7 0h.1m7 0h.1"/></svg><span>Más</span></button></nav>
<footer>Casa Viva Control OS · Una sola cola, una sola verdad operativa.</footer>
<script src="app.js?v=<?php echo esc_attr(substr(hash_file('sha256', __DIR__ . '/app.js'), 0, 12)); ?>"></script>
</body>
</html>
