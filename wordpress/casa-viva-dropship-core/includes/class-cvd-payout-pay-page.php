<?php

defined( 'ABSPATH' ) || exit;

/**
 * Página «Pagar a {gestora}» (3.14.23, Ernesto 15-oct): la abre quien paga desde el aviso de WhatsApp. Una sola
 * pantalla, pensada para el teléfono y el internet lento (sin tema, sin imágenes, ~10 KB): total grande en CUP y USD,
 * «Copiar tarjeta» y «Copiar teléfono», detalle por pedido con la tasa de cada venta, la captura y «Ya pagué».
 *
 * Quién puede entrar (lo más seguro sin pedir contraseña desde WhatsApp):
 *  - Enlace firmado: ?cvd_pago=ID&t=caducidad-teléfono-firma. La firma (HMAC con el secreto del sitio) ata el pago, el
 *    teléfono que lo recibió y la caducidad (7 días). Solo vale si ese teléfono sigue en la lista de quien paga
 *    (cvd_payout_admin_phones; en efectivo también cvd_payout_cash_phones). Es de un solo uso para pagar: cuando el
 *    pago queda hecho, el enlace solo enseña el comprobante, sin tarjeta ni botón.
 *  - O con la sesión abierta en la web: administración (manage_woocommerce) o dependienta (cvd_manage_sales).
 * Quién firmó: la persona de la web con el teléfono del enlace (o la de la sesión); queda en cvd_payout_events.
 *
 * La captura NO va a la biblioteca pública: se guarda en uploads/cvd-privado/pagos/ (cerrado con .htaccess) como
 * adjunto privado, y se ve solo por ?cvd_captura=ID (la gestora dueña, administración, dependientas o el enlace).
 *
 * «Abrir Transfermóvil»: no se pone. Transfermóvil no publica un enlace (esquema o intent de Android) que abra una
 * transferencia con la tarjeta y el importe ya puestos; abrir la app vacía no ahorra nada y no se pudo probar.
 */
