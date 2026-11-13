<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cadena de mensajeros (Core 3.14.160, Ernesto 10-nov). Diseño aprobado:
 * docs/nexo-business/carreras-atascadas-mensajero-no-responde-2026-11-10.md (caso #6411: Lázaro sin conectarse 5 h).
 *
 * Un pedido ya no se queda esperando al mensajero elegido:
 *  1. La gestora deja en el pedido un 1.º mensajero (_cvd_preferred_messenger_user_id, el de siempre) y, si quiere, un
 *     2.º de respaldo (BACKUP_META) y «también al grupo» (GROUP_TOO_META). Puede cambiarlo también DESPUÉS de asignado,
 *     mientras el mensajero no recogió el paquete (Ventas o el bot).
 *  2. SIN FRENO: si el que la tiene la suelta («suelto», «ya no puedo», el botón), pasa AL INSTANTE al 2.º (vale por
 *     privado); sin 2.º, al grupo. Lo hace el Core dentro de CVD_Bot_Bridge::release_by_messenger, no el bot.
 *  3. Reloj del Core (cron cada minuto y cada vez que el bot pide /bot/cadena-avisos): a los 10 min sin aceptar,
 *     recordatorio al mensajero y aviso a la gestora; si hay 2.º, se le abre a la vez (gana el primero que acepte).
 *     A los 20 min sin aceptar nadie, sale al grupo. Tiempos: opciones cvd_cadena_aviso_min / cvd_cadena_grupo_min
 *     (el bot las copia de crm_settings con POST /bot/cadena/ajustes). Interruptor: cvd_cadena (si | no).
 *  4. «Suelto» de quien no tiene la carrera no afecta (release_by_messenger ya lo rechaza). Cada suelta queda apuntada
 *     (quién, cuándo, cuánto la tuvo) para el resumen de las 21:00: GET /bot/cadena/resumen.
 *  5. Orden de administración: POST /bot/cadena/{id}/asignar = soltar + asignar + aceptar de una vez; el nuevo recibe
 *     el vale por el camino de siempre (/bot/dispatch/direct) y el anterior su «ya no es tuya» (avisos de abajo).
 *  6. Avisos: el reparto cambia al instante, pero los avisos que deja el Core (ya no es tuya, recordatorio, invitación
 *     al 2.º, «salió al grupo» a la gestora) salen cuando el estado lleva 60 s estable. Un aviso nuevo para la misma
 *     persona y pedido sustituye al anterior, y uno que ya no es verdad se descarta: nunca «ya no es tuya» y «sí es tuya»
 *     seguidos. VivaBot: GET /bot/cadena-avisos y POST /bot/cadena-avisos/{id}/{key}/done (mismo camino que los pagos).
 */
final class CVD_Cadena_Mensajeros {
	private const NS = 'casa-viva/v1';
	public const BACKUP_META    = '_cvd_backup_messenger_user_id';
	public const GROUP_TOO_META = '_cvd_chain_group_too';
	public const CLOCK_META     = '_cvd_chain_clock';
	public const STAGE_META     = '_cvd_chain_stage';       // first | backup | admin | group
	public const REMINDED_META  = '_cvd_chain_reminded_at';  // unix
	public const INVITED_META   = '_cvd_chain_invited';      // id del 2.º abierto a la vez
	public const HANDOFF_META   = '_cvd_chain_handoff';      // 'Y-m-d H:i:s' UTC: de un mensajero a otro, no se anuncia en el grupo
	public const NEXT_META      = '_cvd_chain_next';         // a quién pasa tras soltar
	public const HOLD_META      = '_cvd_chain_hold_until';   // unix: el vale espera a que el estado se asiente
	public const FIRST_AT_META  = '_cvd_chain_first_at';     // unix: primera asignación (minutos hasta aceptar)
	public const LOG_META       = '_cvd_chain_log';
	public const AVISOS_META    = '_cvd_chain_avisos';
	public const PENDING_META   = '_cvd_chain_avisos_pending';
	public const SEEN_META      = '_cvd_last_seen_at';       // usuario: unix de su última señal (Pa'llá o WhatsApp)
	public const CRON           = 'cvd_cadena_tick';
	private const LOG_OPTION    = 'cvd_cadena_log';
	private const OPEN_DELIVERY = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store' );

	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_cvd_gestora_cadena', array( __CLASS__, 'save_from_gestora' ) );
	}

	// ------------------------------------------------------------------ Ajustes

	public static function on(): bool { return 'no' !== sanitize_key( (string) get_option( 'cvd_cadena', 'si' ) ); }
	public static function remind_min(): int { return max( 1, absint( get_option( 'cvd_cadena_aviso_min', 10 ) ) ); }
	public static function group_min(): int { return max( self::remind_min() + 1, absint( get_option( 'cvd_cadena_grupo_min', 20 ) ) ); }
	public static function stable_seconds(): int { return min( 600, max( 0, absint( get_option( 'cvd_cadena_estable_seg', 60 ) ) ) ); }

	public static function schedules( $schedules ) {
		$schedules['cvd_cada_minuto'] = array( 'interval' => 60, 'display' => 'Cada minuto (cadena de mensajeros)' );
		return $schedules;
	}

	public static function schedule(): void {
		if ( self::on() && ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 60, 'cvd_cada_minuto', self::CRON ); }
	}

	// ------------------------------------------------------------------ Ayudas

	private static function user( $id ): ?WP_User {
		$user = $id ? get_userdata( absint( $id ) ) : false;
		return $user instanceof WP_User ? $user : null;
	}

	private static function valid_messenger( $id ): ?WP_User {
		$user = self::user( $id );
		return $user && class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::is_valid_messenger( $user ) ? $user : null;
	}

	public static function backup( WC_Order $order ): ?WP_User { return self::valid_messenger( $order->get_meta( self::BACKUP_META, true ) ); }
	public static function group_too( WC_Order $order ): bool { return '1' === (string) $order->get_meta( self::GROUP_TOO_META, true ); }
	public static function holder_id( WC_Order $order ): int { return absint( $order->get_meta( '_cvd_messenger_user_id', true ) ); }

	private static function first_name( string $name ): string { return strtok( trim( $name ), ' ' ) ?: $name; }

	private static function phone_of( int $user_id ): string {
		$own = preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, '_cvd_whatsapp', true ) );
		return strlen( $own ) >= 8 ? $own : preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, 'billing_phone', true ) );
	}

	private static function utc_ts( string $mysql ): int {
		if ( '' === $mysql ) { return 0; }
		$ts = strtotime( $mysql . ' UTC' );
		return $ts ? $ts : 0;
	}

	private static function system_actor(): int {
		$actor = absint( get_option( 'cvd_system_actor_user_id', 0 ) );
		if ( $actor ) { return $actor; }
		$ids = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) );
		return (int) ( $ids[0] ?? 0 );
	}

	/** Corre $callback como el administrador del sistema (las transiciones de la cadena no las hace una persona). */
	private static function as_system( callable $callback ) {
		$previous = get_current_user_id();
		$actor = self::system_actor();
		if ( $actor ) { wp_set_current_user( $actor ); }
		try { return $callback( $actor ); }
		finally { wp_set_current_user( $previous ); }
	}

	private static function is_test( WC_Order $order ): bool {
		if ( class_exists( 'CVD_Delivery' ) && '' !== CVD_Delivery::blocks_dispatch( $order ) ) { return true; }
		$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$user = self::user( $owner );
		return $owner && $user && ( '1' === (string) get_user_meta( $owner, '_cvd_test_account', true ) || str_contains( (string) $user->user_login, 'piloto' ) );
	}

	/** Hora de Cuba: entre 22:00 y 7:30 el reloj no mueve nada (lo vencido se hace a las 7:30). */
	public static function quiet_now( ?int $now = null ): bool {
		$range = (string) get_option( 'cvd_cadena_silencio', '22:00-07:30' );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $range, $m ) ) { return false; }
		$date = new DateTime( '@' . ( $now ?? time() ) );
		$date->setTimezone( new DateTimeZone( 'America/Havana' ) );
		$minute = (int) $date->format( 'G' ) * 60 + (int) $date->format( 'i' );
		$from = (int) $m[1] * 60 + (int) $m[2];
		$to = (int) $m[3] * 60 + (int) $m[4];
		return $from > $to ? ( $minute >= $from || $minute < $to ) : ( $minute >= $from && $minute < $to );
	}

	// ------------------------------------------------------------------ Reloj y estado (dentro de las transiciones)

	/** Dentro de una mutación atómica: la carrera queda asignada (sin aceptar) y el reloj empieza. */
	public static function start_clock( WC_Order $locked, string $stage, string $at = '' ): void {
		$locked->update_meta_data( self::CLOCK_META, '1' );
		$locked->update_meta_data( self::STAGE_META, sanitize_key( $stage ) ?: 'first' );
		$locked->delete_meta_data( self::REMINDED_META );
		$locked->delete_meta_data( self::INVITED_META );
		$locked->delete_meta_data( self::HANDOFF_META );
		$locked->delete_meta_data( self::NEXT_META );
		if ( ! absint( $locked->get_meta( self::FIRST_AT_META, true ) ) ) {
			$locked->update_meta_data( self::FIRST_AT_META, self::utc_ts( $at ) ?: time() );
		}
	}

	/** Segundos que el mensajero tuvo la carrera (desde que se la asignaron o la aceptó). */
	private static function held_seconds( WC_Order $order ): int {
		$since = max( self::utc_ts( (string) $order->get_meta( '_cvd_direct_assigned_at', true ) ), self::utc_ts( (string) $order->get_meta( '_cvd_delivery_accepted_at', true ) ) );
		return $since ? max( 0, time() - $since ) : 0;
	}

	/** Apunta una suelta / un vencimiento / un cambio (pedido + lista global para el resumen de las 21:00). */
	private static function log( WC_Order $order, string $kind, int $messenger_id, int $held, string $by = '', int $to = 0 ): void {
		$entry = array( 't' => time(), 'order' => $order->get_id(), 'kind' => $kind, 'messenger' => $messenger_id, 'held' => $held, 'by' => mb_substr( $by, 0, 80 ), 'to' => $to );
		$list = $order->get_meta( self::LOG_META, true );
		$list = is_array( $list ) ? $list : array();
		$list[] = $entry;
		$order->update_meta_data( self::LOG_META, array_slice( $list, -40 ) );
		$global = get_option( self::LOG_OPTION, array() );
		$global = is_array( $global ) ? $global : array();
		$global[] = $entry;
		update_option( self::LOG_OPTION, array_slice( $global, -1500 ), false );
	}

	/**
	 * Dentro de la mutación de CVD_Bot_Bridge::release_by_messenger (el que la tiene la suelta, sin freno):
	 * se apunta y, si hay un 2.º que no es él, la carrera queda marcada para pasarle a él (no se anuncia en el grupo).
	 */
	public static function on_release( WC_Order $locked, WP_User $messenger, string $at ): void {
		self::log( $locked, 'suelta', $messenger->ID, self::held_seconds( $locked ) );
		$locked->delete_meta_data( self::CLOCK_META );
		$locked->delete_meta_data( self::REMINDED_META );
		if ( ! self::on() ) { return; }
		$next = self::next_after( $locked, $messenger->ID );
		if ( $next ) {
			$locked->update_meta_data( self::NEXT_META, $next );
			$locked->update_meta_data( self::HANDOFF_META, $at );
		} else {
			$locked->update_meta_data( self::STAGE_META, 'group' );
		}
	}

	/** A quién pasa tras soltar: el 2.º si la tenía el 1.º (o la tenía alguien de fuera de la cadena). 0 = al grupo. */
	private static function next_after( WC_Order $order, int $releaser ): int {
		$stage = sanitize_key( (string) $order->get_meta( self::STAGE_META, true ) );
		if ( in_array( $stage, array( 'backup', 'group' ), true ) ) { return 0; }
		$backup = self::backup( $order );
		if ( ! $backup || $backup->ID === $releaser ) { return 0; }
		$first = absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) );
		// El 2.º es respaldo del 1.º. Si la soltó otra persona que no era de la cadena, también le toca al 2.º.
		return ( ! $first || $first === $releaser || 'first' === $stage || 'admin' === $stage ) ? $backup->ID : 0;
	}

	/** Después de soltar: pasa al 2.º (vale por privado) o queda en el grupo. Devuelve el nombre del nuevo, o ''. */
	public static function after_release( int $order_id, WP_User $released_by ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) { return ''; }
		$next = self::valid_messenger( $order->get_meta( self::NEXT_META, true ) );
		if ( $next && 'offered' === CVD_Delivery::status( $order ) ) {
			$note = 'Cadena de mensajeros: ' . $released_by->display_name . ' soltó la carrera y pasa al instante a ' . $next->display_name . ' (2.º, respaldo). No se anunció en el grupo.';
			// Al que la soltó ya le contesta el bot («Listo, soltaste…»): aquí no se le repite nada.
			if ( self::assign_from_offered( $order, $next, 'backup', $note ) ) { return $next->display_name; }
		}
		// Sin 2.º (o no se pudo): queda en el grupo como siempre; la gestora se entera de qué pasó.
		$fresh = wc_get_order( $order_id );
		if ( $fresh ) {
			$fresh->delete_meta_data( self::NEXT_META );
			$fresh->delete_meta_data( self::HANDOFF_META );
			$fresh->update_meta_data( self::STAGE_META, 'group' );
			$fresh->save();
			self::queue_gestora( $fresh, 'grupo', '🔁 *#' . $fresh->get_order_number() . '*: ' . self::first_name( $released_by->display_name ) . ' no puede llevarlo y lo soltó. Ya está en el grupo de mensajeros; te aviso quién lo coge.' );
		}
		return '';
	}

	/** «Oferta abierta» (o recién soltada) → asignada directo a $to, sin aceptar. El reloj empieza de nuevo. */
	public static function assign_from_offered( WC_Order $order, WP_User $to, string $stage, string $note, bool $hold = true, bool $announced = false ): bool {
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) || ! CVD_Direct_Dispatch::is_valid_messenger( $to ) ) { return false; }
		$hold_until = $hold ? time() + self::stable_seconds() : 0;
		$result = self::as_system( static function ( int $actor ) use ( $order, $to, $stage, $note, $hold_until, $announced ) {
			return CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'assigned', array(
				'actor_user_id' => $actor, 'source' => 'cvd_cadena_mensajeros',
				'metadata' => array( 'messenger' => $to->ID, 'direct' => true, 'chain' => $stage ),
				'precondition' => static function ( WC_Order $locked, string $current ) {
					if ( 'offered' !== $current || absint( $locked->get_meta( '_cvd_messenger_user_id', true ) ) ) { return CVD_Order_Transition_Service::CONFLICT; }
					return 'yes' === $locked->get_meta( '_cvd_delivery_incident_active', true ) ? CVD_Order_Transition_Service::PRECONDITION_FAILED : true;
				},
				'atomic_mutation' => static function ( WC_Order $locked, $from, $next, $who, string $at ) use ( $to, $stage, $note, $hold_until, $announced ): void {
					$locked->update_meta_data( '_cvd_messenger_user_id', $to->ID );
					$locked->update_meta_data( '_cvd_direct_assignment', 1 );
					$locked->update_meta_data( '_cvd_direct_assigned_at', $at );
					$locked->delete_meta_data( '_cvd_delivery_accepted_at' );
					if ( $announced ) { $locked->update_meta_data( '_cvd_bot_direct_announced_at', $at ); } else { $locked->delete_meta_data( '_cvd_bot_direct_announced_at' ); }
					self::start_clock( $locked, $stage, $at );
					if ( $hold_until ) { $locked->update_meta_data( self::HOLD_META, $hold_until ); } else { $locked->delete_meta_data( self::HOLD_META ); }
					$locked->add_order_note( $note );
				},
				'after_commit' => static function ( WC_Order $saved, array $transition ) use ( $to ): void {
					CVD_Delivery::after_commit_effects( $saved, 'assigned' );
					if ( empty( $transition['idempotent_replay'] ) && class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_offer( $saved, array( $to ) ); }
				},
			) );
		} );
		return ! empty( $result['success'] ) && 'assigned' === ( $result['new_state'] ?? '' );
	}

	/**
	 * La cadena (no el mensajero) le quita la carrera al que la tiene: vence el reloj, la gestora la pasa al grupo o
	 * a otro, o la administración la reasigna. $handoff: va directo a otro mensajero (no se anuncia en el grupo).
	 * → id del mensajero que la tenía (0 si no se pudo).
	 */
	public static function system_release( WC_Order $order, string $kind, string $why, bool $handoff, string $by = '' ): int {
		$holder = self::holder_id( $order );
		$status = CVD_Delivery::status( $order );
		if ( ! $holder || ! in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ) { return 0; }
		$held = self::held_seconds( $order );
		$result = self::as_system( static function ( int $actor ) use ( $order, $holder, $kind, $why, $handoff, $by, $held ) {
			return CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'offered', array(
				'actor_user_id' => $actor, 'source' => 'cvd_cadena_mensajeros', 'chain_system' => true,
				'metadata' => array( 'messenger' => $holder, 'release' => true, 'chain' => $kind ),
				'precondition' => static function ( WC_Order $locked, string $current ) use ( $holder ) {
					if ( 'yes' === $locked->get_meta( '_cvd_delivery_incident_active', true ) ) { return CVD_Order_Transition_Service::PRECONDITION_FAILED; }
					return in_array( $current, array( 'assigned', 'accepted', 'to_store' ), true ) && $holder === absint( $locked->get_meta( '_cvd_messenger_user_id', true ) ) ? true : CVD_Order_Transition_Service::CONFLICT;
				},
				'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $who, string $at ) use ( $holder, $kind, $why, $handoff, $by, $held ): void {
					foreach ( array( '_cvd_messenger_user_id', '_cvd_direct_assignment', '_cvd_delivery_accepted_at', '_cvd_to_store_at', '_cvd_bot_eta', '_cvd_bot_eta_at', self::CLOCK_META, self::REMINDED_META, self::HOLD_META ) as $key ) { $locked->delete_meta_data( $key ); }
					$locked->update_meta_data( '_cvd_delivery_offered_at', $at );
					$invited = array_map( 'absint', (array) $locked->get_meta( '_cvd_delivery_invited_messengers', true ) );
					$available = array_map( static fn( WP_User $user ): int => $user->ID, CVD_Delivery::available_messengers() );
					$locked->update_meta_data( '_cvd_delivery_invited_messengers', array_values( array_unique( array_merge( $invited, $available ) ) ) );
					if ( $handoff ) { $locked->update_meta_data( self::HANDOFF_META, $at ); } else { $locked->delete_meta_data( self::HANDOFF_META ); $locked->update_meta_data( self::STAGE_META, 'group' ); }
					$name = ( $u = get_userdata( $holder ) ) ? $u->display_name : '#' . $holder;
					$locked->add_order_note( 'Cadena de mensajeros: la carrera deja de ser de ' . $name . ' (' . $why . ').' . ( $handoff ? '' : ' Sale al grupo de mensajeros.' ) );
					self::log( $locked, $kind, $holder, $held, $by );
				},
				'after_commit' => static function () use ( $holder ): void {
					if ( class_exists( 'CVD_Messenger_Reputation' ) ) { CVD_Messenger_Reputation::invalidate( $holder ); }
				},
			) );
		} );
		return ! empty( $result['success'] ) ? $holder : 0;
	}

	/** Si la entrega de mensajero a mensajero falla, queda en el grupo (nunca sin nadie). */
	private static function handoff_failed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) { return; }
		$order->delete_meta_data( self::HANDOFF_META );
		$order->update_meta_data( self::STAGE_META, 'group' );
		$order->add_order_note( 'Cadena de mensajeros: no se pudo pasar directo al nuevo mensajero; queda en el grupo.' );
		$order->save();
	}

	// ------------------------------------------------------------------ Órdenes (administración, gestora, mensajero)

	/**
	 * Reasignar (administración: Ernesto, Lennys, dependienta; el bot comprueba quién manda la orden).
	 * Suelta a quien la tenga, la asigna a $to y, con $accept, la deja aceptada. → result: done | same | preferred |
	 * too_late | error, from, to.
	 */
	public static function reassign( WC_Order $order, WP_User $to, string $by, bool $accept = true, string $stage = 'admin' ): array {
		$status = CVD_Delivery::status( $order );
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return array( 'result' => 'pickup' ); }
		if ( ! in_array( $status, self::OPEN_DELIVERY, true ) ) { return array( 'result' => 'too_late', 'status' => $status ); }
		$holder = self::holder_id( $order );
		$from = $holder ? self::user( $holder ) : null;
		$by_text = '' !== $by ? $by : 'la tienda';
		if ( $holder === $to->ID ) {
			if ( $accept && 'assigned' === $status ) { CVD_Delivery::accept_assigned( $order, $to->ID, 'cvd_cadena_admin', 'por orden de ' . $by_text ); }
			return array( 'result' => 'same', 'to' => $to->display_name );
		}
		if ( 'unassigned' === $status ) {
			$order->update_meta_data( CVD_Direct_Dispatch::PREFERRED_META, $to->ID );
			$order->update_meta_data( CVD_Direct_Dispatch::SOURCE_META, 'admin' === $stage ? 'admin' : 'gestora' );
			$order->add_order_note( 'Cadena de mensajeros: ' . $by_text . ' eligió a ' . $to->display_name . ' (1.º). Se le asigna directo al pulsar «Listo para salir».' );
			$order->save();
			// La gestora solo lo deja elegido (sale al pulsar «Listo»). La administración, si ya está listo, lo asigna ya.
			if ( 'admin' !== $stage || 'ready' !== sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ) { return array( 'result' => 'preferred', 'to' => $to->display_name ); }
			$fresh = wc_get_order( $order->get_id() );
			$ok = $fresh && self::as_system( static fn() => CVD_Delivery::assign_direct( $fresh, $to ) );
			if ( ! $ok ) { return array( 'result' => 'preferred', 'to' => $to->display_name ); }
		} else {
			if ( $holder && ! self::system_release( $order, 'admin' === $stage ? 'reasignada' : 'cambio_gestora', 'la pasó ' . $by_text . ' a ' . $to->display_name, true, $by_text ) ) { return array( 'result' => 'error', 'code' => 'release' ); }
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || ! self::assign_from_offered( $fresh, $to, $stage, 'Cadena de mensajeros: ' . $by_text . ' asignó la carrera a ' . $to->display_name . '.' ) ) {
				self::handoff_failed( $order->get_id() );
				return array( 'result' => 'error', 'code' => 'assign', 'from' => $from ? $from->display_name : '' );
			}
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( $accept && $fresh && 'assigned' === CVD_Delivery::status( $fresh ) ) {
			CVD_Delivery::accept_assigned( $fresh, $to->ID, 'cvd_cadena_admin', 'por orden de ' . $by_text );
			$fresh = wc_get_order( $order->get_id() );
		}
		if ( $fresh && $from ) { self::queue_lost( $fresh, $from->ID, $by_text ); }
		return array( 'result' => 'done', 'from' => $from ? $from->display_name : '', 'to' => $to->display_name, 'status' => $fresh ? CVD_Delivery::status( $fresh ) : '' );
	}

	/** Pasar al grupo ya (gestora o administración). → result: group | already | too_late | error. */
	public static function to_group( WC_Order $order, string $by ): array {
		$status = CVD_Delivery::status( $order );
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return array( 'result' => 'pickup' ); }
		if ( 'offered' === $status ) { return array( 'result' => 'already' ); }
		if ( ! in_array( $status, self::OPEN_DELIVERY, true ) ) { return array( 'result' => 'too_late', 'status' => $status ); }
		if ( 'unassigned' === $status ) {
			$order->delete_meta_data( CVD_Direct_Dispatch::PREFERRED_META );
			$order->delete_meta_data( CVD_Direct_Dispatch::SOURCE_META );
			$order->update_meta_data( self::STAGE_META, 'group' );
			$order->add_order_note( 'Cadena de mensajeros: ' . $by . ' pidió que salga al grupo de mensajeros.' );
			$order->save();
			if ( 'ready' !== sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ) { return array( 'result' => 'group_later' ); }
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || CVD_Delivery::supplier_hold( $fresh ) ) { return array( 'result' => 'supplier' ); } // producto de proveedor: lo decide la tienda
			$ok = self::as_system( static fn() => CVD_Delivery::publish_offer( $fresh ) );
			return array( 'result' => $ok ? 'group' : 'error' );
		}
		$holder = self::system_release( $order, 'al_grupo', 'lo pidió ' . $by, false, $by );
		if ( ! $holder ) { return array( 'result' => 'error' ); }
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) { self::queue_lost( $fresh, $holder, $by ); }
		return array( 'result' => 'group', 'from' => ( $u = self::user( $holder ) ) ? $u->display_name : '' );
	}

	/**
	 * Lo que pide la gestora (Ventas o el bot): 1.º, 2.º y «también al grupo». $first / $backup: id, 0 = quitar,
	 * null = no tocar. Si el pedido ya está asignado a otro y cambia el 1.º, pasa al nuevo al instante.
	 */
	public static function apply_chain( WC_Order $order, string $by, ?int $first, ?int $backup, ?bool $group_too ): array {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return array( 'result' => 'pickup' ); }
		$status = CVD_Delivery::status( $order );
		if ( ! in_array( $status, self::OPEN_DELIVERY, true ) || in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return array( 'result' => 'too_late', 'status' => $status ); }
		$notes = array();
		if ( null !== $backup ) {
			$b = $backup ? self::valid_messenger( $backup ) : null;
			if ( $backup && ! $b ) { return array( 'result' => 'not_messenger' ); }
			$prev = absint( $order->get_meta( self::BACKUP_META, true ) );
			if ( $b && $prev !== $b->ID ) { $order->update_meta_data( self::BACKUP_META, $b->ID ); $notes[] = '2.º (respaldo): ' . $b->display_name; }
			if ( ! $b && $prev ) { $order->delete_meta_data( self::BACKUP_META ); $notes[] = 'sin 2.º'; }
		}
		if ( null !== $group_too && $group_too !== self::group_too( $order ) ) {
			if ( $group_too ) { $order->update_meta_data( self::GROUP_TOO_META, '1' ); $notes[] = 'también al grupo'; } else { $order->delete_meta_data( self::GROUP_TOO_META ); $notes[] = 'solo su mensajero'; }
		}
		if ( $notes ) { $order->add_order_note( 'Cadena de mensajeros (' . $by . '): ' . implode( ', ', $notes ) . '.' ); }
		$order->save();
		$order = wc_get_order( $order->get_id() );
		if ( null === $first ) { return array( 'result' => 'saved' ); }
		if ( ! $first ) { return self::to_group( $order, $by ); }
		$to = self::valid_messenger( $first );
		if ( ! $to ) { return array( 'result' => 'not_messenger' ); }
		$order->update_meta_data( CVD_Direct_Dispatch::PREFERRED_META, $to->ID );
		$order->update_meta_data( CVD_Direct_Dispatch::SOURCE_META, 'gestora' );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		if ( 'offered' === $status && self::group_too( $order ) ) {
			// En los dos: sigue en el grupo y a él se le avisa por privado (gana el primero que acepte).
			self::queue_invite( $order, $to, 'prioridad' );
			return array( 'result' => 'invited', 'to' => $to->display_name );
		}
		if ( 'offered' === $status ) {
			$ok = self::assign_from_offered( $order, $to, 'first', 'Cadena de mensajeros: ' . $by . ' lo pasó del grupo a ' . $to->display_name . ' (1.º).' );
			return array( 'result' => $ok ? 'done' : 'error', 'to' => $to->display_name );
		}
		return self::reassign( $order, $to, $by, false, 'first' );
	}

	/**
	 * «Yo» de un mensajero (CVD_Bot_Bridge::claim_by_messenger) sobre una carrera ASIGNADA y sin aceptar:
	 *  - el que la tiene: queda aceptada (null: sigue el «yours» de siempre);
	 *  - el 2.º abierto a la vez a los 10 min: gana él (true) — el que la tenía recibe su «ya no es tuya»;
	 *  - cualquier otro: false (ya está cogida).
	 */
	public static function claim_assigned( WC_Order $order, WP_User $messenger ): ?bool {
		if ( 'assigned' !== CVD_Delivery::status( $order ) ) { return null; }
		$holder = self::holder_id( $order );
		if ( $holder === $messenger->ID ) {
			CVD_Delivery::accept_assigned( $order, $messenger->ID, 'cvd_bot_bridge_claim', 'con su «Yo»' );
			return null;
		}
		if ( ! self::on() || absint( $order->get_meta( self::INVITED_META, true ) ) !== $messenger->ID ) { return false; }
		if ( ! self::system_release( $order, 'gano_respaldo', 'la aceptó antes ' . $messenger->display_name . ', el 2.º', true, $messenger->display_name ) ) { return false; }
		$fresh = wc_get_order( $order->get_id() );
		// Él ya tiene el vale (lo recibe con el «Yo»): el de /bot/dispatch/direct no se repite.
		if ( ! $fresh || ! self::assign_from_offered( $fresh, $messenger, 'backup', 'Cadena de mensajeros: ' . $messenger->display_name . ' (2.º) aceptó antes que el 1.º.', false, true ) ) {
			self::handoff_failed( $order->get_id() );
			return false;
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) { CVD_Delivery::accept_assigned( $fresh, $messenger->ID, 'cvd_bot_bridge_claim', 'con su «Yo» (era el 2.º)' ); }
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) { self::queue_lost( $fresh, $holder, '' ); }
		return true;
	}

	/** Modo «en los dos» al pulsar «Listo»: sale al grupo y al 1.º (y al 2.º) se les avisa por privado. */
	public static function invite_priority( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) { return; }
		$order->update_meta_data( self::STAGE_META, 'group' );
		$order->save();
		$first = CVD_Direct_Dispatch::preferred_messenger( $order );
		if ( $first ) { self::queue_invite( $order, $first, 'prioridad' ); }
		$backup = self::backup( $order );
		if ( $backup && ( ! $first || $backup->ID !== $first->ID ) ) { self::queue_invite( $order, $backup, 'prioridad' ); }
	}

	// ------------------------------------------------------------------ Reloj (cada minuto)

	public static function tick(): void {
		if ( ! self::on() || ! class_exists( 'CVD_Delivery' ) ) { return; }
		if ( get_transient( 'cvd_cadena_tick_lock' ) ) { return; }
		set_transient( 'cvd_cadena_tick_lock', 1, 45 );
		if ( self::quiet_now() ) { return; }
		$orders = wc_get_orders( array( 'limit' => 60, 'type' => 'shop_order', 'status' => array( 'pending', 'processing', 'on-hold' ), 'meta_key' => self::CLOCK_META, 'meta_value' => '1' ) );
		foreach ( $orders as $order ) {
			try { self::tick_order( $order ); } catch ( Throwable $error ) { unset( $error ); }
		}
	}

	public static function tick_order( WC_Order $order, ?int $now = null ): string {
		$now = $now ?? time();
		if ( 'assigned' !== CVD_Delivery::status( $order ) ) {
			$order->delete_meta_data( self::CLOCK_META );
			$order->save();
			return 'stopped';
		}
		$holder = self::user( self::holder_id( $order ) );
		$assigned_at = self::utc_ts( (string) $order->get_meta( '_cvd_direct_assigned_at', true ) );
		if ( ! $holder || ! $assigned_at ) { return 'skip'; }
		$age = $now - $assigned_at;
		$num = $order->get_order_number();
		if ( $age >= self::group_min() * 60 ) {
			$minutes = self::group_min();
			if ( ! self::system_release( $order, 'sin_respuesta', 'no aceptó en ' . $minutes . ' min', false, 'reloj' ) ) { return 'error'; }
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh ) {
				self::queue_lost( $fresh, $holder->ID, '', '⏱️ Pasaron ' . $minutes . ' min sin que aceptaras la carrera *#' . $num . '*: salió al grupo de mensajeros. Ya no es tuya; no tienes que hacer nada con ese pedido.' );
				self::queue_gestora( $fresh, 'grupo', '⏱️ *#' . $num . '*: ' . self::first_name( $holder->display_name ) . ' no aceptó en ' . $minutes . ' min. Lo pasé al grupo de mensajeros; te aviso quién lo coge.' );
			}
			return 'group';
		}
		if ( $age >= self::remind_min() * 60 && absint( $order->get_meta( self::REMINDED_META, true ) ) < $assigned_at ) {
			$order->update_meta_data( self::REMINDED_META, $now );
			$backup = self::backup( $order );
			$stage = sanitize_key( (string) $order->get_meta( self::STAGE_META, true ) );
			$open_backup = $backup && $backup->ID !== $holder->ID && 'backup' !== $stage;
			if ( $open_backup ) { $order->update_meta_data( self::INVITED_META, $backup->ID ); }
			$order->save();
			$order = wc_get_order( $order->get_id() );
			$left = self::group_min() - self::remind_min();
			self::queue( $order, 'recordatorio', 'messenger', $holder->ID, implode( "\n", array(
				'⏰ *Tienes la carrera #' . $num . '* (' . CVD_Delivery::destination_zone( $order ) . ') y todavía no la aceptaste.',
				'Responde con la *hora* a la que puedes ir, o «suelto» si no puedes.',
				$open_backup ? 'Ya se la ofrecí también a otro mensajero: el primero que acepte la lleva.' : 'Si en ' . $left . ' min no la aceptas, sale al grupo.',
			) ) );
			if ( $open_backup ) { self::queue_invite( $order, $backup, 'respaldo' ); }
			self::queue_gestora( $order, 'espera', '⏰ *#' . $num . '*: ' . self::first_name( $holder->display_name ) . ' todavía no acepta (' . self::remind_min() . ' min). '
				. ( $open_backup ? 'Se lo ofrecí también a ' . self::first_name( $backup->display_name ) . ' (tu 2.º): el primero que acepte lo lleva.' : 'Si en ' . $left . ' min nadie acepta, sale al grupo solo.' )
				. ' Puedes cambiar el mensajero en Ventas.' );
			return 'reminded';
		}
		return 'wait';
	}

	// ------------------------------------------------------------------ Avisos (60 s estables, uno por persona)

	private static function gestora_phone( WC_Order $order ): string {
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return ''; }
		$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		return $owner ? self::phone_of( $owner ) : '';
	}

	/** Apunta un aviso. Sustituye al que esperaba para la misma persona en este pedido. */
	public static function queue( WC_Order $order, string $kind, string $role, int $user_id, string $body, string $phone = '', array $extra = array() ): bool {
		$phone = '' !== $phone ? preg_replace( '/\D+/', '', $phone ) : ( $user_id ? self::phone_of( $user_id ) : '' );
		if ( strlen( $phone ) < 8 || '' === trim( $body ) ) { return false; }
		$list = $order->get_meta( self::AVISOS_META, true );
		$list = is_array( $list ) ? $list : array();
		foreach ( $list as &$item ) {
			if ( 'pending' === ( $item['state'] ?? '' ) && substr( (string) $item['phone'], -8 ) === substr( $phone, -8 ) ) { $item['state'] = 'superseded'; }
		}
		unset( $item );
		$user = self::user( $user_id );
		$list[] = array_merge( array(
			'key' => strtolower( wp_generate_password( 10, false, false ) ), 'kind' => $kind, 'role' => $role, 'user' => $user_id,
			'phone' => strlen( $phone ) === 8 ? '53' . $phone : $phone, 'name' => $user ? $user->display_name : '', 'body' => $body,
			'holder' => self::holder_id( $order ), 'at' => time(), 'due' => time() + self::stable_seconds(), 'state' => 'pending',
		), $extra );
		$order->update_meta_data( self::AVISOS_META, array_slice( $list, -30 ) );
		$order->update_meta_data( self::PENDING_META, '1' );
		$order->save();
		return true;
	}

	/** «Ya no es tuya» al que la tenía (sale solo si a los 60 s sigue sin ser suya). */
	public static function queue_lost( WC_Order $order, int $user_id, string $by, string $body = '' ): void {
		if ( ! $user_id ) { return; }
		$num = $order->get_order_number();
		if ( '' === $body ) {
			$holder = self::user( self::holder_id( $order ) );
			$body = 'ℹ️ La carrera *#' . $num . '* ya no es tuya' . ( '' !== $by && 'reloj' !== $by ? ' (la cambió ' . $by . ')' : '' ) . ( $holder ? ': ahora la lleva ' . self::first_name( $holder->display_name ) : '' ) . '. No tienes que hacer nada con ese pedido.';
		}
		self::queue( $order, 'perdida', 'messenger', $user_id, $body );
	}

	private static function queue_gestora( WC_Order $order, string $kind, string $body ): void {
		$phone = self::gestora_phone( $order );
		if ( '' === $phone ) { return; }
		self::queue( $order, $kind, 'gestora', absint( $order->get_meta( '_cvd_owner_user_id', true ) ), $body, $phone );
	}

	/** Invitación por privado: «¿Puedes llevarla? Responde Yo» (2.º a los 10 min, o 1.º/2.º en «en los dos»). */
	private static function queue_invite( WC_Order $order, WP_User $to, string $why ): void {
		$gestora = class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::gestora_name( $order ) : '';
		$earning = CVD_Delivery::courier_amount( $order );
		$lines = array(
			'🛵 *¿Puedes llevar la carrera #' . $order->get_order_number() . '?*',
			'Zona: ' . CVD_Delivery::destination_zone( $order ) . ( $earning > 0 ? ' · ganas ' . number_format_i18n( $earning, 0 ) . ' CUP' : '' ),
		);
		if ( class_exists( 'CVD_Order_Lines' ) ) { $lines[] = implode( "\n", array_slice( (array) CVD_Order_Lines::texts( $order ), 0, 4 ) ); }
		$lines[] = 'respaldo' === $why
			? ( '' !== $gestora ? $gestora . ' te puso de respaldo. ' : '' ) . 'El primero no la ha aceptado: si la quieres, responde «Yo». El primero que acepte la lleva.'
			: ( '' !== $gestora ? $gestora . ' te eligió. ' : '' ) . 'También está en el grupo: responde «Yo» y es tuya (gana el primero que acepte).';
		self::queue( $order, 'invitacion', 'messenger', $to->ID, implode( "\n", array_filter( $lines ) ), '', array( 'why' => $why ) );
	}

	/** ¿Sigue siendo verdad el aviso? Si no, no sale (así nunca «ya no es tuya» y luego «sí es tuya»). */
	private static function still_true( array $item, WC_Order $order ): bool {
		if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return false; }
		$status = CVD_Delivery::status( $order );
		$holder = self::holder_id( $order );
		$user = absint( $item['user'] ?? 0 );
		switch ( $item['kind'] ?? '' ) {
			case 'perdida': return $holder !== $user;
			case 'recordatorio': return 'assigned' === $status && $holder === $user;
			case 'invitacion':
				if ( 'prioridad' === ( $item['why'] ?? '' ) ) { return 'offered' === $status && ! $holder; }
				return 'assigned' === $status && $holder !== $user && absint( $order->get_meta( self::INVITED_META, true ) ) === $user;
			case 'grupo': return 'offered' === $status && ! $holder;
			case 'espera': return 'assigned' === $status && $holder === absint( $item['holder'] ?? 0 );
		}
		return true;
	}

	/** GET /bot/cadena-avisos: avisos con 60 s estables, el texto listo. ?test=1 incluye los de pruebas. */
	public static function avisos_request( WP_REST_Request $request ): WP_REST_Response {
		self::tick();
		$with_test = (bool) $request->get_param( 'test' );
		$orders = wc_get_orders( array( 'limit' => 50, 'type' => 'shop_order', 'meta_key' => self::PENDING_META, 'meta_value' => '1' ) );
		$out = array();
		$now = time();
		foreach ( $orders as $order ) {
			$list = $order->get_meta( self::AVISOS_META, true );
			$list = is_array( $list ) ? $list : array();
			$changed = false;
			$pending = false;
			$test = self::is_test( $order );
			foreach ( $list as &$item ) {
				if ( 'pending' !== ( $item['state'] ?? '' ) ) { continue; }
				if ( ! self::still_true( $item, $order ) ) { $item['state'] = 'stale'; $changed = true; continue; }
				$pending = true;
				if ( (int) $item['due'] > $now || ( $test && ! $with_test ) ) { continue; }
				$out[] = array(
					'id' => $order->get_id(), 'key' => $item['key'], 'order' => $order->get_order_number(), 'kind' => $item['kind'], 'role' => $item['role'],
					'phone' => $item['phone'], 'name' => $item['name'], 'body' => $item['body'], 'test' => $test, 'at' => gmdate( 'c', (int) $item['at'] ),
				);
			}
			unset( $item );
			if ( ! $pending ) { $order->delete_meta_data( self::PENDING_META ); $changed = true; }
			if ( $changed ) { $order->update_meta_data( self::AVISOS_META, $list ); $order->save(); }
		}
		return self::no_cache( array( 'avisos' => $out, 'on' => self::on() ) );
	}

	public static function aviso_done_request( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$key = sanitize_key( (string) $request['key'] );
		$list = $order->get_meta( self::AVISOS_META, true );
		$list = is_array( $list ) ? $list : array();
		$found = false;
		foreach ( $list as &$item ) {
			if ( ( $item['key'] ?? '' ) === $key ) { $item['state'] = 'sent'; $item['sent'] = time(); $found = true; }
		}
		unset( $item );
		if ( $found ) { $order->update_meta_data( self::AVISOS_META, $list ); $order->save(); }
		return self::no_cache( array( 'ok' => $found ) );
	}

	// ------------------------------------------------------------------ Disponibilidad (F)

	/** Señal de vida del mensajero (Pa'llá o WhatsApp). Como mucho una escritura por minuto. */
	public static function seen( int $user_id, ?int $at = null ): void {
		$at = $at ?? time();
		if ( $user_id && $at - absint( get_user_meta( $user_id, self::SEEN_META, true ) ) >= 60 ) { update_user_meta( $user_id, self::SEEN_META, $at ); }
	}

	/** «conectó hoy 9:40» · «sin conexión desde ayer» · «sin conexión desde el 8/11» · '' (sin datos). */
	public static function seen_label( int $user_id ): string {
		$at = absint( get_user_meta( $user_id, self::SEEN_META, true ) );
		if ( ! $at ) { return ''; }
		$tz = new DateTimeZone( 'America/Havana' );
		$then = ( new DateTime( '@' . $at ) )->setTimezone( $tz );
		$today = new DateTime( 'now', $tz );
		$days = (int) ( new DateTime( $today->format( 'Y-m-d' ), $tz ) )->diff( new DateTime( $then->format( 'Y-m-d' ), $tz ) )->days;
		if ( 0 === $days ) { return 'conectó hoy ' . $then->format( 'G:i' ); }
		return 1 === $days ? 'sin conexión desde ayer' : 'sin conexión desde el ' . $then->format( 'j/n' );
	}

	public static function messenger_option_label( WP_User $messenger ): string {
		$bits = array();
		if ( 'yes' !== get_user_meta( $messenger->ID, '_cvd_messenger_available', true ) ) { $bits[] = 'no disponible'; }
		$seen = self::seen_label( $messenger->ID );
		if ( '' !== $seen ) { $bits[] = $seen; }
		return $messenger->display_name . ( $bits ? ' · ' . implode( ' · ', $bits ) : '' );
	}

	// ------------------------------------------------------------------ Ventas de la gestora (B)

	/** Celda «Mensajero que lo lleva» con la cadena: 1.º, 2.º, «también al grupo» y «Pasar al grupo ya». */
	public static function gestora_cell( WC_Order $order ): string {
		static $messengers = null;
		$status = CVD_Delivery::status( $order );
		$holder = self::user( self::holder_id( $order ) );
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return '<small>Recoge en tienda</small>'; }
		$closed = in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true );
		if ( $closed || ! in_array( $status, self::OPEN_DELIVERY, true ) ) {
			return $holder ? esc_html( $holder->display_name ) : '<small>—</small>';
		}
		if ( null === $messengers ) { $messengers = CVD_Direct_Dispatch::approved_messengers(); }
		$first = absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) );
		$backup = absint( $order->get_meta( self::BACKUP_META, true ) );
		if ( 'assigned' === $status && $holder ) {
			$minutes = max( 0, (int) floor( ( time() - self::utc_ts( (string) $order->get_meta( '_cvd_direct_assigned_at', true ) ) ) / 60 ) );
			$now = 'Asignado a <b>' . esc_html( $holder->display_name ) . '</b>, sin aceptar (' . $minutes . ' min)';
		} elseif ( $holder ) {
			$now = 'Lo lleva <b>' . esc_html( $holder->display_name ) . '</b> (aceptó)';
		} elseif ( 'offered' === $status ) {
			$now = 'En el grupo de mensajeros';
		} else {
			$now = 'Todavía no salió de la tienda';
		}
		$options = static function ( int $current, string $none ) use ( $messengers ): string {
			$html = '<option value="0">' . esc_html( $none ) . '</option>';
			foreach ( $messengers as $messenger ) {
				$html .= '<option value="' . esc_attr( $messenger->ID ) . '"' . selected( $current, $messenger->ID, false ) . '>' . esc_html( self::messenger_option_label( $messenger ) ) . '</option>';
			}
			return $html;
		};
		$id = $order->get_id();
		$html = '<div class="cvd-cadena"><small>' . $now . '</small>'
			. '<form class="cvd-preferred-messenger" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cvd_gestora_cadena"><input type="hidden" name="order_id" value="' . esc_attr( $id ) . '">'
			. wp_nonce_field( 'cvd_cadena_' . $id, '_wpnonce', true, false )
			. '<label>1.º mensajero<select name="first">' . $options( $first, 'Cualquiera (grupo)' ) . '</select></label>'
			. '<label>2.º (si el 1.º no puede)<select name="backup">' . $options( $backup, 'Ninguno' ) . '</select></label>'
			. '<label class="cvd-check"><input type="checkbox" name="group_too" value="1"' . checked( self::group_too( $order ), true, false ) . '> También al grupo (el primero que acepte lo lleva)</label>'
			. '<button class="cvd-secondary" type="submit" name="do" value="guardar">Guardar</button>';
		if ( 'offered' !== $status ) { $html .= ' <button class="cvd-secondary" type="submit" name="do" value="grupo">Pasar al grupo ya</button>'; }
		return $html . '</form></div>';
	}

	public static function gestora_notice(): string {
		if ( ! isset( $_GET['cadena'] ) ) { return ''; }
		$messages = array(
			'saved'       => 'Listo: guardado. Si el 1.º no acepta en ' . self::remind_min() . ' min te aviso; a los ' . self::group_min() . ' min sale al grupo solo.',
			'preferred'   => 'Listo: cuando la tienda pulse «Listo para salir», el pedido se le asigna directo a tu 1.º mensajero.',
			'done'        => 'Listo: el pedido ya es de tu nuevo mensajero. Le llega el vale por WhatsApp y al anterior le aviso que ya no es suyo.',
			'same'        => 'Ese mensajero ya lleva el pedido.',
			'invited'     => 'Listo: sigue en el grupo y a tu mensajero le aviso por privado. El primero que acepte lo lleva.',
			'group'       => 'Listo: el pedido salió al grupo de mensajeros. Te aviso quién lo coge.',
			'group_later' => 'Listo: saldrá al grupo cuando la tienda pulse «Listo para salir».',
			'already'     => 'El pedido ya está en el grupo de mensajeros.',
			'supplier'    => 'Ese pedido tiene un producto de proveedor: la entrega la coordina la tienda. Escríbele a Casa Viva.',
			'pickup'      => 'Ese pedido se recoge en la tienda: no lleva mensajero.',
			'not_messenger' => 'Ese mensajero ya no está aprobado. Elige otro.',
		);
		$key = sanitize_key( wp_unslash( $_GET['cadena'] ) );
		return '<div class="cvd-notice" role="status">' . esc_html( $messages[ $key ] ?? 'No se pudo guardar. Puede que el mensajero ya haya recogido el paquete o que ese mensajero ya no esté aprobado.' ) . '</div>';
	}

	public static function save_from_gestora(): void {
		$user = wp_get_current_user();
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $user->exists() || ! CVD_Registration::is_approved_gestora( $user ) ) { wp_die( 'No tienes permiso para cambiar este pedido.', 403 ); }
		check_admin_referer( 'cvd_cadena_' . $order_id );
		$order = wc_get_order( $order_id );
		$result = 'error';
		if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && $user->ID === absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ) {
			$by = self::first_name( $user->display_name ) . ' (gestor/a)';
			if ( 'grupo' === sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) {
				$r = self::to_group( $order, $by );
			} else {
				$first = absint( $_POST['first'] ?? 0 );
				$same_first = $first === absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) ) && ( ! $first || $first === self::holder_id( $order ) || 'unassigned' === CVD_Delivery::status( $order ) );
				$r = self::apply_chain( $order, $by, $same_first ? null : $first, absint( $_POST['backup'] ?? 0 ), ! empty( $_POST['group_too'] ) );
				if ( 'saved' === ( $r['result'] ?? '' ) && $first && 'unassigned' === CVD_Delivery::status( $order ) ) { $r['result'] = 'preferred'; }
			}
			$result = sanitize_key( (string) ( $r['result'] ?? 'error' ) );
		}
		wp_safe_redirect( add_query_arg( 'cadena', $result, home_url( '/area-gestoras/' ) ) . '#ventas' );
		exit;
	}

	// ------------------------------------------------------------------ REST para VivaBot

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/cadena-avisos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'avisos_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena-avisos/(?P<id>\d+)/(?P<key>[a-z0-9]+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'aviso_done_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'state_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/(?P<id>\d+)/asignar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/(?P<id>\d+)/grupo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'group_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/(?P<id>\d+)/cambiar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'change_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/resumen', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'summary_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/cadena/ajustes', array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'settings_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/mensajeros/visto', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'seen_request' ), 'permission_callback' => $auth ) );
	}

	private static function order_of( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? $order : new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}

	/** Mensajero por teléfono (8+ dígitos), id o nombre («Lázaro», «Félix Alexander»). */
	public static function messenger_from( string $value ): ?WP_User {
		$value = trim( $value );
		if ( '' === $value ) { return null; }
		$digits = preg_replace( '/\D+/', '', $value );
		if ( strlen( $digits ) >= 8 ) { return CVD_Bot_Bridge::messenger_by_phone( $digits ); }
		if ( ctype_digit( $value ) ) { return self::valid_messenger( (int) $value ); }
		return CVD_Direct_Dispatch::messenger_in_note( 'mensajero: ' . $value );
	}

	/**
	 * Quién manda la orden. as=gestora: el bot pasa su teléfono y el Core comprueba que el pedido es suyo.
	 * as=admin: el bot ya comprobó que es Ernesto, Lennys o la dependienta de turno (la clave del bot lo respalda).
	 */
	private static function who( WP_REST_Request $request, WC_Order $order ) {
		$as = sanitize_key( (string) $request->get_param( 'as' ) ) ?: 'gestora';
		$by = sanitize_text_field( (string) $request->get_param( 'by' ) );
		if ( 'admin' === $as ) { return '' !== $by ? $by : 'la tienda'; }
		$gestora = CVD_Bot_Bridge::gestora_by_phone( (string) $request->get_param( 'phone' ) );
		if ( ! $gestora || $gestora->ID !== absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ) {
			return new WP_Error( 'cvd_not_owner', 'Ese pedido no es de esa gestora.', array( 'status' => 403 ) );
		}
		return self::first_name( $gestora->display_name ) . ' (gestor/a)';
	}

	public static function state( WC_Order $order ): array {
		$holder = self::user( self::holder_id( $order ) );
		$first = CVD_Direct_Dispatch::preferred_messenger( $order );
		$backup = self::backup( $order );
		$assigned_at = self::utc_ts( (string) $order->get_meta( '_cvd_direct_assigned_at', true ) );
		$log = $order->get_meta( self::LOG_META, true );
		return array(
			'id' => $order->get_id(), 'status' => CVD_Delivery::status( $order ), 'label' => CVD_Delivery::label( CVD_Delivery::status( $order ) ),
			'holder' => $holder ? $holder->display_name : '', 'holderPhone' => $holder ? self::phone_of( $holder->ID ) : '',
			'first' => $first ? $first->display_name : '', 'backup' => $backup ? $backup->display_name : '', 'groupToo' => self::group_too( $order ),
			'stage' => (string) $order->get_meta( self::STAGE_META, true ), 'clock' => '1' === (string) $order->get_meta( self::CLOCK_META, true ),
			'minutesWaiting' => $assigned_at && 'assigned' === CVD_Delivery::status( $order ) ? (int) floor( ( time() - $assigned_at ) / 60 ) : null,
			'remindMin' => self::remind_min(), 'groupMin' => self::group_min(),
			'log' => is_array( $log ) ? array_map( static function ( array $e ): array { $u = get_userdata( (int) $e['messenger'] ); return $e + array( 'name' => $u ? $u->display_name : '', 'when' => gmdate( 'c', (int) $e['t'] ) ); }, $log ) : array(),
		);
	}

	public static function state_request( WP_REST_Request $request ) {
		$order = self::order_of( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messengers = array_map( static fn( WP_User $m ): array => array( 'id' => $m->ID, 'name' => $m->display_name, 'available' => 'yes' === get_user_meta( $m->ID, '_cvd_messenger_available', true ), 'seen' => self::seen_label( $m->ID ) ), CVD_Direct_Dispatch::approved_messengers() );
		return self::no_cache( self::state( $order ) + array( 'messengers' => $messengers ) );
	}

	/** POST /bot/cadena/{id}/asignar {to, by, accept=1}: «#asignar N nombre» de la administración. */
	public static function assign_request( WP_REST_Request $request ) {
		$order = self::order_of( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$to = self::messenger_from( (string) $request->get_param( 'to' ) );
		if ( ! $to ) { return self::no_cache( array( 'result' => 'not_messenger' ) ); }
		$by = sanitize_text_field( (string) $request->get_param( 'by' ) ) ?: 'la tienda';
		$accept = null === $request->get_param( 'accept' ) || ! in_array( (string) $request->get_param( 'accept' ), array( '0', 'false', 'no' ), true );
		return self::no_cache( self::reassign( $order, $to, $by, $accept ) + array( 'state' => self::state( wc_get_order( $order->get_id() ) ) ) );
	}

	/** POST /bot/cadena/{id}/grupo {as, phone|by}: «pasa #N al grupo». */
	public static function group_request( WP_REST_Request $request ) {
		$order = self::order_of( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$by = self::who( $request, $order );
		if ( is_wp_error( $by ) ) { return $by; }
		return self::no_cache( self::to_group( $order, $by ) );
	}

	/** POST /bot/cadena/{id}/cambiar {as, phone|by, first, backup, groupToo}: «cambia #N a X» (nombre, teléfono o id; 0 = grupo). */
	public static function change_request( WP_REST_Request $request ) {
		$order = self::order_of( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$by = self::who( $request, $order );
		if ( is_wp_error( $by ) ) { return $by; }
		$pick = static function ( $value ): ?int {
			if ( null === $value ) { return null; }
			$value = trim( (string) $value );
			if ( in_array( strtolower( $value ), array( '', '0', 'grupo', 'ninguno', 'nadie' ), true ) ) { return 0; }
			$m = self::messenger_from( $value );
			return $m ? $m->ID : -1;
		};
		$first = $pick( $request->get_param( 'first' ) );
		$backup = $pick( $request->get_param( 'backup' ) );
		if ( -1 === $first || -1 === $backup ) { return self::no_cache( array( 'result' => 'not_messenger' ) ); }
		$group_too = $request->get_param( 'groupToo' );
		$r = self::apply_chain( $order, $by, $first, $backup, null === $group_too ? null : in_array( (string) $group_too, array( '1', 'true', 'si', 'sí' ), true ) );
		return self::no_cache( $r + array( 'state' => self::state( wc_get_order( $order->get_id() ) ) ) );
	}

	/** GET /bot/cadena/resumen?date=YYYY-MM-DD (hora de Cuba): sueltas, vencimientos y minutos hasta aceptar. */
	public static function summary_request( WP_REST_Request $request ): WP_REST_Response {
		$tz = new DateTimeZone( 'America/Havana' );
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request->get_param( 'date' ) ) ? (string) $request->get_param( 'date' ) : ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$from = ( new DateTime( $date . ' 00:00:00', $tz ) )->getTimestamp();
		$to = $from + DAY_IN_SECONDS;
		$log = get_option( self::LOG_OPTION, array() );
		$by_messenger = array();
		$orders = array();
		foreach ( is_array( $log ) ? $log : array() as $e ) {
			if ( (int) $e['t'] < $from || (int) $e['t'] >= $to ) { continue; }
			$id = (int) $e['messenger'];
			if ( ! isset( $by_messenger[ $id ] ) ) { $u = get_userdata( $id ); $by_messenger[ $id ] = array( 'name' => $u ? $u->display_name : '#' . $id, 'sueltas' => 0, 'sinRespuesta' => 0, 'cambiadas' => 0, 'minutosTenida' => array() ); }
			$kind = (string) $e['kind'];
			if ( 'suelta' === $kind ) { $by_messenger[ $id ]['sueltas']++; } elseif ( 'sin_respuesta' === $kind ) { $by_messenger[ $id ]['sinRespuesta']++; } else { $by_messenger[ $id ]['cambiadas']++; }
			$by_messenger[ $id ]['minutosTenida'][] = round( (int) $e['held'] / 60, 1 );
			$orders[ (int) $e['order'] ] = true;
		}
		$waits = array();
		foreach ( array_keys( $orders ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) { continue; }
			$first = absint( $order->get_meta( self::FIRST_AT_META, true ) );
			$accepted = self::utc_ts( (string) $order->get_meta( '_cvd_delivery_accepted_at', true ) );
			$waits[] = array( 'order' => $order->get_order_number(), 'minutosHastaAceptar' => $first && $accepted >= $first ? round( ( $accepted - $first ) / 60, 1 ) : null, 'status' => CVD_Delivery::status( $order ) );
		}
		return self::no_cache( array( 'date' => $date, 'mensajeros' => array_values( $by_messenger ), 'pedidos' => $waits ) );
	}

	/** GET/POST /bot/cadena/ajustes {on, avisoMin, grupoMin, estableSeg, silencio}: el bot copia aquí lo de crm_settings. */
	public static function settings_request( WP_REST_Request $request ): WP_REST_Response {
		if ( 'POST' === $request->get_method() ) {
			$map = array( 'avisoMin' => 'cvd_cadena_aviso_min', 'grupoMin' => 'cvd_cadena_grupo_min', 'estableSeg' => 'cvd_cadena_estable_seg' );
			foreach ( $map as $param => $option ) {
				if ( null !== $request->get_param( $param ) ) { update_option( $option, absint( $request->get_param( $param ) ), false ); }
			}
			if ( null !== $request->get_param( 'on' ) ) { update_option( 'cvd_cadena', in_array( (string) $request->get_param( 'on' ), array( '0', 'false', 'no' ), true ) ? 'no' : 'si', false ); }
			$quiet = (string) $request->get_param( 'silencio' );
			if ( preg_match( '/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $quiet ) ) { update_option( 'cvd_cadena_silencio', $quiet, false ); }
		}
		return self::no_cache( array( 'on' => self::on(), 'avisoMin' => self::remind_min(), 'grupoMin' => self::group_min(), 'estableSeg' => self::stable_seconds(), 'silencio' => (string) get_option( 'cvd_cadena_silencio', '22:00-07:30' ) ) );
	}

	/** POST /bot/mensajeros/visto {phones: [..]} o {phone}: el mensajero escribió por WhatsApp (para «conectó hoy»). */
	public static function seen_request( WP_REST_Request $request ): WP_REST_Response {
		$phones = (array) ( $request->get_param( 'phones' ) ?? array() );
		if ( $request->get_param( 'phone' ) ) { $phones[] = (string) $request->get_param( 'phone' ); }
		$count = 0;
		foreach ( array_slice( $phones, 0, 100 ) as $phone ) {
			$m = CVD_Bot_Bridge::messenger_by_phone( (string) $phone );
			if ( $m ) { self::seen( $m->ID ); $count++; }
		}
		return self::no_cache( array( 'ok' => true, 'messengers' => $count ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
