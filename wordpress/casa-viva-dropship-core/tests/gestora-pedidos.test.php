<?php
// Pruebas de las reglas de «la gestora cambia o cancela su pedido» y de «Dividir pedido» (cuentas puras, sin WordPress).
// Se corre con: php tests/gestora-pedidos.test.php  (sale con código 1 si algo falla). Core 3.14.116, Ernesto 3-nov.
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-cvd-gestora-pedidos.php';
require_once __DIR__ . '/../includes/class-cvd-order-edit.php';

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got, JSON_UNESCAPED_UNICODE ) . "\n  quería: " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . "\n"; }
}
function starts( string $name, string $got, string $prefix ): void { check( $name, 0 === strpos( $got, $prefix ) ? $prefix : $got, $prefix ); }
$G = 'CVD_Gestora_Pedidos';

// ---------- Reglas: cuándo puede ----------
$ok = array( 'mine' => true, 'status' => 'on-hold', 'operation' => 'new', 'stage' => 'unassigned', 'pickup' => false, 'payment_kind' => 'cash_usd', 'reserva' => false, 'commission_status' => 'pending', 'payout' => false, 'ledger_settled' => false, 'cancelled_by_her' => false, 'cancelled_days' => 0 );
check( 'suyo, en tienda, efectivo: cambia', $G::block_reason( 'cambiar', $ok ), '' );
check( 'suyo, en tienda, efectivo: cancela', $G::block_reason( 'cancelar', $ok ), '' );
check( 'aceptado por el mensajero pero sin recoger (#6102 a las 14:27): cambia', $G::block_reason( 'cambiar', array( 'stage' => 'accepted' ) + $ok ), '' );
check( 'camino a la tienda: cambia', $G::block_reason( 'cambiar', array( 'stage' => 'to_store' ) + $ok ), '' );
check( 'procesando: cambia', $G::block_reason( 'cambiar', array( 'status' => 'processing' ) + $ok ), '' );
check( 'efectivo CUP: cambia', $G::block_reason( 'cambiar', array( 'payment_kind' => 'cash_cup' ) + $ok ), '' );
check( 'euros: cambia', $G::block_reason( 'cambiar', array( 'payment_kind' => 'eur' ) + $ok ), '' );
check( 'sin forma de pago apuntada: cambia', $G::block_reason( 'cambiar', array( 'payment_kind' => '' ) + $ok ), '' );
check( 'comisión vacía (aún sin calcular): cambia', $G::block_reason( 'cambiar', array( 'commission_status' => '' ) + $ok ), '' );
check( 'recogida en tienda sin reparto: cambia', $G::block_reason( 'cambiar', array( 'pickup' => true, 'stage' => '' ) + $ok ), '' );

