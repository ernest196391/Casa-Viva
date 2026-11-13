<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026, D139): lo que cada chofer debe a Pa'llá y lo que paga.
 *
 * - Comisión: `cvd_pv_comision_pct` (0 en el piloto y los 2 meses siguientes; luego 8, lo pone Ernesto). Se apunta al
 *   terminar cada viaje con el % de ESE momento (si luego cambia, los viajes de antes no cambian).
 * - El chofer paga por Transfermóvil a la cuenta de `cvd_pv_cobro` y escribe el número de la transferencia: cuenta como
 *   pagado al momento («el SMS del banco basta»); Ernesto lo confirma o lo rechaza en el panel /triciclub/admin.
 * - Tope `cvd_pv_tope` (2.000 CUP por defecto, D140; JSON por grupo {"taxi":15000,…} o un número): si la deuda llega al
 *   tope, se pausan SOLO sus viajes en efectivo (los pagados por transferencia le siguen llegando).
 */
final class CVD_Pv_Cuenta {
	public const TOPE = 2000;

	public static function pct(): int { return max( 0, min( 30, (int) get_option( 'cvd_pv_comision_pct', 0 ) ) ); }

	public static function tope( string $grupo = '' ): int {
		$raw = get_option( 'cvd_pv_tope', self::TOPE );
		$j = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( is_array( $j ) ) { return max( 0, (int) ( $j[ $grupo ] ?? ( $j['otros'] ?? self::TOPE ) ) ); }
		return max( 0, (int) $raw ) ?: self::TOPE;
	}

	/** Saldo: + debe, − a su favor. Los pagos rechazados no cuentan. */
	public static function saldo( int $chofer_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cup),0) FROM ' . CVD_Triciclub_Db::t( 'cuenta' ) . " WHERE chofer_id=%d AND estado NOT IN ('rechazado','revision')", $chofer_id ) );
	}

	/** 10-nov (auditoría): «No me pagó» → la comisión de ese viaje queda «en revisión» (no cuenta como deuda). */
	public static function revision( int $viaje_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'cuenta' ) . " SET estado='revision', updated_at=%s WHERE viaje_id=%d AND tipo='comision' AND estado='ok'", CVD_Triciclub_Db::now(), $viaje_id ) );
	}

	/** ¿Se le pausan los viajes en efectivo? */
	public static function pausa_efectivo( int $chofer_id, string $grupo ): bool {
		$tope = self::tope( $grupo );
		return $tope > 0 && self::saldo( $chofer_id ) >= $tope;
	}

	/** Al terminar un viaje: apunta su comisión (una sola vez por viaje). Devuelve [pct, cup]. */
	public static function apuntar( object $viaje, int $total ): array {
		global $wpdb;
		$pct = self::pct();
		$cup = (int) round( $total * $pct / 100 );
		$t = CVD_Triciclub_Db::t( 'cuenta' );
		if ( $cup > 0 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE viaje_id=%d AND tipo='comision'", (int) $viaje->id ) ) ) {
			$now = CVD_Triciclub_Db::now();
			$wpdb->insert( $t, array( 'chofer_id' => (int) $viaje->chofer_id, 'viaje_id' => (int) $viaje->id, 'tipo' => 'comision', 'cup' => $cup, 'estado' => 'ok', 'created_at' => $now, 'updated_at' => $now ) );
		}
		return array( $pct, $cup );
	}

	/** El chofer dice que pagó. */
	public static function pago( int $chofer_id, int $cup, string $ref ): int {
		global $wpdb;
		$now = CVD_Triciclub_Db::now();
		$wpdb->insert( CVD_Triciclub_Db::t( 'cuenta' ), array( 'chofer_id' => $chofer_id, 'viaje_id' => 0, 'tipo' => 'pago', 'cup' => -abs( $cup ), 'ref' => mb_substr( $ref, 0, 60 ), 'estado' => 'pendiente', 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	/** Ernesto: confirmar o rechazar un pago. */
	public static function revisar_pago( int $id, bool $ok, int $por ): bool {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'cuenta' ) . " SET estado=%s, por=%d, updated_at=%s WHERE id=%d AND tipo='pago' AND estado='pendiente'", $ok ? 'ok' : 'rechazado', $por, CVD_Triciclub_Db::now(), $id ) );
	}

	/** Para la pantalla «Lo que debo». */
	public static function resumen( int $chofer_id, string $grupo ): array {
		global $wpdb;
		$t = CVD_Triciclub_Db::t( 'cuenta' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, tipo, cup, ref, estado, created_at FROM {$t} WHERE chofer_id=%d ORDER BY id DESC LIMIT 20", $chofer_id ) );
		$cobro = (string) get_option( 'cvd_pv_cobro', '' );
		return array(
			'debe'     => max( 0, self::saldo( $chofer_id ) ),
			'tope'     => self::tope( $grupo ),
			'pct'      => self::pct(),
			'pausa'    => self::pausa_efectivo( $chofer_id, $grupo ),
			'cobro'    => $cobro, // tarjeta o móvil de Pa'llá para Transfermóvil (opción cvd_pv_cobro)
			'nota'     => 'CH-' . str_pad( (string) $chofer_id, 4, '0', STR_PAD_LEFT ),
			'lineas'   => array_map( static fn( object $r ): array => array( 'tipo' => (string) $r->tipo, 'cup' => (int) $r->cup, 'ref' => (string) $r->ref, 'estado' => (string) $r->estado, 'fecha' => gmdate( 'c', (int) strtotime( $r->created_at . ' UTC' ) ) ), $rows ),
		);
	}

	/** Pagos por revisar (panel). */
	public static function pendientes(): array {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'cuenta' ) . " WHERE tipo='pago' AND estado='pendiente' ORDER BY id ASC LIMIT 100" );
	}
}
