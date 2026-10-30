<?php

defined( 'ABSPATH' ) || exit;

/**
 * Área de gestoras como app: menú lateral plegable (cajón en el móvil), Inicio con lo ganado
 * y lo más vendido, y Herramientas. Reutiliza las secciones del portal clásico (clientes,
 * precios, ventas, mensajería, pagos, invitar, perfil); solo cambia cómo se presentan.
 *
 * Activación: opción `cvd_gestora_app` = 'all' para todas; por defecto solo cuentas de prueba
 * (`_cvd_test_account` = 1), para revisarla antes de abrirla a las gestoras reales.
 */
final class CVD_Gestora_App {
	private const OPTION = 'cvd_gestora_app';
	private const TOP_CACHE = 'cvd_gestora_top_products';

	public static function register(): void {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'woocommerce_order_status_changed', static function (): void { delete_transient( self::TOP_CACHE ); } );
	}

	public static function enabled_for( WP_User $user ): bool {
		if ( ! $user->exists() || 'gestora' !== CVD_Registration::program_type( $user ) || ! CVD_Registration::is_approved_gestora( $user ) ) { return false; }
		return 'all' === get_option( self::OPTION, 'test' ) || '1' === (string) get_user_meta( $user->ID, '_cvd_test_account', true );
	}

	private static function active(): bool {
		return is_page( 'area-gestoras' ) && is_user_logged_in() && self::enabled_for( wp_get_current_user() );
	}

	public static function template( string $template ): string {
		if ( ! self::active() ) { return $template; }
		nocache_headers();
		return CVD_DIR . 'templates/gestora-app.php';
	}

	public static function title( array $parts ): array {
		if ( self::active() ) { $parts['title'] = 'Mi área'; }
		return $parts;
	}

	public static function body_class( array $classes ): array {
		if ( self::active() ) { $classes[] = 'cvga-body'; }
		return $classes;
	}

	public static function assets(): void {
		if ( ! self::active() ) { return; }
		wp_enqueue_style( 'cvd-gestora-app', CVD_URL . 'assets/gestora-app.css', array( 'cvd-portal' ), CVD_VERSION );
		wp_enqueue_script( 'cvd-gestora-app', CVD_URL . 'assets/gestora-app.js', array( 'cvd-portal' ), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-gestora-confianza', CVD_URL . 'assets/gestora-confianza.js', array( 'cvd-gestora-app' ), CVD_VERSION, true ); // 3.14.42
	}

	/** Todo el cuerpo de la app: menú + vistas nuevas + secciones del portal clásico. */
	public static function render(): string {
		$user = wp_get_current_user();
		// Primero el portal clásico: procesa los formularios (precios, pagos) antes de calcular las cifras.
		$legacy = do_shortcode( '[casa_viva_portal role="gestora"]' );
		// 3.13.68: sus pedidos, una sola vez para las cifras de Inicio y el recuadro «Tus comisiones» (Inicio y Ventas).
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user->ID ) );
		$box = class_exists( 'CVD_Gestora_Financial_View' ) ? CVD_Gestora_Financial_View::commission_box( $orders, $user->ID ) : '';
		$code = self::referral_code( $user );
		$link = add_query_arg( 'ref', $code, wc_get_page_permalink( 'shop' ) );
		$first = strtok( (string) $user->display_name, ' ' ) ?: $user->display_name;
		$zone = (string) get_user_meta( $user->ID, '_cvd_zone', true );
		$wa_shop = 'https://wa.me/5354056173?text=' . rawurlencode( 'Hola, soy ' . $user->display_name . ', gestor/a de Casa Viva (código ' . $code . ').' );
		$logout = wp_logout_url( home_url( '/casa-viva-app/' ) );

		$nav = array(
			array( 'inicio', 'Inicio', 'home', '' ),
			array( '', 'Vender', '', '' ),
			array( 'tienda', 'Mi tienda', 'bag', '' ),
			// 3.13.75 (Rafael Codina): volver a la tienda para ver los productos, con su código.
			array( 'ver-tienda', 'Ver la tienda', 'shop', $link ),
			array( 'precios', 'Mis precios', 'tag', '' ),
			array( 'clientes', 'Clientes', 'people', '' ),
			// 3.14.65 (D107): «Preguntas a la tienda» (Ficha que contesta), solo con cvd_ficha_contesta = si.
			...( class_exists( 'CVD_Ficha_Contesta' ) && 'si' === CVD_Ficha_Contesta::mode() ? array( array( 'preguntas', 'Preguntas a la tienda', 'chat', '' ) ) : array() ),
			array( '', 'Herramientas', '', '' ),
			// 3.14.28 (Ernesto, 16-oct): «NEXO Busca» (antes «Buscar precios»), la primera de Herramientas.
			array( 'buscar', 'NEXO Busca', 'search', '' ),
			array( 'calculadora', 'Calculadora', 'calc', '' ),
			// 3.14.42 (Ernesto, 20-oct): mirar si el número del cliente está en la lista de sospechosos de estafa.
			array( 'confianza', '¿Es de confianza?', 'shield', '' ),
			array( 'curru', 'Pregúntale a Curru', 'chat', '' ),
			array( 'fotos', 'Fotos para publicar', 'image', '' ),
			array( 'herramientas', 'Más herramientas', 'spark', '' ),
			array( '', 'Mi dinero', '', '' ),
			array( 'ventas', 'Ventas', 'chart', '' ),
			array( 'cobrar', 'Cobrar', 'card', '' ),
			array( '', 'Mi equipo', '', '' ),
			array( 'invitar', 'Invitar gestores', 'invite', '' ),
		);
		// 3.14.1 (Ernesto, 14-oct): «Mi estudio de fotos» (con la clave de Google de cada gestora) y «Mi contabilidad».
		$estudio = class_exists( 'CVD_Gestora_Estudio' ) && CVD_Gestora_Estudio::enabled_for( $user );
		if ( $estudio ) {
			array_splice( $nav, (int) array_search( 'fotos', array_column( $nav, 0 ), true ) + 1, 0, array( array( 'estudio', 'Mi estudio de fotos', 'camera', '' ) ) );
			array_splice( $nav, (int) array_search( 'cobrar', array_column( $nav, 0 ), true ) + 1, 0, array( array( 'contabilidad', 'Mi contabilidad', 'doc', '' ) ) );
		}
		// 3.14.91 (Ernesto, 29-oct): «Taller de fotos» (foto lista en el móvil y texto para publicar), debajo de «Fotos para publicar».
		$taller = class_exists( 'CVD_Gestora_Taller' ) && CVD_Gestora_Taller::enabled_for( $user );
		if ( $taller ) {
			array_splice( $nav, (int) array_search( 'fotos', array_column( $nav, 0 ), true ) + 1, 0, array( array( 'taller', 'Taller de fotos', 'camera', '' ) ) );
		}
		// 3.14.29: Programa de gestoras (D53–D58): «Mi equipo» y «Mi piedra», en sombra solo para quien lo prueba.
		$programa = class_exists( 'CVD_Programa' ) && CVD_Programa::visible_for( $user );
		if ( $programa ) {
			$at = (int) array_search( 'invitar', array_column( $nav, 0 ), true );
			array_splice( $nav, $at, 1, array( array( 'equipo', 'Mi equipo', 'people', '' ), array( 'piedra', 'Mi piedra', 'gem', '' ), array( 'invitar', 'Invitar gestores', 'invite', '' ) ) );
		}
		// 3.14.48 (D92): «Mis ventas de proveedor», solo para la cuenta de gestor del dueño (opción cvd_owner_gestor_user).
		$proveedor = class_exists( 'CVD_Supplier_Split' ) && CVD_Supplier_Split::owner_gestor_user() === (int) $user->ID;
		if ( $proveedor ) {
			array_splice( $nav, (int) array_search( 'cobrar', array_column( $nav, 0 ), true ) + 1, 0, array( array( 'proveedor', 'Mis ventas de proveedor', 'truck', '' ) ) );
		}
		// 3.14.87 (Ernesto 29-oct): «Mis artículos» (la tienda propia del vendedor) y «Artículos de vendedores» (lo que ofrecen a las gestoras).
		$vendedor = class_exists( 'CVD_Seller_Store' ) && CVD_Seller_Store::is_seller( $user );
		$articulos = class_exists( 'CVD_Seller_Store' ) && CVD_Seller_Store::has_offered();
		if ( $vendedor || $articulos ) {
			$add = array();
			if ( $vendedor ) { $add[] = array( 'vendedor', 'Mis artículos', 'bag', '' ); }
			if ( $articulos ) { $add[] = array( 'articulos', 'Artículos de vendedores', 'bag', '' ); }
			array_splice( $nav, (int) array_search( 'tienda', array_column( $nav, 0 ), true ) + 1, 0, $add );
		}
		// 3.13.77: «Aprende» (tutoriales). Mientras le falte algo, justo debajo de Inicio y con lo que lleva (2/6);
		// cuando lo vio todo, baja al final de Herramientas.
		$learn = class_exists( 'CVD_Aprende' ) ? CVD_Aprende::progress( $user->ID, 'gestora' ) : null;
		if ( $learn ) {
			$pending = $learn['done'] < $learn['total'];
			$item = array( 'aprende', 'Aprende', 'learn', '', $pending ? $learn['done'] . '/' . $learn['total'] : '' );
			$at = $pending ? 1 : (int) array_search( 'herramientas', array_column( $nav, 0 ), true ) + 1;
			array_splice( $nav, $at, 0, array( $item ) );
		}
		// Cuenta doble (gestora y mensajero aprobado): enlace directo a su área de entregas.
		if ( CVD_Registration::is_dual( $user ) && CVD_Registration::is_approved_messenger( $user ) ) {
			$nav[] = array( '', 'Mensajería', '', '' );
			$nav[] = array( 'entregas', 'Mis entregas', 'truck', home_url( '/area-mensajeros/' ) );
		}
		ob_start();
		?>
		<div class="cvga" id="cvga" data-cvga>
			<aside class="cvga-side" aria-label="Menú">
				<div class="cvga-me">
					<span class="cvga-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $user->display_name, 0, 1 ) ) ); ?></span>
					<div class="cvga-me-text"><b><?php echo esc_html( $user->display_name ); ?></b><small>Gestor/a<?php echo $zone ? ' · ' . esc_html( $zone ) : ''; ?></small></div>
					<button class="cvga-icon-btn cvga-collapse" type="button" data-cvga-collapse aria-label="Plegar menú" title="Plegar menú"><?php echo self::icon( 'panel' ); // phpcs:ignore ?></button>
					<button class="cvga-icon-btn cvga-close" type="button" data-cvga-close aria-label="Cerrar menú"><?php echo self::icon( 'x' ); // phpcs:ignore ?></button>
				</div>
				<nav class="cvga-nav">
					<?php foreach ( $nav as $item ) : if ( '' === $item[0] ) : ?><p class="cvga-group"><?php echo esc_html( $item[1] ); ?></p><?php elseif ( 'curru' === $item[0] ) : ?>
					<a class="cvga-link" href="#herramientas" data-cvga-curru><?php echo self::icon( $item[2] ); // phpcs:ignore ?><span class="cvga-label"><?php echo esc_html( $item[1] ); ?></span></a>
					<?php elseif ( '' !== $item[3] ) : // Enlace a otra página (no es una vista de esta app). ?>
					<a class="cvga-link" href="<?php echo esc_url( $item[3] ); ?>"><?php echo self::icon( $item[2] ); // phpcs:ignore ?><span class="cvga-label"><?php echo esc_html( $item[1] ); ?></span></a>
					<?php else : ?>
					<a class="cvga-link" href="#<?php echo esc_attr( $item[0] ); ?>" data-cvga-go="<?php echo esc_attr( $item[0] ); ?>"><?php echo self::icon( $item[2] ); // phpcs:ignore ?><span class="cvga-label"><?php echo esc_html( $item[1] ); ?></span><?php if ( 'aprende' === $item[0] ) : ?><span class="cvga-nav-badge" data-cva-badge="gestora"<?php echo empty( $item[4] ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $item[4] ?? '' ) ); ?></span><?php endif; ?></a>
					<?php endif; endforeach; ?>
				</nav>
				<div class="cvga-nav cvga-nav-bottom">
					<a class="cvga-link" href="#perfil" data-cvga-go="perfil"><?php echo self::icon( 'user' ); // phpcs:ignore ?><span class="cvga-label">Mi perfil</span></a>
					<a class="cvga-link" href="<?php echo esc_url( $wa_shop ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'chat' ); // phpcs:ignore ?><span class="cvga-label">Ayuda por WhatsApp</span></a>
					<a class="cvga-link" href="<?php echo esc_url( $logout ); ?>" data-cvga-logout><?php echo self::icon( 'out' ); // phpcs:ignore ?><span class="cvga-label">Salir</span></a>
				</div>
			</aside>
			<div class="cvga-scrim" data-cvga-close></div>
			<main class="cvga-main" id="cvga-main">
				<header class="cvga-top">
					<button class="cvga-icon-btn" type="button" data-cvga-open aria-label="Abrir menú"><?php echo self::icon( 'menu' ); // phpcs:ignore ?></button>
					<b data-cvga-title>Inicio</b>
					<a class="cvga-shop-btn" href="<?php echo esc_url( $link ); ?>"><?php echo self::icon( 'shop' ); // phpcs:ignore ?><span>Ver la tienda</span></a>
				</header>
				<div class="cvga-wrap">
					<?php echo self::inicio( $user, $first, $link, $code, $orders, $box, $learn ); // phpcs:ignore ?>
					<?php echo $learn ? CVD_Aprende::render( $user, 'gestora' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo self::tienda( $link ); // phpcs:ignore ?>
					<?php echo $vendedor ? CVD_Seller_Store::render_seller_view( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo $articulos ? CVD_Seller_Store::render_gestora_section( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo class_exists( 'CVD_Ficha_Contesta' ) ? CVD_Ficha_Contesta::render_app_section( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo self::herramientas( $estudio, $taller ); // phpcs:ignore ?>
					<?php echo self::calculadora(); // phpcs:ignore ?>
					<?php echo self::buscar( $zone ); // phpcs:ignore ?>
				<?php echo class_exists( 'CVD_Gestora_Confianza' ) ? CVD_Gestora_Confianza::render( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php if ( $programa ) { echo CVD_Programa::render_equipo( $user ) . CVD_Programa::render_piedra( $user ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo $proveedor ? CVD_Supplier_Split::render_owner_sales( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo class_exists( 'CVD_Gestora_Fotos' ) ? CVD_Gestora_Fotos::render( $user, $code ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo $estudio ? CVD_Gestora_Estudio::render( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo $taller ? CVD_Gestora_Taller::render( $user ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php echo $estudio && class_exists( 'CVD_Gestora_Contabilidad' ) ? CVD_Gestora_Contabilidad::render( $user, $orders ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
					<?php if ( $box ) : // 3.13.68: el mismo recuadro arriba de «Tus ventas». ?><div class="cvga-view" data-view="ventas"><?php echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?></div><?php endif; ?>
					<div class="cvga-legacy"><?php echo $legacy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- salida del shortcode, ya escapada allí. ?></div>
				</div>
			</main>
			<nav class="cvga-tabbar" aria-label="Accesos rápidos">
				<a href="#inicio" data-cvga-go="inicio"><?php echo self::icon( 'home' ); // phpcs:ignore ?>Inicio</a>
				<a href="#tienda" data-cvga-go="tienda"><?php echo self::icon( 'bag' ); // phpcs:ignore ?>Vender</a>
				<a href="<?php echo esc_url( $link ); ?>" class="cvga-tab-shop"><?php echo self::icon( 'shop' ); // phpcs:ignore ?>Tienda</a>
				<a href="#clientes" data-cvga-go="clientes"><?php echo self::icon( 'people' ); // phpcs:ignore ?>Clientes</a>
				<a href="#cobrar" data-cvga-go="cobrar"><?php echo self::icon( 'card' ); // phpcs:ignore ?>Cobrar</a>
			</nav>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function inicio( WP_User $user, string $first, string $link, string $code, array $orders, string $box, ?array $learn = null ): string {
		// 3.14.49 (Ernesto 22-oct): en la cuenta del dueño, su parte de las ventas de proveedor de este mes suma a «Ganado».
		$prov = class_exists( 'CVD_Supplier_Split' ) ? CVD_Supplier_Split::owner_commissions( (int) $user->ID ) : array();
		$money = self::money_summary( $orders, (float) ( $prov['month_usd'] ?? 0 ) );
		$clients = class_exists( 'CVD_Clients' ) ? CVD_Clients::list( $user->ID, '', 5000 ) : array();
		$quiet = 0;
		$limit = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		foreach ( $clients as $c ) { if ( ! $c->opt_out && ( ! $c->last_order_at || $c->last_order_at < $limit ) ) { $quiet++; } }
		$ready = self::orders_ready_to_send( $user->ID );
		$top = self::top_products();
		$share_text = static fn( array $p ): string => $p['name'] . ' — ' . self::money( $p['price_for_her'] ) . '. Pídelo aquí: ' . $p['link'];
		ob_start();
		?>
		<section class="cvga-view" data-view="inicio" id="cvga-inicio">
			<div class="cvga-head"><h1>Hola, <?php echo esc_html( $first ); ?></h1><p>Esto es lo que llevas este mes.</p></div>
			<div class="cvga-kpis">
				<article class="cvga-card cvga-kpi"><small>Ganado este mes</small><strong><?php echo esc_html( $money['month'] ); ?></strong><span>Incluye ventas aún sin entregar<?php echo ! empty( $prov['month_count'] ) ? esc_html( ' · ' . self::money( (float) $prov['month_usd'], 'USD' ) . ' de ventas de proveedor' ) : ''; ?></span></article>
				<?php // 3.13.68: «Por cobrar» pasó al recuadro «Tus comisiones» como «Listas para cobrar». ?>
				<article class="cvga-card cvga-kpi"><small>Clientes</small><strong><?php echo esc_html( (string) count( $clients ) ); ?></strong><span><a href="#clientes" data-cvga-go="clientes">Ver la lista</a></span></article>
			</div>
			<?php echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro (CVD_Gestora_Financial_View::commission_box). ?>
			<div class="cvga-grid">
				<section class="cvga-card">
					<div class="cvga-row"><h2>Lo que más se vende</h2><span class="cvga-chip"><?php echo esc_html( $top['label'] ); ?></span></div>
					<p class="cvga-note">Ofrécelo primero: es lo que la gente está comprando.</p>
					<?php if ( ! $top['items'] ) : ?><p class="cvga-empty">Aún no hay ventas este mes. Comparte tu tienda y aquí verás lo que más sale.</p><?php else : ?>
					<ol class="cvga-top-list">
						<?php $max = max( 1, (int) $top['items'][0]['qty'] ); foreach ( $top['items'] as $p ) : $p = self::for_gestora( $p, $user->ID, $code ); ?>
						<li>
							<?php if ( $p['image'] ) : ?><img src="<?php echo esc_url( $p['image'] ); ?>" alt="" width="48" height="48" loading="lazy"><?php else : ?><span class="cvga-thumb" aria-hidden="true"></span><?php endif; ?>
							<div class="cvga-top-text"><b><?php echo esc_html( $p['name'] ); ?></b><?php echo self::avail_tag( (int) $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML fijo. ?><small><?php echo $p['qty'] ? esc_html( $p['qty'] . ' vendidos · ' ) : ''; ?><?php $cup = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::cup_for( (float) $p['price_for_her'] ) : null; echo $cup ? esc_html( self::money( $p['price_for_her'] ) . ' (' . CVD_Daily_Rates::format_cup( $cup ) . ') · ' ) : ''; ?>ganas <?php echo esc_html( self::money( $p['earn'] ) ); ?> c/u</small><?php if ( $p['qty'] ) : ?><span class="cvga-bar"><i style="width:<?php echo esc_attr( (string) round( $p['qty'] / $max * 100 ) ); ?>%"></i></span><?php endif; ?></div>
							<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $share_text( $p ) ) ); ?>" target="_blank" rel="noopener">Compartir</a>
						</li>
						<?php endforeach; ?>
					</ol>
					<?php endif; ?>
				</section>
				<div class="cvga-stack">
					<?php echo self::rate_card(); // phpcs:ignore ?>
					<section class="cvga-card">
						<h2>Tu tienda</h2>
						<p class="cvga-note">Quien compra con tu enlace queda como tu cliente.</p>
						<div class="cvga-link-box"><input id="cvga-link-home" readonly value="<?php echo esc_attr( $link ); ?>" aria-label="Enlace de tu tienda"><button class="cvga-btn cvga-btn-sm" type="button" data-copy-target="cvga-link-home">Copiar</button></div>
						<div class="cvga-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#tienda" data-cvga-go="tienda">Ver QR</a></div>
					</section>
					<section class="cvga-card">
						<h2>Para hoy</h2>
						<ul class="cvga-todo">
							<?php if ( $learn && $learn['done'] < $learn['total'] ) : // 3.13.77: mientras le falte algo de «Aprende». ?><li data-cva-nudge="gestora"><div><b>Aprende</b>: te faltan <span data-cva-left><?php echo esc_html( (string) ( $learn['total'] - $learn['done'] ) ); ?></span> de <?php echo esc_html( (string) $learn['total'] ); ?> vídeos y guías</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#aprende" data-cvga-go="aprende">Ver</a></li><?php endif; ?>
							<?php if ( $ready ) : ?><li><div><b><?php echo esc_html( $ready . ( 1 === $ready ? ' pedido listo' : ' pedidos listos' ) ); ?></b> para mandar al mensajero</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#ventas" data-cvga-go="ventas">Ver</a></li><?php endif; ?>
							<?php if ( $quiet ) : ?><li><div><b><?php echo esc_html( $quiet . ( 1 === $quiet ? ' cliente' : ' clientes' ) ); ?></b> sin comprar en 30 días</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#clientes" data-cvga-go="clientes">Escribir</a></li><?php endif; ?>
							<?php if ( ! $clients ) : ?><li><div><b>Manda tu tienda</b> a 5 contactos</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar</a></li><?php endif; ?>
							<li><div><b>Fotos para publicar</b>: todo lo que hay, con tu enlace</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#fotos" data-cvga-go="fotos">Abrir</a></li>
								<?php if ( ! $ready && ! $quiet && $clients ) : ?><li><div>Todo al día. Comparte un producto de la lista.</div></li><?php endif; ?>
						</ul>
					</section>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function tienda( string $link ): string {
		ob_start();
		?>
		<section class="cvga-view" data-view="tienda" id="cvga-tienda">
			<div class="cvga-head"><h1>Mi tienda</h1><p>Comparte tu enlace. Lo que compren con él cuenta para ti.</p></div>
			<section class="cvga-card">
				<div class="cvga-link-box"><input id="cvga-link-store" readonly value="<?php echo esc_attr( $link ); ?>" aria-label="Enlace de tu tienda"><button class="cvga-btn cvga-btn-sm" type="button" data-copy-target="cvga-link-store">Copiar</button></div>
				<div class="cvga-actions"><a class="cvga-btn" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft" href="<?php echo esc_url( $link ); ?>">Ver mi tienda</a></div>
				<p class="cvga-note">Para mandar un solo producto: ábrelo en tu tienda y copia el enlace de esa página. Ya lleva tu código.</p>
			</section>
			<?php
			// 3.14.55 (D104): la misma tienda, solo con lo que está en la tienda (listo para hoy) o solo lo bajo pedido.
			$parts = class_exists( 'CVD_Departments' ) ? array(
				'tienda' => array( '🏪 Solo lo que está en tienda', 'Listo para hoy: se entrega o se recoge enseguida.', 'Mira lo que tengo listo para hoy en mi tienda Casa Viva: ' ),
				'pedido' => array( '📦 Solo bajo pedido', 'Lo pedimos al proveedor: llega en 24 h.', 'Mira lo que te consigo por encargo (llega en 24 h) en mi tienda Casa Viva: ' ),
			) : array();
			if ( $parts ) :
			?>
			<section class="cvga-card cvga-avail-card">
				<h2>Comparte solo una parte</h2>
				<p class="cvga-note">Tu cliente ve tu tienda ya filtrada. Arriba de los productos puede cambiar a «Todo».</p>
				<?php foreach ( $parts as $mode => $part ) : $url = add_query_arg( CVD_Departments::AVAIL_PARAM, $mode, $link ); ?>
				<div class="cvga-avail-row">
					<b><?php echo esc_html( $part[0] ); ?></b><small class="cvga-note"><?php echo esc_html( $part[1] ); ?></small>
					<div class="cvga-link-box"><input id="cvga-link-<?php echo esc_attr( $mode ); ?>" readonly value="<?php echo esc_attr( $url ); ?>" aria-label="<?php echo esc_attr( $part[0] ); ?>"><button class="cvga-btn cvga-btn-sm" type="button" data-copy-target="cvga-link-<?php echo esc_attr( $mode ); ?>">Copiar</button></div>
					<div class="cvga-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $part[2] . $url ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( $url ); ?>">Ver</a></div>
				</div>
				<?php endforeach; ?>
			</section>
			<?php endif; ?>
			<section class="cvga-card cvga-qr-card">
				<canvas data-qr="<?php echo esc_attr( $link ); ?>" aria-label="QR de tu tienda"></canvas>
				<div><h2>Tu QR</h2><p class="cvga-note">Ponlo en tu estado o imprímelo. Al escanearlo se abre tu tienda.</p><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvga-save-qr>Guardar imagen</button></div>
			</section>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function herramientas( bool $estudio = false, bool $taller = false ): string {
		// 3.13.99: precios de mensajería (decisión de Ernesto, 14-oct). PDF con nombre fijo en uploads/casa-viva/,
		// generado con las tarifas vivas (docs/nexo-business/bienvenida-2026-10-14/generar-pdf.mjs en ernesto-rondon-nexo).
		$uploads  = wp_get_upload_dir();
		$pdf_path = trailingslashit( $uploads['basedir'] ) . 'casa-viva/precios-mensajeria.pdf';
		$pdf_url  = is_readable( $pdf_path ) ? add_query_arg( 'v', (string) filemtime( $pdf_path ), trailingslashit( $uploads['baseurl'] ) . 'casa-viva/precios-mensajeria.pdf' ) : '';
		$pdf_ts   = $pdf_url ? (int) filemtime( $pdf_path ) : 0;
		$meses    = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ); // sin depender del idioma cargado
		$pdf_date = $pdf_ts ? wp_date( 'j', $pdf_ts ) . ' ' . $meses[ (int) wp_date( 'n', $pdf_ts ) - 1 ] . ' ' . wp_date( 'Y', $pdf_ts ) : '';
		$tools = array(
			array( 'search', 'NEXO Busca', '¿Tu cliente pide algo que no tenemos? Súbele una foto y te buscamos quién lo vende. Lo vendes tú o por Casa Viva.', class_exists( 'CVD_Gestora_Busca' ) && CVD_Gestora_Busca::available() ? 'ready' : 'soon', 'buscar' ),
			array( 'shield', '¿Es de confianza este número?', 'Antes de mandar un pedido, mira si el número de tu cliente fue reportado por estafa. Y reporta tú los que veas.', class_exists( 'CVD_Gestora_Confianza' ) && CVD_Gestora_Confianza::available() ? 'ready' : 'soon', 'confianza' ),
			array( 'truck', 'Precios de mensajería', 'Cuánto cuesta llevar el pedido a cada reparto, en CUP. La mensajería es del mensajero.', 'ready', 'precios-mensajeria' ),
			array( 'calc', 'Calculadora', 'Pon el precio en USD y mira cuánto es en CUP, euros, tarjeta clásica, Zelle o PayPal con la tasa de hoy.', 'ready', 'calculadora' ),
			array( 'chat', 'Pregúntale a Curru', 'Precios, medidas y si hay en almacén. Responde al momento.', 'ready', 'curru' ),
			array( 'image', 'Fotos para publicar', 'Todo lo que hay en existencia, con tu precio y tu enlace: descarga la foto y copia el texto.', 'ready', 'fotos' ),
			...( $taller ? array( array( 'camera', 'Taller de fotos', 'Sube una foto de tu móvil y te la dejo lista: cuadrada, con fondo limpio y el nombre del producto. Y te escribo el texto para Facebook.', 'ready', 'taller' ) ) : array() ),
			array( 'target', 'Cazadora de clientes', 'Encuentra gente que está pidiendo lo que vendes, para escribirle primero.', 'soon', '' ),
			array( 'camera', $estudio ? 'Mi estudio de fotos' : 'Fotos de producto', 'Convierte una foto normal en una foto bonita para vender: fondo blanco, en una sala bonita, para tu estado…', $estudio ? 'ready' : 'soon', 'estudio' ),
		);
		ob_start();
		?>
		<section class="cvga-view" data-view="herramientas" id="cvga-herramientas">
			<div class="cvga-head"><h1>Herramientas</h1><p>Para encontrar clientes y vender más rápido.</p></div>
			<div class="cvga-tools">
				<?php foreach ( $tools as $t ) : ?>
				<article class="cvga-card cvga-tool<?php echo 'soon' === $t[3] ? ' is-soon' : ''; ?>">
					<span class="cvga-tool-ic"><?php echo self::icon( $t[0] ); // phpcs:ignore ?></span>
					<h2><?php echo esc_html( $t[1] ); ?></h2>
					<p><?php echo esc_html( $t[2] ); ?></p>
					<div class="cvga-actions">
						<?php if ( 'soon' === $t[3] ) : ?><span class="cvga-chip">Pronto</span>
						<?php elseif ( 'precios-mensajeria' === $t[4] ) : // no «mensajeria»: en gestora-app.js ese alias va a Ventas ?>
							<a class="cvga-btn cvga-btn-sm" href="<?php echo esc_url( home_url( '/cotizar-mensajeria/' ) ); ?>">Calcular</a>
							<?php if ( $pdf_url ) : ?><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener">Ver PDF</a><span class="cvga-note"><?php echo esc_html( 'Actualizado: ' . $pdf_date ); ?></span><?php endif; ?>
						<?php elseif ( 'curru' === $t[4] ) : ?><button class="cvga-btn cvga-btn-sm" type="button" data-cvga-curru>Abrir</button>
						<?php else : ?><a class="cvga-btn cvga-btn-sm" href="#<?php echo esc_attr( $t[4] ); ?>" data-cvga-go="<?php echo esc_attr( $t[4] ); ?>">Abrir</a><?php endif; ?>
					</div>
				</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Tasa del día en Inicio: 1 USD = N CUP, cuándo se actualizó y acceso a la calculadora. */
	private static function rate_card(): string {
		if ( ! class_exists( 'CVD_Daily_Rates' ) ) { return ''; }
		$r = CVD_Daily_Rates::get();
		$stale = CVD_Daily_Rates::is_stale( $r );
		ob_start();
		?>
		<section class="cvga-card cvga-rate-card">
			<div class="cvga-row"><h2>Tasa de hoy</h2><?php if ( $r['updated_at'] ) : ?><span class="cvga-chip<?php echo $stale ? ' is-warn' : ''; ?>"><?php echo esc_html( 'actualizada ' . self::rate_when( $r['updated_at'] ) ); ?></span><?php endif; ?></div>
			<?php if ( $r['cup'] ) : ?>
			<p class="cvga-rate-big">1 USD = <b><?php echo esc_html( CVD_Daily_Rates::format_cup( (int) round( $r['cup'] ) ) ); ?></b> <small>en efectivo</small></p>
			<p class="cvga-note"><?php echo esc_html( implode( ' · ', array_merge( array( 'Transferencia CUP: ' . CVD_Daily_Rates::format_cup( (int) CVD_Daily_Rates::cup_transfer( (float) $r['cup'] ) ) ), self::rate_bits( $r ) ) ) ); // 3.14.43 ?></p>
			<?php if ( $stale ) : ?><p class="cvga-warn">Esta tasa tiene más de un día. Confírmala en el grupo General antes de cobrar.</p><?php endif; ?>
			<?php else : ?>
			<p class="cvga-empty">Aún no hay tasa del día. La tienda la publica cada mañana en el grupo General.</p>
			<?php endif; ?>
			<div class="cvga-actions"><a class="cvga-btn cvga-btn-sm" href="#calculadora" data-cvga-go="calculadora"><?php echo self::icon( 'calc' ); // phpcs:ignore ?>Calculadora</a></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Calculadora: precio en USD (+ mensajería en CUP, opcional) → cuánto cobrar en CUP, euros, tarjeta
	 * clásica, Zelle y PayPal con la tasa del día. Las cuentas las hace gestora-app.js con data-rates.
	 */
	private static function calculadora(): string {
		$rates = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::public_data() : array( 'cup' => null, 'updatedTs' => 0, 'stale' => true );
		$r = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::get() : array();
		ob_start();
		?>
		<section class="cvga-view" data-view="calculadora" id="cvga-calculadora">
			<div class="cvga-head"><h1>Calculadora</h1><p>Pon el precio en USD y te digo cuánto cobrar en cada forma de pago, con la tasa de hoy.</p></div>
			<form class="cvga-card cvga-calc" data-cvga-calc data-rates="<?php echo esc_attr( wp_json_encode( $rates ) ); ?>" autocomplete="off">
				<div class="cvga-calc-in">
					<label class="cvga-field" for="cvga-calc-usd"><span>Precio en USD</span><input id="cvga-calc-usd" name="usd" type="text" inputmode="decimal" placeholder="Ej.: 25" enterkeyhint="done"></label>
					<label class="cvga-field" for="cvga-calc-ship"><span>Mensajería en CUP <small>(opcional)</small></span><input id="cvga-calc-ship" name="ship" type="text" inputmode="numeric" placeholder="Ej.: 500" enterkeyhint="done"></label>
				</div>
				<p class="cvga-note"><a href="<?php echo esc_url( home_url( '/cotizar-mensajeria/' ) ); ?>">¿Cuánto es la mensajería? Ver precios</a></p>
				<p class="cvga-calc-rate"><?php echo $rates['updatedTs'] ? esc_html( 'Tasa del día · actualizada ' . self::rate_when( (int) $rates['updatedTs'] ) ) : 'Aún no hay tasa del día.'; ?></p>
				<?php if ( $rates['updatedTs'] && $rates['stale'] ) : ?><p class="cvga-warn">Ojo: esta tasa tiene más de <?php echo esc_html( (string) CVD_Daily_Rates::STALE_HOURS ); ?> horas. Confírmala en el grupo General antes de cobrar.</p><?php endif; ?>
			</form>
			<section class="cvga-card" aria-live="polite">
				<ul class="cvga-calc-out" data-calc-out><li class="cvga-calc-empty">Escribe un precio para ver cuánto es.</li></ul>
				<div class="cvga-actions"><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-calc-copy hidden>Copiar para el cliente</button><small class="cvga-note" data-calc-copied role="status"></small></div>
			</section>
			<?php if ( $r && $r['cup'] ) : ?>
			<section class="cvga-card">
				<h2>Tasas de hoy</h2>
				<p class="cvga-note">Lo que paga el cliente por cada 1 USD del precio.</p>
				<ul class="cvga-rate-list">
					<li><span>CUP efectivo</span><b><?php echo esc_html( CVD_Daily_Rates::format_cup( (int) round( $r['cup'] ) ) ); ?></b></li>
					<li><span>CUP transferencia</span><b><?php echo esc_html( CVD_Daily_Rates::format_cup( (int) CVD_Daily_Rates::cup_transfer( (float) $r['cup'] ) ) ); // 3.14.43 ?></b></li>
					<?php foreach ( self::rate_bits( $r, true ) as $label => $value ) : ?><li><span><?php echo esc_html( $label ); ?></span><b><?php echo esc_html( $value ); ?></b></li><?php endforeach; ?>
					<li><span>USDT</span><b>Consultar</b></li>
				</ul>
			</section>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Euro, clásica, Zelle y PayPal en corto ("Clásica ×1,15"); con $long, etiqueta => valor. */
	private static function rate_bits( array $r, bool $long = false ): array {
		$n = static fn( float $v ): string => rtrim( rtrim( number_format( $v, 2, ',', '.' ), '0' ), ',' );
		$bits = array();
		if ( $r['eur'] ) { $bits['Euro'] = 1.0 === (float) $r['eur'] ? '1:1' : '×' . $n( $r['eur'] ); }
		if ( $r['clasica'] ) { $bits['Tarjeta clásica'] = '×' . $n( $r['clasica'] ); }
		if ( $r['zelle'] ) { $bits['Zelle'] = '×' . $n( $r['zelle'] ); }
		if ( null !== $r['paypal_pct'] ) { $bits['PayPal'] = '+' . $n( $r['paypal_pct'] ) . ' %'; }
		if ( $long ) { return $bits; }
		$short = array();
		foreach ( $bits as $label => $value ) { $short[] = ( 'Tarjeta clásica' === $label ? 'Clásica' : $label ) . ' ' . $value; }
		return $short;
	}

	/** "hoy 08:15", "ayer 20:10" o "9 oct 08:15", en la hora de la web. */
	private static function rate_when( int $ts ): string {
		$day = wp_date( 'Y-m-d', $ts );
		if ( wp_date( 'Y-m-d' ) === $day ) { return 'hoy ' . wp_date( 'H:i', $ts ); }
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) { return 'ayer ' . wp_date( 'H:i', $ts ); }
		return wp_date( 'j M H:i', $ts );
	}

	private static function buscar( string $zone ): string {
		// 3.14.28 «NEXO Busca»: encabezado con la marca, foto o texto, y lo de después (assets/gestora-busca.js).
		$user = wp_get_current_user();
		$remaining = class_exists( 'CVD_Gestora_Busca' ) ? CVD_Gestora_Busca::remaining( $user->ID ) : 0;
		$config = array(
			'base'     => esc_url_raw( rest_url( 'casa-viva/v1/gestora/busca' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'rates'    => class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::public_data() : array( 'cup' => null ),
			'zone'     => $zone && ! is_email( $zone ) ? $zone : 'La Habana',
			'estudio'  => class_exists( 'CVD_Gestora_Estudio' ) && CVD_Gestora_Estudio::enabled_for( $user ),
			'pedidos'  => class_exists( 'CVD_Nexo_Busca' ) ? CVD_Nexo_Busca::list_for( $user->ID ) : array(),
		);
		ob_start();
		?>
		<section class="cvga-view" data-view="buscar" id="cvga-buscar">
			<header class="cvnb-hero">
				<img class="cvnb-logo" src="<?php echo esc_url( CVD_URL . 'assets/logo-casa-viva-crema.svg' ); ?>" alt="Casa Viva" width="150" height="31">
				<h1>NEXO Busca</h1>
				<p>¿Tu cliente pide algo que no tenemos? Súbele una foto y te buscamos quién lo vende.</p>
			</header>
			<form class="cvga-card cvga-busca-form" data-cvga-busca autocomplete="off">
				<script type="application/json" data-busca-config><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON con HEX_TAG. ?></script>
				<label class="cvnb-drop" for="cvga-busca-img">
					<img class="cvga-busca-preview" alt="" hidden>
					<span class="cvnb-drop-text"><b>📷 Subir foto o captura</b><small>La que te mandó el cliente, o una de Facebook o Revolico</small></span>
					<input id="cvga-busca-img" name="image" type="file" accept="image/jpeg,image/png,image/webp">
				</label>
				<label class="cvga-field" for="cvga-busca-ctx"><span>O escribe qué buscas <small>(si subiste foto, es opcional)</small></span><input id="cvga-busca-ctx" name="context" type="text" maxlength="300" placeholder="Ej.: cortina de baño con recubrimiento de teflón"></label>
				<label class="cvga-field" for="cvga-busca-zone"><span>Zona del cliente</span><input id="cvga-busca-zone" name="zone" type="text" maxlength="100" value="<?php echo esc_attr( $config['zone'] ); ?>"></label>
				<div class="cvga-actions"><button class="cvga-btn cvnb-go" type="submit">Buscar quién lo vende</button><small class="cvga-note" data-busca-left>Te quedan <?php echo esc_html( (string) $remaining ); ?> búsquedas hoy.</small></div>
				<p class="cvga-busca-status" data-busca-status role="status" hidden></p>
			</form>
			<div class="cvga-busca-results" data-busca-results></div>
			<div class="cvnb-use" data-busca-use hidden></div>
			<section class="cvga-card cvnb-mine" data-busca-mine<?php echo $config['pedidos'] ? '' : ' hidden'; ?>>
				<h2>Mis solicitudes a Casa Viva</h2>
				<p class="cvga-note">Lo que pediste vender por Casa Viva. Te avisamos por WhatsApp cuando lo comprobemos con el proveedor.</p>
				<ul class="cvnb-list" data-busca-mine-list></ul>
			</section>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Lo más vendido en toda la tienda (30 días); si aún no hay ventas, los favoritos de siempre. */
	public static function top_products( int $limit = 5 ): array {
		$cached = get_transient( self::TOP_CACHE );
		if ( is_array( $cached ) ) { return $cached; }
		$qty = array();
		$orders = wc_get_orders( array( 'limit' => 400, 'type' => 'shop_order', 'status' => array( 'processing', 'completed', 'on-hold' ), 'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ) ) );
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$pid = (int) $item->get_product_id();
				if ( $pid ) { $qty[ $pid ] = ( $qty[ $pid ] ?? 0 ) + max( 1, (int) $item->get_quantity() ); }
			}
		}
		arsort( $qty );
		$items = array();
		foreach ( array_keys( $qty ) as $pid ) {
			$row = self::product_row( $pid, (int) $qty[ $pid ] );
			if ( $row ) { $items[] = $row; }
			if ( count( $items ) >= $limit ) { break; }
		}
		$label = 'Toda la tienda · 30 días';
		if ( count( $items ) < 3 ) {
			$label = 'Favoritos de la tienda';
			$items = array();
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => $limit * 3, 'fields' => 'ids', 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true ) );
			foreach ( $ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_in_stock() ) { continue; }
				$row = self::product_row( (int) $pid, 0 );
				if ( $row ) { $items[] = $row; }
				if ( count( $items ) >= $limit ) { break; }
			}
		}
		$result = array( 'label' => $label, 'items' => $items );
		set_transient( self::TOP_CACHE, $result, 30 * MINUTE_IN_SECONDS );
		return $result;
	}

	private static function product_row( int $pid, int $qty ): ?array {
		$product = wc_get_product( $pid );
		if ( ! $product || 'publish' !== $product->get_status() ) { return null; }
		return array( 'id' => $pid, 'name' => $product->get_name(), 'qty' => $qty, 'price' => (float) $product->get_price(), 'image' => (string) get_the_post_thumbnail_url( $pid, 'thumbnail' ), 'url' => (string) get_permalink( $pid ) );
	}

	/** 3.14.55 (D104): etiqueta «🏪 En tienda» / «📦 Bajo pedido · llega en 24 h» de un producto de la lista. */
	private static function avail_tag( int $pid ): string {
		if ( ! class_exists( 'CVD_Departments' ) ) { return ''; }
		return CVD_Departments::is_bajo_pedido_id( $pid )
			? '<span class="cvga-avail-tag cvga-avail-tag--pedido">📦 Bajo pedido · llega en 24 h</span>'
			: '<span class="cvga-avail-tag">🏪 En tienda</span>';
	}

	/** Precio que ve su cliente y lo que gana ella por unidad (comisión + lo que subió). */
	private static function for_gestora( array $p, int $user_id, string $code ): array {
		$product = wc_get_product( $p['id'] );
		$base = (float) $p['price'];
		$stored = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::stored_prices( $user_id ) : array();
		$markup = (float) get_user_meta( $user_id, '_cvd_global_markup_percent', true );
		$hers = isset( $stored[ $p['id'] ] ) && '' !== $stored[ $p['id'] ] ? (float) $stored[ $p['id'] ] : $base * ( 1 + $markup / 100 );
		$type = $product ? sanitize_key( (string) $product->get_meta( '_cvd_commission_type', true ) ) : '';
		$value = $product ? $product->get_meta( '_cvd_commission_value', true ) : '';
		if ( '' === $type && '' === $value ) {
			$user_rate = get_user_meta( $user_id, '_cvd_commission_rate', true );
			$rate = '' !== $user_rate ? (float) $user_rate : (float) get_option( 'cvd_default_commission_rate', 13 );
			$commission = $base * $rate / 100;
		} else {
			$commission = 'fixed' === $type ? (float) $value : $base * (float) $value / 100;
		}
		$p['price_for_her'] = round( $hers, 2 );
		$p['earn'] = round( $commission + max( 0, $hers - $base ), 2 );
		$p['link'] = add_query_arg( 'ref', $code, $p['url'] );
		return $p;
	}

	/**
	 * Lo ganado este mes (sus pedidos ya leídos en render()); sin pedidos anulados, comisiones canceladas ni pruebas.
	 * 3.14.49: $supplier_usd = su parte de las ventas de proveedor del mes (solo la cuenta del dueño; 0 para las demás).
	 */
	private static function money_summary( array $orders, float $supplier_usd = 0.0 ): array {
		$month_start = strtotime( gmdate( 'Y-m-01 00:00:00' ) );
		$month = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { continue; }
			if ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) { continue; } // 3.13.68: igual que el recuadro.
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'cancelled' === $status ) { continue; }
			$cur = strtoupper( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) ) ?: 'USD';
			$amount = (float) $order->get_meta( '_cvd_commission_amount', true );
			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() >= $month_start ) { $month[ $cur ] = ( $month[ $cur ] ?? 0 ) + $amount; }
		}
		if ( $supplier_usd > 0 ) { $month['USD'] = ( $month['USD'] ?? 0 ) + $supplier_usd; }
		return array( 'month' => self::money_list( $month ) );
	}

	private static function money_list( array $by_currency ): string {
		if ( ! $by_currency ) { return self::money( 0 ); }
		$parts = array();
		foreach ( $by_currency as $cur => $amount ) { $parts[] = self::money( $amount, $cur ); }
		return implode( ' · ', $parts );
	}

	private static function money( float $amount, string $currency = '' ): string {
		$currency = $currency ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
		return number_format_i18n( $amount, 2 ) . ' ' . $currency;
	}

	private static function orders_ready_to_send( int $user_id ): int {
		$n = 0;
		foreach ( wc_get_orders( array( 'limit' => 50, 'type' => 'shop_order', 'status' => array( 'processing', 'on-hold' ), 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user_id ) ) as $order ) {
			if ( 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) && 'ready' === sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) && class_exists( 'CVD_Delivery' ) && 'unassigned' === CVD_Delivery::status( $order ) ) { $n++; }
		}
		return $n;
	}

	private static function referral_code( WP_User $user ): string {
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		return $code ?: 'CV' . $user->ID . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $user->user_login ), 0, 8 ) );
	}

	private static function icon( string $name ): string {
		$paths = array(
			'home'   => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
			'bag'    => '<path d="M4 9h16l-1 11H5z"/><path d="M8 9V7a4 4 0 0 1 8 0v2"/>',
			'tag'    => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
			'people' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 11a3 3 0 1 0-1-5.8M21.5 20a5.5 5.5 0 0 0-4-5.3"/>',
			'spark'  => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
			'doc'    => '<path d="M5 3h10l4 4v14H5z"/><path d="M9 12h6M9 16h6"/>',
			'chart'  => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
			'card'   => '<rect x="2.5" y="6" width="19" height="13" rx="2.5"/><path d="M2.5 10h19"/>',
			'invite' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M19 8v6M16 11h6"/>',
			'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5A8 8 0 1 1 21 12z"/>',
			'out'    => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
			'menu'   => '<path d="M4 7h16M4 12h16M4 17h16"/>',
			'panel'  => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/>',
			'x'      => '<path d="M6 6l12 12M18 6L6 18"/>',
			'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
			'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
			'shield' => '<path d="M12 3l7 3v5c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V6z"/><path d="M9 12l2 2 4-4"/>',
			'truck'  => '<path d="M2 6h12v10H2z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
			'calc'   => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M8 6.5h8v3H8z"/><path d="M8.5 13h.01M12 13h.01M15.5 13h.01M8.5 16.5h.01M12 16.5h.01M15.5 16.5h.01"/>',
			'camera' => '<rect x="3" y="6" width="18" height="14" rx="3"/><circle cx="12" cy="13" r="3.5"/><path d="M8 6l1.5-2h5L16 6"/>',
			'image'  => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
			'gem'    => '<path d="M7 4h10l4 5-9 11L3 9z"/><path d="M3 9h18M10 4 8 9l4 11 4-11-2-5"/>',
			'learn'  => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l5.5 3.5-5.5 3.5z"/>',
			'shop'   => '<path d="M3.5 9.5 5 4h14l1.5 5.5"/><path d="M3.5 9.5a2.8 2.8 0 0 0 5.6 0 2.9 2.9 0 0 0 5.8 0 2.8 2.8 0 0 0 5.6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-4.5h4V20"/>',
		);
		return '<svg class="cvga-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