// ---------- Reglas: cuándo NO ----------
check( 'pedido de otra gestora', $G::block_reason( 'cambiar', array( 'mine' => false ) + $ok ), 'Ese pedido no está entre los tuyos.' );
check( 'de otra gestora tampoco se cancela', $G::block_reason( 'cancelar', array( 'mine' => false ) + $ok ), 'Ese pedido no está entre los tuyos.' );
foreach ( array( 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed', 'incident' ) as $stage ) {
	check( "mensajero ya lo tiene ({$stage}): no cambia", $G::block_reason( 'cambiar', array( 'stage' => $stage ) + $ok ), 'El mensajero ya recogió el paquete. Para cambiarlo, escribe a la tienda.' );
	check( "mensajero ya lo tiene ({$stage}): no cancela", $G::block_reason( 'cancelar', array( 'stage' => $stage ) + $ok ), 'El mensajero ya recogió el paquete. Para cambiarlo, escribe a la tienda.' );
}
check( '#6062 (picked_up, de Nadielys): no cambia', $G::block_reason( 'cambiar', array( 'stage' => 'picked_up', 'operation' => 'with_courier' ) + $ok ), 'El mensajero ya recogió el paquete. Para cambiarlo, escribe a la tienda.' );
foreach ( array( 'zelle', 'paypal', 'transfer_cup', 'clasica' ) as $kind ) {
	check( "prepago {$kind}: no cambia", $G::block_reason( 'cambiar', array( 'payment_kind' => $kind ) + $ok ), 'Este pedido se paga por adelantado (Zelle, PayPal o transferencia): los cambios los hace la tienda.' );
	check( "prepago {$kind}: no cancela", $G::block_reason( 'cancelar', array( 'payment_kind' => $kind ) + $ok ), 'Este pedido se paga por adelantado (Zelle, PayPal o transferencia): los cambios los hace la tienda.' );
}
check( 'con reserva: no', $G::block_reason( 'cancelar', array( 'reserva' => true, 'pickup' => true ) + $ok ), 'Este pedido tiene una reserva guardada en la tienda: los cambios los hace la tienda.' );
check( 'comisión aprobada: no', $G::block_reason( 'cambiar', array( 'commission_status' => 'approved' ) + $ok ), 'Tu comisión de este pedido ya está aprobada o pagada: los cambios los hace la tienda.' );
check( 'comisión pagada: no', $G::block_reason( 'cancelar', array( 'commission_status' => 'paid' ) + $ok ), 'Tu comisión de este pedido ya está aprobada o pagada: los cambios los hace la tienda.' );
check( 'comisión en una solicitud de pago: no', $G::block_reason( 'cambiar', array( 'payout' => true ) + $ok ), 'Tu comisión de este pedido ya está aprobada o pagada: los cambios los hace la tienda.' );
check( 'ganancia del mensajero liquidada: no', $G::block_reason( 'cancelar', array( 'ledger_settled' => true ) + $ok ), 'Este pedido ya está liquidado con el mensajero: los cambios los hace la tienda.' );
check( 'completado: no', $G::block_reason( 'cambiar', array( 'status' => 'completed' ) + $ok ), 'Este pedido ya está cerrado: no se puede cambiar.' );
check( 'cancelado: no se cambia', $G::block_reason( 'cambiar', array( 'status' => 'cancelled' ) + $ok ), 'Este pedido ya está cancelado.' );
check( 'cancelado: no se vuelve a cancelar', $G::block_reason( 'cancelar', array( 'status' => 'cancelled' ) + $ok ), 'Este pedido ya está cancelado.' );
check( 'operación entregada: no', $G::block_reason( 'cambiar', array( 'operation' => 'delivered' ) + $ok ), 'Este pedido ya está cerrado: no se puede cambiar.' );
check( 'reembolsado: no', $G::block_reason( 'cancelar', array( 'status' => 'refunded' ) + $ok ), 'Este pedido ya está cerrado: no se puede cambiar.' );

// ---------- Recuperar ----------
$cancelled = array( 'status' => 'cancelled', 'cancelled_by_her' => true, 'cancelled_days' => 0.5 ) + $ok;
check( 'lo canceló ella hoy: recupera', $G::block_reason( 'recuperar', $cancelled ), '' );
check( 'abierto: no se recupera', $G::block_reason( 'recuperar', $ok ), 'Solo se recupera un pedido cancelado.' );
check( 'lo canceló la tienda: no', $G::block_reason( 'recuperar', array( 'cancelled_by_her' => false ) + $cancelled ), 'Este pedido lo canceló la tienda: para recuperarlo, escribe a la tienda.' );
check( 'hace 8 días: no', $G::block_reason( 'recuperar', array( 'cancelled_days' => 8 ) + $cancelled ), 'Lleva más de 7 días cancelado: haz un pedido nuevo.' );
check( 'prepago cancelado: no se recupera', $G::block_reason( 'recuperar', array( 'payment_kind' => 'zelle' ) + $cancelled ), 'Este pedido se paga por adelantado (Zelle, PayPal o transferencia): los cambios los hace la tienda.' );
check( 'de otra gestora: no se recupera', $G::block_reason( 'recuperar', array( 'mine' => false ) + $cancelled ), 'Ese pedido no está entre los tuyos.' );

// ---------- Lista blanca de «Cambiar» ----------
$lines = array( 643 => 1, 644 => 1 ); // #6102: topper (643) y Camero (644)
check( '#6102: quitar el topper', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 643, 'qty' => 0 ), array( 'itemId' => 644, 'qty' => 1 ) ) ) ), array( 'data' => array( 'lines' => array( array( 'itemId' => 643, 'qty' => 0 ) ) ), 'error' => '' ) );
check( 'bajar 3 → 1', $G::whitelist( array( 7 => 3 ), array( 'lines' => array( array( 'itemId' => 7, 'qty' => 1 ) ) ) ), array( 'data' => array( 'lines' => array( array( 'itemId' => 7, 'qty' => 1 ) ) ), 'error' => '' ) );
check( 'misma cantidad: nada que tocar', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 643, 'qty' => 1 ) ) ) ), array( 'data' => array(), 'error' => '' ) );
check( 'subir: no', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 644, 'qty' => 2 ) ) ) )['error'], 'Desde aquí solo puedes bajar cantidades, no subirlas. Para subir, escribe a la tienda.' );
check( 'negativa: no', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 644, 'qty' => -1 ) ) ) )['error'], 'La cantidad no puede ser negativa.' );
check( 'producto ajeno: no', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 999, 'qty' => 0 ) ) ) )['error'], 'Uno de esos productos no está en el pedido. Actualiza la página y vuelve a probar.' );
check( 'todo a cero: no', $G::whitelist( $lines, array( 'lines' => array( array( 'itemId' => 643, 'qty' => 0 ), array( 'itemId' => 644, 'qty' => 0 ) ) ) )['error'], 'El pedido no puede quedar sin productos. Si ya no lo quieren, cancélalo.' );
check( 'añadir: no', $G::whitelist( $lines, array( 'add' => array( array( 'wooId' => 3702, 'qty' => 1 ) ) ) )['error'], 'Desde aquí solo puedes quitar productos o bajar cantidades. Para añadir algo, escribe a la tienda.' );
check( 'vuelto: no', $G::whitelist( $lines, array( 'changeRequired' => array( array( 'amount' => 5, 'currency' => 'USD' ) ) ) )['error'], 'El vuelto lo cambia la tienda.' );
check( 'vuelto vacío también: no', $G::whitelist( $lines, array( 'changeRequired' => array() ) )['error'], 'El vuelto lo cambia la tienda.' );
foreach ( array( 'locality', 'municipality', 'zone', 'price', 'total', 'fulfillment' ) as $key ) {
	check( "{$key}: no", $G::whitelist( $lines, array( $key => 'Playa' ) )['error'], 'Cambiar de municipio, de reparto o de precio lo hace la tienda (cambia la mensajería). Escríbele a la tienda.' );
}
check( 'reparto vacío no molesta', $G::whitelist( $lines, array( 'locality' => '' ) )['error'], '' );
check( 'contacto, calle, día, franja y nota pasan', $G::whitelist( $lines, array( 'firstName' => 'Katy', 'lastName' => 'P', 'phone' => '', 'address' => 'Calle Georgia #3', 'deliveryDate' => '2026-11-04', 'deliveryWindow' => 'morning', 'note' => 'tocar fuerte', 'foo' => 'x', 'status' => 'completed' ) ), array( 'data' => array( 'firstName' => 'Katy', 'lastName' => 'P', 'phone' => '', 'address' => 'Calle Georgia #3', 'deliveryDate' => '2026-11-04', 'deliveryWindow' => 'morning', 'note' => 'tocar fuerte' ), 'error' => '' ) );
check( 'recogida: sin dirección ni día', $G::whitelist( $lines, array( 'address' => 'x', 'deliveryDate' => '2026-11-04', 'note' => 'n' ), true ), array( 'data' => array( 'note' => 'n' ), 'error' => '' ) );
check( 'un arreglo en un campo de texto se ignora', $G::whitelist( $lines, array( 'firstName' => array( 'x' ) ) ), array( 'data' => array(), 'error' => '' ) );

