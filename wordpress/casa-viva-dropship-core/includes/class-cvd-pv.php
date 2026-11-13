<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026, D138–D140): una sola app con Taxi + Triciclo (Triciclub) + Moto + Mensajero y precio mixto
 * como inDrive. La puerta: interruptor, quién entra, páginas, manifest y service worker.
 *
 * Dónde vive (sin tocar a los mensajeros de Casa Viva):
 * - /palla/        → la app de los mensajeros de Casa Viva (CVD_Palla), igual que siempre (también la app Android 1.4).
 * - /palla/ir/     → el CLIENTE (esta clase). Su propio service worker con alcance /palla/ir/.
 * - /palla/chofer/ → el CHOFER (taxi, triciclo, moto, mensajero de envíos). Mismo enlace que Pa'llá (localStorage palla.k).
 * - /palla/triciclub → el alta del chofer (CVD_Triciclub), que ya existe.
 *
 * Motor dentro del Core (D129): las tablas de Triciclub ampliadas (wp_cvd_tc_*, versión 4). Un viaje NO es un pedido.
 * Interruptor `cvd_pv`: no (por defecto) | prueba | si. En prueba entran solo los clientes de `cvd_tc_clientes_prueba`
 * (teléfonos) y los choferes de `cvd_tc_testers` (user ids): las mismas listas de Triciclub.
 */
