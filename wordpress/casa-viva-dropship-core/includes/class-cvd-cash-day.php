<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.76 (Ernesto 28-oct): «¿Lo recibiste hoy?» antes de contar el dinero de una entrega vieja.
 *
 * El 27-oct Nana marcó en 2 minutos el dinero de 8 entregas de días anteriores y el cuadre de VivaBot sumó al cajón de
 * ese día dinero que no estaba en su papel. Ahora, al registrar el dinero de una entrega a domicilio, la web pregunta si
 * la entrega tiene más de un día, o si es el cuarto cobro de una entrega de otro día que la misma persona marca en
 * 5 minutos. La respuesta queda en el pedido y VivaBot la lee (src/ventas-rules.js, cajonDelDia):
 *   _cvd_cash_received_today = yes | no · _cvd_cash_received_day = AAAA-MM-DD (día de Cuba en que entró el dinero)
 *   _cvd_cash_day_reason = tarde | rafaga | tarde,rafaga · _cvd_cash_day_by / _cvd_cash_day_at (GMT)
 * Las recogidas en tienda no preguntan: se entregan y se cobran en el mismo momento.
 */
final class CVD_Cash_Day {
	public const ANSWER = '_cvd_cash_received_today';
	public const DAY = '_cvd_cash_received_day';
	public const ERROR = 'cvd_cash_day_question';
	private const MARKS = '_cvd_cash_day_marks'; // usuario: [[timestamp, pedido], …] cobros de entregas de otro día
	private const WINDOW = 300; // 5 minutos
	private const BURST = 3; // con 3 ya marcados en la ventana, el siguiente pregunta («más de 3»)

	public static function tz(): DateTimeZone { return new DateTimeZone( 'America/Havana' ); }

	public static function day( int $ts ): string { return wp_date( 'Y-m-d', $ts, self::tz() ); }

	/** Cuándo se entregó la carrera (timestamp) o 0 si no consta. */
	public static function delivered_ts( WC_Order $order ): int {
		$at = (string) $order->get_meta( '_cvd_delivered_at', true );
		if ( '' === $at ) { $at = (string) $order->get_meta( '_cvd_to_customer_at', true ); }
		$ts = '' !== $at ? strtotime( $at . ' UTC' ) : false;
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Motivos para preguntar; vacío = no hace falta. Solo a la tienda: lo que cierra administración (Lennys, el panel)
	 * ya va fuera del cajón en el cuadre, y la caja NEXO cobra con el dinero en la mano (manda cashReceivedToday=yes).
	 */
	public static function reasons( WC_Order $order, int $user_id, ?int $now = null ): array {
		if ( ! $user_id || user_can( $user_id, 'manage_woocommerce' ) ) { return array(); }
		if ( 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ) { return array(); }
		$now = $now ?? time();
		$delivered = self::delivered_ts( $order );
		if ( ! $delivered ) { return array(); }
		$reasons = array();
		if ( $now - $delivered > DAY_IN_SECONDS ) { $reasons[] = 'tarde'; }
		if ( self::day( $delivered ) < self::day( $now ) && count( self::recent_marks( $user_id, $now, $order->get_id() ) ) >= self::BURST ) { $reasons[] = 'rafaga'; }
		return $reasons;
	}

	/** La respuesta que trae la petición (cashReceivedToday = yes|no, cashReceivedDay = AAAA-MM-DD) o null. */
	public static function answer_from( WP_REST_Request $request, ?int $now = null ): ?array {
		$answer = sanitize_key( (string) $request->get_param( 'cashReceivedToday' ) );
		if ( ! in_array( $answer, array( 'yes', 'no' ), true ) ) { return null; }
		$today = self::day( $now ?? time() );
		$day = sanitize_text_field( (string) $request->get_param( 'cashReceivedDay' ) );
		if ( 'yes' === $answer ) { $day = $today; }
		elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day >= $today ) { $day = ''; }
		return array( 'answer' => $answer, 'day' => $day );
	}

	/**
	 * Antes de guardar el dinero. Devuelve WP_Error 428 con la pregunta si hace falta y no vino la respuesta;
	 * si no, lo que se guardará con record() (array vacío si no hubo que preguntar).
	 */
	public static function check( WC_Order $order, WP_REST_Request $request, int $user_id ) {
		$reasons = self::reasons( $order, $user_id );
		if ( ! $reasons ) { return array(); }
		$answer = self::answer_from( $request );
		if ( null === $answer ) {
			return new WP_Error( self::ERROR, '¿Lo recibiste hoy?', array(
				'status' => 428, 'order_id' => $order->get_id(), 'reasons' => $reasons,
				'delivered_day' => self::day( self::delivered_ts( $order ) ), 'today' => self::day( time() ),
			) );
		}
		return array( 'reasons' => $reasons ) + $answer;
	}

	/** Deja la respuesta en el pedido (sin guardar: lo guarda quien llama) y apunta la marca para ver ráfagas. */
	public static function record( WC_Order $order, $check, int $user_id, ?int $now = null ): void {
		$now = $now ?? time();
		if ( is_array( $check ) && ! empty( $check['answer'] ) ) {
			$order->update_meta_data( self::ANSWER, $check['answer'] );
			$order->update_meta_data( self::DAY, $check['day'] );
			$order->update_meta_data( '_cvd_cash_day_reason', implode( ',', $check['reasons'] ) );
			$order->update_meta_data( '_cvd_cash_day_by', $user_id );
			$order->update_meta_data( '_cvd_cash_day_at', gmdate( 'Y-m-d H:i:s', $now ) );
			$order->add_order_note( 'yes' === $check['answer']
				? 'Dinero de una entrega de otro día: quien lo registró dijo que lo recibió hoy.'
				: sprintf( 'Dinero de una entrega de otro día: quien lo registró dijo que lo recibió %s. No cuenta en el cajón de hoy.', $check['day'] ? 'el ' . $check['day'] : 'otro día' ) );
		}
		$delivered = self::delivered_ts( $order );
		if ( $user_id && $delivered && self::day( $delivered ) < self::day( $now ) ) {
			$marks = self::recent_marks( $user_id, $now, $order->get_id() );
			$marks[] = array( $now, $order->get_id() );
			update_user_meta( $user_id, self::MARKS, array_slice( $marks, -20 ) );
		}
	}

	/** Cobros de entregas de otro día que esta persona marcó en los últimos 5 minutos (sin contar este pedido). */
	private static function recent_marks( int $user_id, int $now, int $order_id ): array {
		$marks = $user_id ? get_user_meta( $user_id, self::MARKS, true ) : array();
		$marks = is_array( $marks ) ? $marks : array();
		return array_values( array_filter( $marks, static function ( $m ) use ( $now, $order_id ): bool {
			return is_array( $m ) && isset( $m[0], $m[1] ) && $now - (int) $m[0] <= self::WINDOW && (int) $m[1] !== $order_id;
		} ) );
	}
}
