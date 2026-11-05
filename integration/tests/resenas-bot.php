<?php
/** Solo en la base de integración desechable. Reseñas que pide VivaBot (Core 3.14.141) con WooCommerce real. */
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Disposable localhost environment required.' ); }
function resena_assert( $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; }
function resena_post( array $p ): WP_REST_Request { $r = new WP_REST_Request( 'POST', '/casa-viva/v1/bot/resenas' ); foreach ( $p as $k => $v ) { $r->set_param( $k, $v ); } return $r; }
function resena_data( $out ): array { return is_wp_error( $out ) ? array( 'error' => $out->get_error_code(), 'status' => $out->get_error_data()['status'] ?? 0 ) : $out->get_data(); }

wp_set_current_user( 0 );
$anon = rest_do_request( new WP_REST_Request( 'GET', '/casa-viva/v1/bot/resenas/candidatos' ) );
resena_assert( $anon->get_status() >= 400, 'sin la llave del bot no se ve nada' );

$p = new WC_Product_Simple(); $p->set_name( 'Cortina reseña integración' ); $p->set_regular_price( '20' ); $p->set_status( 'publish' ); $p->save();
$other = new WC_Product_Simple(); $other->set_name( 'Otro producto' ); $other->set_regular_price( '5' ); $other->set_status( 'publish' ); $other->save();
$make = static function ( array $meta = array(), string $first = 'maría', string $last = 'pérez' ) use ( $p ): WC_Order {
	$o = wc_create_order(); $o->add_product( $p, 1 );
	$o->set_billing_first_name( $first ); $o->set_billing_last_name( $last ); $o->set_billing_phone( '5' . wp_rand( 1000000, 9999999 ) );
	foreach ( $meta as $k => $v ) { $o->update_meta_data( $k, $v ); }
	$o->calculate_totals(); $o->set_status( 'completed' ); $o->save();
	return $o;
};
$direct = $make();
$gestora_user = wp_insert_user( array( 'user_login' => 'cv_resena_gestora_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
$gestora = $make( array( '_cvd_owner_type' => 'gestora', '_cvd_owner_user_id' => $gestora_user ) );
$test = $make( array( '_cvd_test_order' => 'tutorial' ) );

resena_assert( '' === CVD_Resenas_Bot::excluded( $direct ), 'pedido directo entregado sirve' );
resena_assert( 'gestora' === CVD_Resenas_Bot::excluded( $gestora ), 'pedido de gestora: nunca al cliente' );
resena_assert( 'prueba' === CVD_Resenas_Bot::excluded( $test ), 'pedido de prueba fuera' );

$req = new WP_REST_Request( 'GET', '/casa-viva/v1/bot/resenas/candidatos' ); $req->set_param( 'dias', 2 );
$ids = wp_list_pluck( CVD_Resenas_Bot::candidates( $req )->get_data()['candidates'], 'id' );
resena_assert( in_array( $direct->get_id(), $ids, true ) && ! in_array( $gestora->get_id(), $ids, true ) && ! in_array( $test->get_id(), $ids, true ), 'candidatos: solo el directo' );
$cand = current( array_filter( CVD_Resenas_Bot::candidates( $req )->get_data()['candidates'], static fn( $c ) => $c['id'] === $direct->get_id() ) );
resena_assert( 'María P.' === $cand['signature'] && $p->get_id() === $cand['products'][0]['id'], 'firma «María P.» y producto del pedido' );

$ok = array( 'order' => $direct->get_id(), 'product' => $p->get_id(), 'text' => 'Muy buena la cortina, llegó rápido', 'name' => 'María P.', 'rating' => 5, 'permission' => '1', 'permission_text' => 'sí, publícalo' );
resena_assert( 'cvd_resena_no_pedida' === resena_data( CVD_Resenas_Bot::save( resena_post( $ok ) ) )['error'], 'sin haberla pedido no se guarda' );
$asked = new WP_REST_Request( 'POST', '/x' ); $asked['id'] = $direct->get_id();
resena_assert( false === CVD_Resenas_Bot::asked( $asked )->get_data()['already'], 'se marca «pedida» antes de enviar' );
resena_assert( true === CVD_Resenas_Bot::asked( $asked )->get_data()['already'], 'la segunda vez dice «ya pedida» (nunca dos mensajes)' );
$again = new WP_REST_Request( 'GET', '/x' ); $again->set_param( 'dias', 2 );
resena_assert( ! in_array( $direct->get_id(), wp_list_pluck( CVD_Resenas_Bot::candidates( $again )->get_data()['candidates'], 'id' ), true ), 'ya pedida: deja de ser candidata' );
$g_ask = new WP_REST_Request( 'POST', '/x' ); $g_ask['id'] = $gestora->get_id();
resena_assert( 409 === resena_data( CVD_Resenas_Bot::asked( $g_ask ) )['status'], 'a un pedido de gestora no se le puede marcar' );

resena_assert( 'cvd_resena_permiso' === resena_data( CVD_Resenas_Bot::save( resena_post( array_merge( $ok, array( 'permission' => '' ) ) ) ) )['error'], 'sin permiso: 422' );
resena_assert( 'cvd_resena_producto' === resena_data( CVD_Resenas_Bot::save( resena_post( array_merge( $ok, array( 'product' => $other->get_id() ) ) ) ) )['error'], 'producto que no está en el pedido: 422' );
$sim = resena_data( CVD_Resenas_Bot::save( resena_post( array_merge( $ok, array( 'simular' => 1 ) ) ) ) );
resena_assert( ! empty( $sim['simulated'] ) && 0 === (int) get_comments( array( 'post_id' => $p->get_id(), 'type' => 'review', 'count' => true ) ), 'simular no escribe' );

$saved = resena_data( CVD_Resenas_Bot::save( resena_post( $ok ) ) );
resena_assert( ! empty( $saved['saved'] ) && false === $saved['already'], 'reseña guardada' );
$c = get_comment( $saved['saved'] );
resena_assert( 'review' === $c->comment_type && '1' === (string) $c->comment_approved && 'María P.' === $c->comment_author && (int) $c->comment_post_ID === $p->get_id(), 'reseña aprobada en el producto con el nombre dado' );
resena_assert( '5' === (string) get_comment_meta( $c->comment_ID, 'rating', true ) && 'bot' === get_comment_meta( $c->comment_ID, '_cvd_resena_origen', true ), 'estrellas y origen «bot»' );
resena_assert( str_starts_with( (string) get_comment_meta( $c->comment_ID, '_cvd_resena_permiso', true ), 'pedido #' ) && 'sí, publícalo' === get_comment_meta( $c->comment_ID, '_cvd_resena_permiso_texto', true ), 'queda quién dio el permiso y sus palabras' );
resena_assert( true === resena_data( CVD_Resenas_Bot::save( resena_post( array_merge( $ok, array( 'text' => 'Otra opinión distinta' ) ) ) ) )['already'], 'una sola reseña por pedido y producto' );

$no_stars = $make( array(), 'Yuli', '' );
$a2 = new WP_REST_Request( 'POST', '/x' ); $a2['id'] = $no_stars->get_id(); CVD_Resenas_Bot::asked( $a2 );
$s2 = resena_data( CVD_Resenas_Bot::save( resena_post( array( 'order' => $no_stars->get_id(), 'text' => 'Todo bien, gracias', 'name' => 'Yuli', 'permission' => 'sí' ) ) ) );
resena_assert( ! empty( $s2['saved'] ) && '' === (string) get_comment_meta( $s2['saved'], 'rating', true ), 'sin estrellas se guarda sin estrellas (nunca se inventan)' );
echo "Reseñas del bot: integración correcta.\n";
