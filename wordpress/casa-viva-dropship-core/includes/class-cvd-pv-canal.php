<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026): las señales en vivo, por NUESTRO ntfy del VPS (la web de Hostinger no tiene conexión
 * abierta). Core publica; el móvil escucha con EventSource en `<ntfy>/<tema>/sse` y, si eso se corta, pregunta a la web.
 *
 * - Un tema secreto por viaje («palla-v<id>-<12 hex>», guardado en el viaje) para el cliente, y otro callado por chofer
 *   («palla-c<id>-<12 hex>», user meta `_cvd_pv_tema`) para su pantalla abierta.
 * - En el VPS, `palla-*` es de solo lectura para todos y solo publica el usuario `core` (cvd_palla_ntfy_token). Nada que
 *   cambiar en el servidor.
 * - El mensaje es pequeño: {t:tipo, rev:n, …}. Lo que importa (precio, chofer) se vuelve a leer de la web al recibirlo,
 *   salvo la posición del chofer, que va dentro para no pedir nada.
 * - Nunca frena: envío sin esperar respuesta (blocking = false), 3 s como mucho.
 */
final class CVD_Pv_Canal {

	public static function url(): string {
		$u = untrailingslashit( (string) get_option( 'cvd_palla_ntfy_url', '' ) );
		return 0 === strpos( $u, 'https://' ) ? $u : '';
	}

	public static function nuevo_tema( int $viaje_id ): string {
		return 'palla-v' . $viaje_id . '-' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * El tema SILENCIOSO del chofer para su pantalla abierta (lo crea la primera vez). NO es el de la app Android
	 * (`_cvd_palla_ntfy_topic`): esa app hace sonar todo lo que llega a su tema, y estas señales son muchas y calladas.
	 * Los avisos que sí deben sonar («te piden un viaje») van por CVD_Palla_Push::avisar, como siempre.
	 */
	public static function tema_chofer( int $user_id ): string {
		$topic = (string) get_user_meta( $user_id, '_cvd_pv_tema', true );
		if ( '' === $topic ) {
			$topic = 'palla-c' . $user_id . '-' . bin2hex( random_bytes( 6 ) );
			update_user_meta( $user_id, '_cvd_pv_tema', $topic );
		}
		return $topic;
	}

	/** Señal silenciosa (sin notificación en la app de ntfy: prioridad 1 y etiqueta «pv»). */
	public static function senal( string $topic, array $data ): bool {
		return self::publicar( $topic, array( 'message' => (string) wp_json_encode( $data ), 'priority' => 1, 'tags' => array( 'pv' ) ) );
	}

	private static function publicar( string $topic, array $body ): bool {
		$url = self::url();
		$token = (string) get_option( 'cvd_palla_ntfy_token', '' );
		if ( '' === $url || '' === $token || ! preg_match( '/^palla-[a-z0-9-]{4,60}$/', $topic ) ) { return false; }
		if ( defined( 'CVD_PV_SIN_RED' ) && CVD_PV_SIN_RED ) { self::$enviados[] = array( $topic, $body ); return true; } // pruebas
		wp_remote_post( $url, array(
			'timeout'  => 3,
			'blocking' => false,
			'headers'  => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array_merge( array( 'topic' => $topic ), $body ) ),
		) );
		return true;
	}

	/** Para las pruebas automáticas (CVD_PV_SIN_RED): lo que se habría mandado. */
	public static $enviados = array();
}
