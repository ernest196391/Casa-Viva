<?php

define( 'ABSPATH', __DIR__ );

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_time( $type, $gmt = false ): string { return '2026-08-15 22:00:00'; }
function wp_generate_uuid4(): string { static $i = 0; return 'uuid-' . ++$i; }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function get_current_user_id(): int { return $GLOBALS['cvt_user_id'] ?? 0; }
function get_userdata( $id ) { return $GLOBALS['cvt_users'][ (int) $id ] ?? false; }
function user_can( $user, $capability ): bool { return ! empty( $user->caps[ $capability ] ); }
function clean_post_cache( $id ): void {}
function wc_get_order( $id ) { return $GLOBALS['cvt_orders'][ (int) $id ] ?? false; }

class WP_User {
	public int $ID;
	public array $roles;
	public array $caps;
	public function __construct( int $id, array $roles, array $caps ) { $this->ID=$id; $this->roles=$roles; $this->caps=$caps; }
}

class WC_Order {
	private int $id;
	public array $meta = array();
	public array $notes = array();
	public string $status = 'processing';
	public bool $fail_save = false;
	public function __construct( int $id ) { $this->id=$id; }
	public function get_id(): int { return $this->id; }
	public function get_status(): string { return $this->status; }
	public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
	public function update_meta_data( $key, $value ): void { $this->meta[$key]=$value; }
	public function delete_meta_data( $key ): void { unset($this->meta[$key]); }
	public function add_order_note( $note ): void { $this->notes[]=$note; }
	public function save(): int { if($this->fail_save){throw new RuntimeException('save failed');} return $this->id; }
	public function snapshot(): array { return array($this->meta,$this->notes,$this->status); }
	public function restore( array $snapshot ): void { [$this->meta,$this->notes,$this->status]=$snapshot; }
}

class CVT_WPDB {
	public bool $lock_available = true;
	private array $snapshots = array();
	public function prepare( $query, ...$args ): string { return $query . '|' . implode('|',$args); }
	public function get_var( $query ) { if(strpos($query,'GET_LOCK')!==false){return $this->lock_available?1:0;} return 1; }
	public function query( $query ) {
		if('START TRANSACTION'===$query){foreach($GLOBALS['cvt_orders'] as $id=>$order){$this->snapshots[$id]=$order->snapshot();}}
		if('ROLLBACK'===$query){foreach($this->snapshots as $id=>$snapshot){$GLOBALS['cvt_orders'][$id]->restore($snapshot);} $this->snapshots=array();}
		if('COMMIT'===$query){$this->snapshots=array();}
		return 1;
	}
}

require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-order-events.php';
require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-order-transition-service.php';

function cvt_check( bool $condition, string $label ): void { if(!$condition){fwrite(STDERR,"FALLO: {$label}\n");exit(1);} echo "OK: {$label}\n"; }
function cvt_reset(): WC_Order {
	$GLOBALS['cvt_user_id']=10;
	$GLOBALS['cvt_users']=array(
		10=>new WP_User(10,array('cvd_clerk'),array('cvd_manage_sales'=>true)),
		11=>new WP_User(11,array('administrator'),array('manage_woocommerce'=>true)),
		12=>new WP_User(12,array('customer'),array()),
	);
	$order=new WC_Order(459); $order->meta['_cvd_operation_status']='new';
	$GLOBALS['cvt_orders']=array(459=>$order);
	$GLOBALS['wpdb']=new CVT_WPDB();
	CVD_Order_Events::use_repository(new CVD_Array_Order_Event_Repository());
	return $order;
}

$order=cvt_reset();
$valid=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10,'idempotency_key'=>'valid-1'));
cvt_check($valid['success'] && 'new'===$valid['previous_state'] && 'preparing'===$order->get_meta('_cvd_operation_status',true),'transición válida y actor autorizado');
cvt_check(1===CVD_Order_Event_Timeline::read(459,array(),1,20)['total'],'evento exactamente una vez');

$invalid=CVD_Order_Transition_Service::transition(459,'operation','confirmed',array('actor_user_id'=>10));
cvt_check(!$invalid['success'] && CVD_Order_Transition_Service::INVALID_TRANSITION===$invalid['error_code'] && 'preparing'===$order->get_meta('_cvd_operation_status',true),'transición inválida falla antes de escribir');

$order=cvt_reset();
$unauthorized=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>12));
cvt_check(!$unauthorized['success'] && CVD_Order_Transition_Service::UNAUTHORIZED===$unauthorized['error_code'] && 'new'===$order->get_meta('_cvd_operation_status',true),'actor no autorizado');

cvt_check(CVD_Order_Transition_Service::ORDER_NOT_FOUND===CVD_Order_Transition_Service::transition(999,'operation','preparing',array('actor_user_id'=>10))['error_code'],'pedido inexistente');

$order=cvt_reset();
$first=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10,'idempotency_key'=>'same-http'));
$second=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10,'idempotency_key'=>'same-http'));
cvt_check($first['success'] && $second['success'] && $second['idempotent_replay'] && $first['event_id']===$second['event_id'],'doble ejecución e idempotencia');
cvt_check(1===CVD_Order_Event_Timeline::read(459,array(),1,20)['total'],'reintento no duplica evento');

$order=cvt_reset();
CVD_Order_Transition_Service::transition(459,'operation','incident',array('actor_user_id'=>10,'idempotency_key'=>'incident-1'));
CVD_Order_Transition_Service::transition(459,'operation','confirmed',array('actor_user_id'=>10,'idempotency_key'=>'resolve-1'));
CVD_Order_Transition_Service::transition(459,'operation','incident',array('actor_user_id'=>11,'idempotency_key'=>'incident-2'));
$events=CVD_Order_Event_Timeline::read(459,array(),1,20)['events'];
cvt_check(2===count(array_filter($events,fn($e)=>'incident.opened'===$e['event_type'])),'transición legítima repetida posteriormente');
cvt_check(1===count(array_filter($events,fn($e)=>'incident.resolved'===$e['event_type'])) && 'incident'===$order->get_meta('_cvd_operation_status',true),'incidencia independiente con apertura y resolución');

$order=cvt_reset();
$failed=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10,'side_effect'=>static function(){throw new RuntimeException('boom');}));
cvt_check(!$failed['success'] && CVD_Order_Transition_Service::SIDE_EFFECT_FAILED===$failed['error_code'] && 'new'===$order->get_meta('_cvd_operation_status',true),'fallo de side effect revierte estado');
cvt_check(0===CVD_Order_Event_Timeline::read(459,array(),1,20)['total'],'fallo de side effect no deja evento');

$order=cvt_reset(); unset($order->meta['_cvd_operation_status']);
$legacy=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10));
cvt_check($legacy['success'] && 'new'===$legacy['previous_state'],'estado legacy sin metadato');

$order=cvt_reset(); $GLOBALS['wpdb']->lock_available=false;
$conflict=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>11));
cvt_check(!$conflict['success'] && CVD_Order_Transition_Service::CONFLICT===$conflict['error_code'] && 'new'===$order->get_meta('_cvd_operation_status',true),'dos actores concurrentes: segundo recibe conflicto');

echo "FASE 1C: pruebas unitarias completadas.\n";