// ---------- Dividir: el reparto de líneas ----------
$E = 'CVD_Order_Edit';
$cur = array( 605 => 1, 606 => 1 ); // #6062: Queen (605) para Ricardo y Camero (606) para Katy
check( '#6062: el Camero pasa a otro pedido', $E::split_plan( $cur, array( array( 'itemId' => 606, 'qty' => 1 ) ) ), array( 606 => 1 ) );
check( 'pasa todo: no', $E::split_plan( $cur, array( array( 'itemId' => 605, 'qty' => 1 ), array( 'itemId' => 606, 'qty' => 1 ) ) ), 'El pedido de antes no puede quedar vacío. Si todo es para el otro cliente, cambia los datos del cliente con «Cambiar pedido».' );
check( 'más de lo que hay: no', $E::split_plan( $cur, array( array( 'itemId' => 606, 'qty' => 2 ) ) ), 'No puedes pasar más unidades de las que tiene el pedido.' );
check( 'repetida y se pasa: no', $E::split_plan( array( 7 => 3, 8 => 1 ), array( array( 'itemId' => 7, 'qty' => 2 ), array( 'itemId' => 7, 'qty' => 2 ) ) ), 'No puedes pasar más unidades de las que tiene el pedido.' );
check( 'línea ajena: no', $E::split_plan( $cur, array( array( 'itemId' => 1, 'qty' => 1 ) ) ), 'Uno de los productos elegidos no está en este pedido.' );
check( 'nada elegido: no', $E::split_plan( $cur, array( array( 'itemId' => 606, 'qty' => 0 ) ) ), 'Elige qué productos pasan al pedido nuevo.' );
check( 'parte de una línea', $E::split_plan( array( 7 => 3 ), array( array( 'itemId' => 7, 'qty' => 1 ) ) ), array( 7 => 1 ) );
check( 'dos de tres de una línea y otra entera', $E::split_plan( array( 7 => 3, 8 => 1, 9 => 2 ), array( array( 'itemId' => 7, 'qty' => 2 ), array( 'itemId' => 8, 'qty' => 1 ) ) ), array( 7 => 2, 8 => 1 ) );
check( 'basura en la petición se ignora', $E::split_plan( $cur, array( 'x', array( 'itemId' => 'abc', 'qty' => 1 ), array( 'itemId' => 606, 'qty' => 1 ) ) ), array( 606 => 1 ) );

echo $fails ? "\n{$fails} de {$n} pruebas FALLARON.\n" : "OK: {$n} pruebas.\n";
exit( $fails ? 1 : 0 );
