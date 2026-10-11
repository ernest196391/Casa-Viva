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
	}

	/** Todo el cuerpo de la app: menú + vistas nuevas + secciones del portal clásico. */
	public static function render(): string {
		$user = wp_get_current_user();
		// Primero el portal clásico: procesa los formularios (precios, pagos) antes de calcular las cifras.
		$legacy = do_shortcode( '[casa_viva_portal role="gestora"]' );
		$code = self::referral_code( $user );
		$link = add_query_arg( 'ref', $code, wc_get_page_permalink( 'shop' ) );
		$first = strtok( (string) $user->display_name, ' ' ) ?: $user->display_name;
		$zone = (string) get_user_meta( $user->ID, '_cvd_zone', true );
		$wa_shop = 'https://wa.me/5354056173?text=' . rawurlencode( 'Hola, soy la gestora ' . $user->display_name . ' (código ' . $code . ').' );
		$logout = wp_logout_url( home_url( '/casa-viva-app/' ) );

		$nav = array(
			array( 'inicio', 'Inicio', 'home', '' ),
			array( '', 'Vender', '', '' ),
			array( 'tienda', 'Mi tienda', 'bag', '' ),
			array( 'precios', 'Mis precios', 'tag', '' ),
			array( 'clientes', 'Clientes', 'people', '' ),
			array( '', 'Herramientas', '', '' ),
			array( 'curru', 'Pregúntale a Curru', 'chat', '' ),
			array( 'materiales', 'Fotos y textos listos', 'doc', '' ),
			array( 'herramientas', 'Más herramientas', 'spark', '' ),
			array( '', 'Mi dinero', '', '' ),
			array( 'ventas', 'Ventas', 'chart', '' ),
			array( 'cobrar', 'Cobrar', 'card', '' ),
			array( '', 'Mi equipo', '', '' ),
			array( 'invitar', 'Invitar gestoras', 'invite', '' ),
		);
		ob_start();
		?>
		<div class="cvga" id="cvga" data-cvga>
			<aside class="cvga-side" aria-label="Menú">
				<div class="cvga-me">
					<span class="cvga-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $user->display_name, 0, 1 ) ) ); ?></span>
					<div class="cvga-me-text"><b><?php echo esc_html( $user->display_name ); ?></b><small>Gestora<?php echo $zone ? ' · ' . esc_html( $zone ) : ''; ?></small></div>
					<button class="cvga-icon-btn cvga-collapse" type="button" data-cvga-collapse aria-label="Plegar menú" title="Plegar menú"><?php echo self::icon( 'panel' ); // phpcs:ignore ?></button>
					<button class="cvga-icon-btn cvga-close" type="button" data-cvga-close aria-label="Cerrar menú"><?php echo self::icon( 'x' ); // phpcs:ignore ?></button>
				</div>
				<nav class="cvga-nav">
					<?php foreach ( $nav as $item ) : if ( '' === $item[0] ) : ?><p class="cvga-group"><?php echo esc_html( $item[1] ); ?></p><?php elseif ( 'curru' === $item[0] ) : ?>
					<a class="cvga-link" href="#herramientas" data-cvga-curru><?php echo self::icon( $item[2] ); // phpcs:ignore ?><span class="cvga-label"><?php echo esc_html( $item[1] ); ?></span></a>
					<?php else : ?>
					<a class="cvga-link" href="#<?php echo esc_attr( $item[0] ); ?>" data-cvga-go="<?php echo esc_attr( $item[0] ); ?>"><?php echo self::icon( $item[2] ); // phpcs:ignore ?><span class="cvga-label"><?php echo esc_html( $item[1] ); ?></span></a>
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
				</header>
				<div class="cvga-wrap">
					<?php echo self::inicio( $user, $first, $link, $code ); // phpcs:ignore ?>
					<?php echo self::tienda( $link ); // phpcs:ignore ?>
					<?php echo self::herramientas(); // phpcs:ignore ?>
					<div class="cvga-legacy"><?php echo $legacy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- salida del shortcode, ya escapada allí. ?></div>
				</div>
			</main>
			<nav class="cvga-tabbar" aria-label="Accesos rápidos">
				<a href="#inicio" data-cvga-go="inicio"><?php echo self::icon( 'home' ); // phpcs:ignore ?>Inicio</a>
				<a href="#tienda" data-cvga-go="tienda"><?php echo self::icon( 'bag' ); // phpcs:ignore ?>Vender</a>
				<a href="#clientes" data-cvga-go="clientes"><?php echo self::icon( 'people' ); // phpcs:ignore ?>Clientes</a>
				<a href="#cobrar" data-cvga-go="cobrar"><?php echo self::icon( 'card' ); // phpcs:ignore ?>Cobrar</a>
			</nav>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function inicio( WP_User $user, string $first, string $link, string $code ): string {
		$money = self::money_summary( $user->ID );
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
				<article class="cvga-card cvga-kpi"><small>Ganado</small><strong><?php echo esc_html( $money['month'] ); ?></strong><span>Incluye ventas aún sin entregar</span></article>
				<article class="cvga-card cvga-kpi"><small>Por cobrar</small><strong><?php echo esc_html( $money['approved'] ); ?></strong><span>De pedidos ya entregados</span></article>
				<article class="cvga-card cvga-kpi"><small>Clientes</small><strong><?php echo esc_html( (string) count( $clients ) ); ?></strong><span><a href="#clientes" data-cvga-go="clientes">Ver la lista</a></span></article>
			</div>
			<div class="cvga-grid">
				<section class="cvga-card">
					<div class="cvga-row"><h2>Lo que más se vende</h2><span class="cvga-chip"><?php echo esc_html( $top['label'] ); ?></span></div>
					<p class="cvga-note">Ofrécelo primero: es lo que la gente está comprando.</p>
					<?php if ( ! $top['items'] ) : ?><p class="cvga-empty">Aún no hay ventas este mes. Comparte tu tienda y aquí verás lo que más sale.</p><?php else : ?>
					<ol class="cvga-top-list">
						<?php $max = max( 1, (int) $top['items'][0]['qty'] ); foreach ( $top['items'] as $p ) : $p = self::for_gestora( $p, $user->ID, $code ); ?>
						<li>
							<?php if ( $p['image'] ) : ?><img src="<?php echo esc_url( $p['image'] ); ?>" alt="" width="48" height="48" loading="lazy"><?php else : ?><span class="cvga-thumb" aria-hidden="true"></span><?php endif; ?>
							<div class="cvga-top-text"><b><?php echo esc_html( $p['name'] ); ?></b><small><?php echo $p['qty'] ? esc_html( $p['qty'] . ' vendidos · ' ) : ''; ?>ganas <?php echo esc_html( self::money( $p['earn'] ) ); ?> c/u</small><?php if ( $p['qty'] ) : ?><span class="cvga-bar"><i style="width:<?php echo esc_attr( (string) round( $p['qty'] / $max * 100 ) ); ?>%"></i></span><?php endif; ?></div>
							<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $share_text( $p ) ) ); ?>" target="_blank" rel="noopener">Compartir</a>
						</li>
						<?php endforeach; ?>
					</ol>
					<?php endif; ?>
				</section>
				<div class="cvga-stack">
					<section class="cvga-card">
						<h2>Tu tienda</h2>
						<p class="cvga-note">Quien compra con tu enlace queda como tu cliente.</p>
						<div class="cvga-link-box"><input id="cvga-link-home" readonly value="<?php echo esc_attr( $link ); ?>" aria-label="Enlace de tu tienda"><button class="cvga-btn cvga-btn-sm" type="button" data-copy-target="cvga-link-home">Copiar</button></div>
						<div class="cvga-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#tienda" data-cvga-go="tienda">Ver QR</a></div>
					</section>
					<section class="cvga-card">
						<h2>Para hoy</h2>
						<ul class="cvga-todo">
							<?php if ( $ready ) : ?><li><div><b><?php echo esc_html( $ready . ( 1 === $ready ? ' pedido listo' : ' pedidos listos' ) ); ?></b> para mandar al mensajero</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#ventas" data-cvga-go="ventas">Ver</a></li><?php endif; ?>
							<?php if ( $quiet ) : ?><li><div><b><?php echo esc_html( $quiet . ( 1 === $quiet ? ' cliente' : ' clientes' ) ); ?></b> sin comprar en 30 días</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="#clientes" data-cvga-go="clientes">Escribir</a></li><?php endif; ?>
							<?php if ( ! $clients ) : ?><li><div><b>Manda tu tienda</b> a 5 contactos</div><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar</a></li><?php endif; ?>
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
				<div class="cvga-actions"><a class="cvga-btn" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">Ver mi tienda</a></div>
				<p class="cvga-note">Para mandar un solo producto: ábrelo en tu tienda y copia el enlace de esa página. Ya lleva tu código.</p>
			</section>
			<section class="cvga-card cvga-qr-card">
				<canvas data-qr="<?php echo esc_attr( $link ); ?>" aria-label="QR de tu tienda"></canvas>
				<div><h2>Tu QR</h2><p class="cvga-note">Ponlo en tu estado o imprímelo. Al escanearlo se abre tu tienda.</p><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvga-save-qr>Guardar imagen</button></div>
			</section>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function herramientas(): string {
		$tools = array(
			array( 'chat', 'Pregúntale a Curru', 'Precios, medidas y si hay en almacén. Responde al momento.', 'ready', 'curru' ),
			array( 'doc', 'Fotos y textos listos', 'Publicaciones de Casa Viva para copiar y pegar en tu estado.', 'ready', 'materiales' ),
			array( 'search', 'Buscar precios', 'Sube la foto de un producto y mira a cuánto lo venden en Revolico y otras tiendas.', 'soon', '' ),
			array( 'target', 'Cazadora de clientes', 'Encuentra gente que está pidiendo lo que vendes, para escribirle primero.', 'soon', '' ),
			array( 'camera', 'Fotos de producto', 'Convierte una foto normal en una foto bonita para vender.', 'soon', '' ),
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

	private static function money_summary( int $user_id ): array {
		$month_start = strtotime( gmdate( 'Y-m-01 00:00:00' ) );
		$month = array(); $approved = array();
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user_id ) );
		foreach ( $orders as $order ) {
			if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { continue; }
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'cancelled' === $status ) { continue; }
			$cur = strtoupper( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) ) ?: 'USD';
			$amount = (float) $order->get_meta( '_cvd_commission_amount', true );
			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() >= $month_start ) { $month[ $cur ] = ( $month[ $cur ] ?? 0 ) + $amount; }
			if ( 'approved' === $status ) { $approved[ $cur ] = ( $approved[ $cur ] ?? 0 ) + $amount; }
		}
		return array( 'month' => self::money_list( $month ), 'approved' => self::money_list( $approved ) );
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
			'camera' => '<rect x="3" y="6" width="18" height="14" rx="3"/><circle cx="12" cy="13" r="3.5"/><path d="M8 6l1.5-2h5L16 6"/>',
		);
		return '<svg class="cvga-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