final class CVD_Payout_Pay_Page {
	private const TTL = 7 * DAY_IN_SECONDS;
	private const DIR = 'cvd-privado/pagos';

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
	}

	public static function url( int $payout_id ): string {
		return add_query_arg( 'cvd_pago', $payout_id, home_url( '/' ) );
	}

	public static function signed_url( int $payout_id, string $phone ): string {
		$token = CVD_Payout_Math::sign( $payout_id, CVD_Payout_Math::phone8( $phone ), time() + self::TTL, self::key() );
		return add_query_arg( array( 'cvd_pago' => $payout_id, 't' => $token ), home_url( '/' ) );
	}

	public static function proof_url( int $payout_id, string $token = '' ): string {
		$args = array( 'cvd_captura' => $payout_id );
		if ( '' !== $token ) { $args['t'] = $token; }
		return add_query_arg( $args, home_url( '/' ) );
	}

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-pagos'; }

	public static function route(): void {
		if ( isset( $_GET['cvd_captura'] ) ) { self::serve_proof( absint( $_GET['cvd_captura'] ) ); }
		if ( isset( $_GET['cvd_pago'] ) ) { self::page( absint( $_GET['cvd_pago'] ) ); }
	}

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: pago de comisión (privado)' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * ¿Quién está mirando este pago? ['ok', 'actor' (usuario que firma), 'name', 'via' (sesion|enlace), 'token'].
	 * $owner_ok: la gestora dueña también puede (solo para ver su captura).
	 */
	public static function access( array $payout, bool $owner_ok = false ): array {
		$deny = array( 'ok' => false, 'actor' => 0, 'name' => '', 'via' => '', 'token' => '' );
		$cash = in_array( sanitize_key( (string) $payout['pay_method'] ), array( 'cash_cup', 'cash_usd' ), true ) || ( '' === (string) $payout['pay_method'] && 'cash' === $payout['method'] );
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			// Las dependientas solo ven los pagos en efectivo (las transferencias, con la tarjeta, las paga Lennys).
			if ( user_can( $user, 'manage_woocommerce' ) || ( $cash && user_can( $user, 'cvd_manage_sales' ) ) || ( $owner_ok && (int) $payout['owner_user_id'] === $user->ID ) ) {
				return array( 'ok' => true, 'actor' => $user->ID, 'name' => $user->display_name, 'via' => 'sesion', 'token' => '' );
			}
		}
		$token = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['t'] ?? '' ) ) );
		if ( '' === $token ) { return $deny; }
		$phone = CVD_Payout_Math::verify( (int) $payout['id'], $token, time(), self::key() );
		if ( '' === $phone ) { return $deny; }
		$allowed = CVD_Payout_Avisos::phones( 'cvd_payout_admin_phones', array( '5356885368' ) );
		if ( $cash ) {
			$allowed = array_merge( $allowed, CVD_Payout_Avisos::phones( 'cvd_payout_cash_phones', array( '5359163951' ) ) );
		}
		if ( ! in_array( '53' . $phone, $allowed, true ) ) { return $deny; }
		$person = CVD_Payout_Avisos::person_for_phone( $phone );
		$actor = $person ? $person->ID : absint( get_option( 'cvd_panel_actor_user_id', 0 ) );
		return array( 'ok' => true, 'actor' => $actor, 'name' => $person ? $person->display_name : '…' . substr( $phone, -4 ), 'via' => 'enlace', 'token' => $token );
	}

	// ------------------------------------------------------------------ La página

	private static function page( int $payout_id ): void {
		self::no_cache();
		$payout = CVD_Payouts::get( $payout_id );
		$access = $payout ? self::access( $payout ) : array( 'ok' => false );
		if ( ! $payout || ! $access['ok'] ) {
			status_header( 403 );
			self::shell( 'Enlace no válido', '<div class="box"><h1>Este enlace ya no vale</h1><p>Caducó o no es para ti. Pide el aviso otra vez a Ernesto, o entra en la web con tu cuenta y vuelve a abrirlo.</p></div>' );
		}
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['cvd_pagar'] ) ) {
			$notice = self::handle_post( $payout, $access );
			$payout = CVD_Payouts::get( $payout_id );
		}
		if ( isset( $_GET['hecho'] ) && ! $notice ) { $notice = 'ok'; }
		self::shell( 'Pagar a ' . CVD_Payouts::view( $payout )['first'], self::body( $payout, $access, $notice ) );
	}

	private static function handle_post( array $payout, array $access ): string {
		$nonce_ok = 'sesion' === $access['via'] ? (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cvd_pagar_' . $payout['id'] ) : true; // con enlace, el token del formulario ya es el secreto
		if ( ! $nonce_ok ) { return 'La página venció. Recárgala e intenta otra vez.'; }
		if ( 'paid' === $payout['status'] ) { return 'already'; }
		$view = CVD_Payouts::view( $payout );
		$reference = mb_substr( sanitize_text_field( wp_unslash( $_POST['cvd_ref'] ?? '' ) ), 0, 120 );
		$proof = 0;
		if ( ! empty( $_FILES['cvd_captura']['name'] ) ) {
			$stored = self::store_proof( 'cvd_captura', (int) $payout['id'] );
			if ( is_wp_error( $stored ) ) { return $stored->get_error_message(); }
			$proof = (int) $stored;
		}
		if ( ! $view['cash'] && ! $proof && '' === $reference ) { return 'Sube la captura de la transferencia (o escribe el número de la transacción).'; }
		$label = $view['cash'] ? 'Efectivo listo en la tienda' : ( $proof ? 'Captura' : 'Transacción ' . $reference );
		if ( $proof && '' !== $reference ) { $label .= ' · ' . $reference; }
		$label .= ' · ' . $access['name'] . ( 'enlace' === $access['via'] ? ' (enlace)' : '' );
		$previous = get_current_user_id();
		wp_set_current_user( (int) $access['actor'] ); // quién firma el pago en el historial; solo dentro de esta petición
		$result = CVD_Payouts::pay_now( (int) $payout['id'], mb_substr( $label, 0, 190 ), $proof );
		wp_set_current_user( $previous );
		if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
		// Volver con GET: recargar la página no reenvía el formulario.
		wp_safe_redirect( add_query_arg( array_filter( array( 'cvd_pago' => (int) $payout['id'], 't' => $access['token'] ?: null, 'hecho' => 'already' === $result ? 'ya' : '1' ) ), home_url( '/' ) ), 303 );
		exit;
	}

	private static function body( array $payout, array $access, string $notice ): string {
		$v = CVD_Payouts::view( $payout );
		$open = in_array( $v['status'], array( 'requested', 'approved' ), true );
		$label = CVD_Payouts::METHOD_LABELS[ $v['kind'] ] ?? 'Pago';
		$n = count( $v['lines'] );
		$h = '';
		if ( 'ok' === $notice || 'already' === $notice ) {
			$h .= '<div class="ok">' . ( 'already' === $notice || 'ya' === ( $_GET['hecho'] ?? '' ) ? 'Ya estaba marcado. No se pagó dos veces.' : ( $v['cash'] ? '✅ Listo. Le avisamos a ' . esc_html( $v['first'] ) . ' que pase a recoger.' : '✅ Listo. Le avisamos a ' . esc_html( $v['first'] ) . ' que ya le pagaste.' ) ) . '</div>';
		} elseif ( '' !== $notice ) {
			$h .= '<div class="err">' . esc_html( $notice ) . '</div>';
		}
		$h .= '<p class="kick">Casa Viva · pago de comisión #' . (int) $v['id'] . '</p>';
		$h .= '<h1>Pagar a ' . esc_html( $v['name'] ) . '</h1>';
		$h .= '<div class="total"><small>' . esc_html( $label ) . '</small><strong>' . esc_html( $v['pay_text'] ) . '</strong>';
		$sub = array();
		if ( 'mixed' === $v['kind'] ) { $sub[] = '= ' . CVD_Payout_Math::usd( $v['usd'] ) . ' de comisión: ' . CVD_Payout_Math::usd( $v['pay'] ) . ' en dólares y el resto en CUP con la tasa de cada venta'; } // 3.14.68
		elseif ( 'USD' !== $v['currency'] ) { $sub[] = '= ' . CVD_Payout_Math::usd( $v['usd'] ) . ' de comisión'; } elseif ( $v['cup'] > 0 && ! $v['missing_rate'] ) { $sub[] = '≈ ' . CVD_Payout_Math::cup( $v['cup'] ) . ' con la tasa de cada venta'; }
		$sub[] = $n . ( 1 === $n ? ' pedido' : ' pedidos' );
		if ( $v['deduction'] > 0 ) { $sub[] = 'ya restada la deuda de ' . CVD_Payout_Math::money( $v['deduction'], $v['deduction_currency'] ); }
		$h .= '<span>' . esc_html( implode( ' · ', $sub ) ) . '</span></div>';

		if ( $open ) {
			$rows = array();
			if ( 'transfer' === $v['kind'] ) {
				$rows[] = array( 'Tarjeta', $v['card'] ? CVD_Payout_Math::card_pretty( $v['card'] ) : ( $v['account'] ?: '(no la puso)' ), $v['card'] ?: $v['account'], 'Copiar tarjeta' );
			} elseif ( 'other' === $v['kind'] ) {
				$rows[] = array( 'Dónde cobra', $v['account'] ?: '(no lo puso)', $v['account'], 'Copiar' );
			}
			$phone = $v['phone'] ?: CVD_Payout_Math::phone8( $v['whatsapp'] );
			if ( $phone ) { $rows[] = array( 'Teléfono', $phone, $phone, 'Copiar teléfono' ); }
			if ( 'mixed' === $v['kind'] ) { // 3.14.68: dos importes, los dos en efectivo
				$rows[] = array( 'En dólares', CVD_Payout_Math::usd( $v['pay'] ), '', '' );
				$rows[] = array( 'En CUP', CVD_Payout_Math::cup( $v['pay_cup'] ), '', '' );
			} else {
				$rows[] = array( 'Importe', $v['pay_text'], 'USD' === $v['currency'] ? number_format( $v['pay'], 2, '.', '' ) : rtrim( rtrim( number_format( $v['pay'], 2, '.', '' ), '0' ), '.' ), 'Copiar importe' );
			}
			$h .= '<div class="copy">';
			foreach ( $rows as $row ) {
				$h .= '<div class="row"><div><small>' . esc_html( $row[0] ) . '</small><b>' . esc_html( $row[1] ) . '</b></div>' . ( '' !== (string) $row[2] ? '<button type="button" class="btn soft" data-copy="' . esc_attr( (string) $row[2] ) . '">' . esc_html( $row[3] ) . '</button>' : '' ) . '</div>';
			}
			$h .= '</div>';
			if ( $v['cash'] ) { $h .= '<p class="note">Dale el dinero en la tienda cuando venga. Si falta dinero allí, avísale a Lennys.</p>'; }
		}

		$h .= '<details class="detail"' . ( $open ? '' : ' open' ) . '><summary>Detalle por pedido</summary><ul>';
		foreach ( $v['lines'] as $line ) {
			$h .= '<li><div><b>#' . esc_html( (string) $line['number'] ) . '</b> · ' . esc_html( (string) $line['date'] ) . '<br><small>' . esc_html( CVD_Payouts::items_text( (array) $line['items'] ) ) . '</small></div><div class="num"><b>' . esc_html( CVD_Payout_Math::usd( (float) $line['usd'] ) ) . '</b><small>' . esc_html( CVD_Payouts::rate_text( $line ) ) . '</small></div></li>';
		}
		foreach ( (array) $v['debts'] as $debt ) {
			$h .= '<li class="minus"><div>Deuda con la tienda<br><small>' . esc_html( (string) $debt['note'] ) . '</small></div><div class="num"><b>− ' . esc_html( CVD_Payout_Math::money( (float) $debt['amount'], (string) $debt['currency'] ) ) . '</b></div></li>';
		}
		$h .= '<li class="sum"><div>Total a pagar</div><div class="num"><b>' . esc_html( $v['pay_text'] ) . '</b>' . ( $v['deduction'] > 0 && $v['deduction_currency'] !== $v['currency'] ? '' : '' ) . '</div></li></ul></details>';

		if ( $open ) {
			$action = add_query_arg( array_filter( array( 'cvd_pago' => $v['id'], 't' => $access['token'] ?: null ) ), home_url( '/' ) );
			$h .= '<form method="post" enctype="multipart/form-data" action="' . esc_url( $action ) . '" class="pay" data-pay>';
			$h .= '<input type="hidden" name="t" value="' . esc_attr( (string) $access['token'] ) . '">' . wp_nonce_field( 'cvd_pagar_' . $v['id'], '_wpnonce', false, false );
			if ( ! $v['cash'] ) {
				$h .= '<label class="file"><span>1. Sube la captura de la transferencia</span><input type="file" name="cvd_captura" accept="image/*" data-shrink></label>';
				$h .= '<label class="ref"><span>Si no tienes captura, el número de la transacción</span><input name="cvd_ref" maxlength="60" autocomplete="off" inputmode="text"></label>';
				$h .= '<button class="btn big" name="cvd_pagar" value="1" data-once>2. Ya pagué</button>';
			} else {
				$h .= '<label class="ref"><span>Nota (opcional)</span><input name="cvd_ref" maxlength="60" autocomplete="off"></label>';
				$h .= '<button class="btn big" name="cvd_pagar" value="1" data-once>Dinero listo: que venga a recoger</button>';
			}
			$h .= '<small class="muted">Toca una sola vez. Si se repite, no se paga dos veces.</small></form>';
		} else {
			$states = array( 'paid' => $v['cash'] ? ( '' !== $v['received_at'] ? 'Recibido por ' . $v['first'] . ' el ' . get_date_from_gmt( $v['received_at'], 'd/m H:i' ) : 'Listo para recoger · falta que ' . $v['first'] . ' toque «Recibido»' ) : 'Pagado', 'rejected' => 'Rechazado: no hay que pagar' );
			$who = $v['paid_by'] ? get_userdata( $v['paid_by'] ) : null;
			$h .= '<div class="done"><b>' . esc_html( $states[ $v['status'] ] ?? $v['status'] ) . '</b>';
			if ( 'paid' === $v['status'] ) { $h .= '<small>' . esc_html( 'Marcado el ' . get_date_from_gmt( $v['paid_at'], 'd/m/Y H:i' ) . ( $who ? ' por ' . $who->display_name : '' ) . ( $v['card'] ? ' · tarjeta …' . substr( $v['card'], -4 ) : '' ) ) . '</small>'; }
			if ( $v['proof'] ) { $h .= '<a class="btn soft" href="' . esc_url( self::proof_url( $v['id'], (string) $access['token'] ) ) . '" target="_blank" rel="noopener">Ver la captura</a>'; }
			$h .= '</div>';
		}
		return $h;
	}

	/** HTML mínimo, sin tema (internet lento). Termina la petición. */
	private static function shell( string $title, string $body ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' · Casa Viva</title><style>' . self::css() . '</style></head><body><main>' . $body . '</main><script>' . self::js() . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body ya escapado al armarlo.
		exit;
	}

	private static function css(): string {
		return ':root{--ink:#25231f;--muted:#6f6a62;--line:#e8e1d8;--bg:#f6f1e6;--card:#fffdf9;--brand:#0f3d3a;--soft:#e4ece8;--good:#2f7a4f;--bad:#9a392f;--gold:#c39443}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:520px;margin:0 auto;padding:16px 16px 40px}'
			. 'h1{font-size:24px;line-height:1.2;margin:2px 0 14px}.kick{margin:0;color:var(--muted);font-size:13px}'
			. '.total{background:var(--brand);color:#fff;border-radius:16px;padding:18px;margin-bottom:12px}.total small{display:block;opacity:.8;font-size:13px}.total strong{display:block;font-size:38px;line-height:1.1;margin:4px 0;letter-spacing:-.02em}.total span{font-size:14px;opacity:.9}'
			. '.copy{background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:12px}.row{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:12px 14px;border-top:1px solid var(--line)}.row:first-child{border-top:0}.row small{display:block;color:var(--muted);font-size:12px}.row b{font-size:19px;letter-spacing:.02em;word-break:break-all}'
			. '.btn{display:inline-block;border:0;border-radius:999px;background:var(--brand);color:#fff;font:inherit;font-weight:700;padding:11px 16px;text-decoration:none;cursor:pointer;min-height:44px}.btn.soft{background:var(--soft);color:var(--brand);white-space:nowrap;font-size:14px;padding:9px 13px}.btn.big{width:100%;font-size:18px;padding:15px;margin-top:8px}.btn.copied{background:var(--good);color:#fff}.btn[disabled]{opacity:.6}'
			. '.detail{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:4px 14px;margin-bottom:12px}.detail summary{padding:10px 0;font-weight:700;cursor:pointer}.detail ul{list-style:none;margin:0;padding:0}.detail li{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid var(--line)}.detail small{color:var(--muted);font-size:13px}.num{text-align:right;white-space:nowrap}.num small{display:block}.minus b{color:var(--bad)}.sum{font-weight:700}'
			. '.pay{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}.pay label{display:block;margin-bottom:12px}.pay label span{display:block;font-weight:700;font-size:14px;margin-bottom:6px}.pay input[type=file]{width:100%;font:inherit;padding:10px;border:2px dashed var(--line);border-radius:12px;background:#fff}.pay input[name=cvd_ref]{width:100%;font:inherit;padding:11px;border:1px solid #cdb8ad;border-radius:11px;min-height:44px}.muted{color:var(--muted);font-size:13px;display:block;margin-top:8px;text-align:center}'
			. '.note{background:#fff7e6;border:1px solid #f0d9a8;border-radius:12px;padding:10px 12px;font-size:14px}.ok{background:#e7f4ec;border:1px solid #b9dcc6;color:#1f5a37;border-radius:12px;padding:12px;margin-bottom:12px;font-weight:700}.err{background:#fff0ed;border:1px solid #f0c4bb;color:var(--bad);border-radius:12px;padding:12px;margin-bottom:12px}'
			. '.done{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;display:grid;gap:8px}.done b{font-size:18px}.done small{color:var(--muted)}.box{background:var(--card);border-radius:14px;padding:20px;margin-top:30px}';
	}

	/** Copiar, achicar la captura antes de subirla (internet lento) y no enviar dos veces. */
	private static function js(): string {
		return '(function(){document.addEventListener("click",function(e){var b=e.target.closest("[data-copy]");if(!b)return;var t=b.getAttribute("data-copy"),o=b.textContent;function done(){b.textContent="Copiado ✓";b.classList.add("copied");setTimeout(function(){b.textContent=o;b.classList.remove("copied")},1800)}'
			. 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done,fallback)}else{fallback()}function fallback(){var a=document.createElement("textarea");a.value=t;a.setAttribute("readonly","");a.style.position="fixed";a.style.opacity="0";document.body.appendChild(a);a.select();try{document.execCommand("copy");done()}catch(x){window.prompt("Copia:",t)}a.remove()}});'
			. 'var f=document.querySelector("[data-shrink]");if(f&&window.DataTransfer&&window.HTMLCanvasElement){f.addEventListener("change",function(){var file=f.files&&f.files[0];if(!file||file.size<350000||!/^image\\//.test(file.type))return;var img=new Image(),u=URL.createObjectURL(file);img.onload=function(){var s=Math.min(1,1400/Math.max(img.width,img.height)),c=document.createElement("canvas");c.width=Math.round(img.width*s);c.height=Math.round(img.height*s);c.getContext("2d").drawImage(img,0,0,c.width,c.height);c.toBlob(function(bl){if(!bl||bl.size>=file.size)return;try{var dt=new DataTransfer();dt.items.add(new File([bl],"captura.jpg",{type:"image/jpeg"}));f.files=dt.files}catch(x){}},"image/jpeg",.72);URL.revokeObjectURL(u)};img.src=u})}'
			. 'var p=document.querySelector("[data-pay]");if(p)p.addEventListener("submit",function(){var b=p.querySelector("[data-once]");if(b){setTimeout(function(){b.disabled=true;b.textContent="Guardando…"},0)}})})();';
	}

	// ------------------------------------------------------------------ Captura privada

	private static function private_dir(): string {
		$base = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIR;
		if ( ! is_dir( $base ) ) { wp_mkdir_p( $base ); }
		$root = dirname( $base );
		foreach ( array( $root, $base ) as $dir ) {
			if ( ! file_exists( $dir . '/.htaccess' ) ) { file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); }
			if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); }
		}
		return $base;
	}

	/** Guarda la imagen subida en $field como adjunto privado del pago. Devuelve el id del adjunto. */
	public static function store_proof( string $field, int $payout_id ) {
		$file = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se valida abajo.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) { return new WP_Error( 'cvd_proof_upload', 'No llegó la imagen. Prueba otra vez (con buena señal).' ); }
		return self::store_proof_file( (string) $file['tmp_name'], $payout_id, true );
	}

	/** $uploaded=false solo para pruebas (copia en vez de mover). */
	public static function store_proof_file( string $path, int $payout_id, bool $uploaded = false ) {
		if ( ! is_file( $path ) || filesize( $path ) > 8 * MB_IN_BYTES ) { return new WP_Error( 'cvd_proof_size', 'La imagen no puede pasar de 8 MB.' ); }
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- archivo que puede no ser imagen.
		$types = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		if ( ! isset( $types[ $mime ] ) ) { return new WP_Error( 'cvd_proof_type', 'Sube una foto o captura (JPG, PNG o WEBP).' ); }
		$dir = self::private_dir();
		$name = 'pago-' . $payout_id . '-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . $types[ $mime ];
		$target = $dir . '/' . $name;
		$ok = $uploaded ? move_uploaded_file( $path, $target ) : copy( $path, $target );
		if ( ! $ok ) { return new WP_Error( 'cvd_proof_store', 'No se pudo guardar la captura.' ); }
		$attachment = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => 'Captura del pago #' . $payout_id, 'post_content' => '', 'post_status' => 'private' ), $target, 0, true );
		if ( is_wp_error( $attachment ) ) { return $attachment; }
		update_post_meta( (int) $attachment, '_cvd_private_payout_proof', $payout_id );
		return (int) $attachment;
	}

	private static function serve_proof( int $payout_id ): void {
		self::no_cache();
		$payout = CVD_Payouts::get( $payout_id );
		$access = $payout ? self::access( $payout, true ) : array( 'ok' => false );
		$attachment = $payout ? absint( $payout['proof_attachment_id'] ) : 0;
		$path = $attachment ? (string) get_attached_file( $attachment ) : '';
		if ( ! $access['ok'] || ! $path || ! is_file( $path ) ) { status_header( 404 ); header( 'Content-Type: text/plain; charset=utf-8' ); echo 'No disponible.'; exit; }
		$mime = (string) get_post_mime_type( $attachment );
		header( 'Content-Type: ' . ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ? $mime : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="captura-pago-' . $payout_id . '.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- archivo privado validado.
		exit;
	}
}