final class CVD_Pv {
	public const OPTION = 'cvd_pv';
	public const TTL = 7776000; // 90 días

	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
	}

	public static function mode(): string {
		$m = sanitize_key( (string) get_option( self::OPTION, 'no' ) );
		return in_array( $m, array( 'prueba', 'si' ), true ) ? $m : 'no';
	}

	public static function texto_cerrado( string $reason ): string {
		return array(
			'closed'     => 'Pa\'llá todavía no está abierto. Muy pronto.',
			'not_tester' => 'Pa\'llá está en prueba con pocas personas. Te avisamos cuando abra para todos.',
			'blocked'    => 'Tu cuenta está parada. Escríbenos por WhatsApp.',
			'no_chofer'  => 'Todavía no eres chofer de Pa\'llá. Termina tu alta.',
		)[ $reason ] ?? 'No puedes entrar ahora.';
	}

	/** '' = el cliente puede entrar. */
	public static function cliente_bloqueado( string $telefono ): string {
		$mode = self::mode();
		if ( 'no' === $mode ) { return 'closed'; }
		if ( 'prueba' === $mode && ! in_array( CVD_Triciclub::telefono( $telefono ), CVD_Triciclub::clientes_prueba(), true ) ) { return 'not_tester'; }
		return '';
	}

	/** Cliente del token X-TC-Token (el mismo token que Triciclub), o el error. */
	public static function request_cliente( WP_REST_Request $request ) {
		$c = CVD_Triciclub::verificar_token( trim( (string) $request->get_header( 'x_tc_token' ) ) );
		if ( ! $c ) { return new WP_Error( 'pv_token', 'Vuelve a entrar con tu teléfono.', array( 'status' => 401 ) ); }
		if ( (int) $c->bloqueado ) { return new WP_Error( 'pv_blocked', self::texto_cerrado( 'blocked' ), array( 'status' => 403 ) ); }
		$r = self::cliente_bloqueado( (string) $c->telefono );
		if ( '' !== $r ) { return new WP_Error( 'pv_' . $r, self::texto_cerrado( $r ), array( 'status' => 403 ) ); }
		return $c;
	}

	/** Chofer del enlace de Pa'llá (X-Palla-Token), o el error. No exige estar aprobado (eso lo mira cada ruta). */
	public static function request_chofer( WP_REST_Request $request ) {
		$uid = CVD_Palla::verify( trim( (string) $request->get_header( 'x_palla_token' ) ) );
		$u = $uid ? get_userdata( $uid ) : false;
		if ( ! $u instanceof WP_User ) { return new WP_Error( 'pv_token', 'Tu enlace ya no vale. Pide uno nuevo por WhatsApp.', array( 'status' => 401 ) ); }
		$mode = self::mode();
		if ( 'no' === $mode ) { return new WP_Error( 'pv_closed', self::texto_cerrado( 'closed' ), array( 'status' => 403 ) ); }
		if ( 'prueba' === $mode && ! in_array( $u->ID, CVD_Triciclub::testers(), true ) ) { return new WP_Error( 'pv_not_tester', self::texto_cerrado( 'not_tester' ), array( 'status' => 403 ) ); }
		return $u;
	}

	// ------------------------------------------------------------------ Páginas

	/** 'cliente' | 'chofer' | 'sw-cliente' | 'sw-chofer' | 'manifest' | '' */
	public static function path_kind( string $request_uri ): string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
		return array(
			'/palla/ir'                      => 'cliente',
			'/palla/ir/'                     => 'cliente',
			'/palla/ir/sw.js'                => 'sw-cliente',
			'/palla/ir/manifest.webmanifest' => 'manifest',
			'/palla/chofer'                  => 'chofer',
			'/palla/chofer/'                 => 'chofer',
			'/palla/chofer/sw.js'            => 'sw-chofer',
		)[ $path ] ?? '';
	}

	public static function route(): void {
		$kind = self::path_kind( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $kind ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá transporte' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'manifest' === $kind ) { self::manifest(); }
		if ( 'sw-cliente' === $kind ) { self::service_worker( '/palla/ir/', 'pvc-', array( 'comun.js', 'cliente.js' ) ); }
		if ( 'sw-chofer' === $kind ) { self::service_worker( '/palla/chofer/', 'pvd-', array( 'comun.js', 'chofer.js' ) ); }
		self::page( 'chofer' === $kind );
	}

	public static function asset( string $file ): string {
		return CVD_URL . 'assets/pv/' . $file . '?v=' . rawurlencode( CVD_VERSION );
	}

	public static function config( bool $chofer ): array {
		return array(
			'api'      => rest_url( 'casa-viva/v1/pv/' ),
			'base'     => CVD_URL . 'assets/pv/',
			'v'        => CVD_VERSION,
			'mode'     => self::mode(),
			'chofer'   => $chofer,
			'scope'    => home_url( $chofer ? '/palla/chofer/' : '/palla/ir/' ),
			'sw'       => home_url( $chofer ? '/palla/chofer/sw.js' : '/palla/ir/sw.js' ),
			'alta'     => home_url( '/palla/triciclub' ),
			'cliente'  => home_url( '/palla/ir/' ),
			'ntfy'     => CVD_Pv_Canal::url(),
			'srv'      => untrailingslashit( (string) get_option( 'cvd_tc_ruta_api', 'https://179-236-242-212.sslip.io' ) ), // 10-nov (auditoría): mapa y rutas, sin dirección fija en el móvil
			'telefono' => preg_replace( '/\D+/', '', (string) get_option( 'cvd_tc_telefono', CVD_Triciclub::TELEFONO ) ),
			'clases'   => CVD_Pv_Tarifa::publicas(),
			'tamanos'  => CVD_Pv_Tarifa::TAMANOS,
			'ayudante'   => CVD_Pv_Tarifa::ayudante(),    // v3 Carga: precio fijo del ayudante
			'comidaTope' => CVD_Pv_Tarifa::comida_tope(), // v3 Comida: lo más que adelanta el mensajero
			'pesos'      => CVD_Pv_Tarifa::PESOS,
		);
	}

	private static function page( bool $chofer ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		$titulo = $chofer ? 'Pa\'llá · Chofer' : 'Pa\'llá';
		// Tema: «auto» = oscuro de 19:00 a 7:00 o si el teléfono está en oscuro (decidido en el diseño v2).
		$tema = "(function(){var t;try{t=localStorage.getItem('pv.tema')}catch(e){}t=t||'auto';if(t==='auto'){var h=new Date().getHours(),d=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches;t=(d||h>=19||h<7)?'oscuro':'claro'}document.documentElement.dataset.tema=t;var m=document.querySelector('meta[name=theme-color]');if(m)m.content=t==='oscuro'?'#0F0F10':'#FFFFFF'})()";
		$logo = '<svg viewBox="0 0 96 96" aria-hidden="true"><rect width="96" height="96" rx="22" fill="#161616"/><path class="pc-p" fill="#FFC21A" fill-rule="evenodd" d="M25 20h27a20 20 0 0 1 0 40h-9v16H25zM43 34v12h7a6 6 0 0 0 0-12z"/><path class="pc-ruta" d="M43 76c10 0 24-2 34-12" fill="none" stroke="#FFC21A" stroke-width="5" stroke-linecap="round" opacity=".9"/><circle class="pc-punto" cx="78" cy="63" r="5" fill="#fff"/></svg>';
		if ( 'no' === self::mode() ) {
			$body = '<main class="pa-llena"><div class="pa-cuerpo" style="padding-top:96px;align-items:center;text-align:center"><div class="pa-carga" style="position:static">' . $logo . '<b>Pa\'llá</b></div><h1 class="p-titulo">Muy pronto</h1><p class="p-2">' . esc_html( self::texto_cerrado( 'closed' ) ) . '</p></div></main>';
		} else {
			$body = '<div id="marco"><div id="mapa" aria-hidden="true"></div><main id="pant" aria-live="polite"><div class="pa-carga" role="img" aria-label="Pa\'llá">' . $logo . '<b>Pa\'llá</b></div></main></div>'
				. '<noscript><p style="padding:24px">Pa\'llá necesita JavaScript.</p></noscript>'
				. '<script>window.PV=' . wp_json_encode( self::config( $chofer ) ) . ';</script>'
				. '<script src="' . esc_url( self::asset( 'mapa.js' ) ) . '" defer></script>'
				. '<script src="' . esc_url( self::asset( 'comun.js' ) ) . '" defer></script>'
				. '<script src="' . esc_url( self::asset( $chofer ? 'chofer.js' : 'cliente.js' ) ) . '" defer></script>';
		}
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#FFFFFF"><title>' . esc_html( $titulo ) . '</title>'
			. '<script>' . $tema . '</script>'
			. ( $chofer ? '' : '<link rel="manifest" href="' . esc_url( home_url( '/palla/ir/manifest.webmanifest' ) ) . '">' )
			. '<link rel="icon" href="' . esc_url( self::asset( 'logo/a-icono-oscuro.svg' ) ) . '" type="image/svg+xml">'
			. '<link rel="apple-touch-icon" href="' . esc_url( self::asset( 'icon-192.png' ) ) . '">'
			. '<link rel="preload" href="' . esc_url( CVD_URL . 'assets/pv/fonts/Geist-var-latin.woff2' ) . '" as="font" type="font/woff2" crossorigin>'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'tokens.css' ) ) . '">'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'app.css' ) ) . '">'
			. '</head><body>' . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* arriba.
		exit;
	}

	private static function manifest(): void {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		$scope = (string) wp_parse_url( home_url( '/palla/ir/' ), PHP_URL_PATH );
		echo wp_json_encode( array(
			'id'               => $scope,
			'name'             => 'Pa\'llá · taxi, triciclo y mensajero',
			'short_name'       => 'Pa\'llá',
			'description'      => 'Muévete por La Habana. Tú pones el precio.',
			'lang'             => 'es',
			'start_url'        => $scope . '?source=pwa',
			'scope'            => $scope,
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#161616',
			'theme_color'      => '#161616',
			'icons'            => array(
				array( 'src' => self::asset( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::asset( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ),
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/** Guarda la app (cáscara + archivos) para abrir sin señal; la API va siempre a la red (la app guarda lo suyo). */
	private static function service_worker( string $scope_path, string $prefijo, array $js ): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		$shell = wp_json_encode( home_url( $scope_path ) );
		$files = wp_json_encode( array_map( array( __CLASS__, 'asset' ), array_merge( $js, array( 'mapa.js', 'tokens.css', 'app.css', 'iconos.svg' ) ) ), JSON_UNESCAPED_SLASHES );
		$cache = $prefijo . CVD_VERSION;
		echo "const CACHE=" . wp_json_encode( $cache ) . ",PRE=" . wp_json_encode( $prefijo ) . ",SHELL={$shell},FILES={$files};\n";
		echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>Promise.race([Promise.all([SHELL].concat(FILES).map(u=>c.add(u).catch(()=>null))),new Promise(ok=>setTimeout(ok,10000))])).catch(()=>null))});\n";
		echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k.startsWith(PRE)&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});\n";
		echo "self.addEventListener('fetch',e=>{const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin)return;\n";
		echo "if(r.mode==='navigate'){e.respondWith(fetch(r).then(res=>{if(res.ok&&u.pathname===new URL(SHELL).pathname){const cp=res.clone();caches.open(CACHE).then(c=>c.put(SHELL,cp))}return res}).catch(()=>caches.match(SHELL)));return}\n";
		echo "if(u.pathname.indexOf('/assets/pv/')!==-1){e.respondWith(caches.match(r).then(x=>x||fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(r,cp))}return res})))}});\n";
		exit;
	}
}
