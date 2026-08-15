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
		20=>new WP_User(20,array('cvd_messenger'),array()),
		21=>new WP_User(21,array('cvd_messenger'),array()),
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
$failed=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10,'atomic_mutation'=>static function(){throw new RuntimeException('boom');}));
cvt_check(!$failed['success'] && CVD_Order_Transition_Service::SIDE_EFFECT_FAILED===$failed['error_code'] && 'new'===$order->get_meta('_cvd_operation_status',true),'fallo de side effect revierte estado');
cvt_check(0===CVD_Order_Event_Timeline::read(459,array(),1,20)['total'],'fallo de side effect no deja evento');

$order=cvt_reset(); unset($order->meta['_cvd_operation_status']);
$legacy=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>10));
cvt_check($legacy['success'] && 'new'===$legacy['previous_state'],'estado legacy sin metadato');

$order=cvt_reset(); $GLOBALS['wpdb']->lock_available=false;
$conflict=CVD_Order_Transition_Service::transition(459,'operation','preparing',array('actor_user_id'=>11));
cvt_check(!$conflict['success'] && CVD_Order_Transition_Service::CONFLICT===$conflict['error_code'] && 'new'===$order->get_meta('_cvd_operation_status',true),'dos actores concurrentes: segundo recibe conflicto');

// Fase 1C.1: ready y logística previa a custodia.
$order=cvt_reset(); $order->meta['_cvd_operation_status']='preparing';
$ready=CVD_Order_Transition_Service::transition(459,'operation','ready',array('actor_user_id'=>10,'idempotency_key'=>'ready-1'));
$ready_retry=CVD_Order_Transition_Service::transition(459,'operation','ready',array('actor_user_id'=>10,'idempotency_key'=>'ready-1'));
cvt_check($ready['success']&&$ready_retry['idempotent_replay']&&$ready['event_id']===$ready_retry['event_id'],'preparing → ready y retry estable');

$order=cvt_reset(); $order->meta['_cvd_operation_status']='incident';
cvt_check(CVD_Order_Transition_Service::transition(459,'operation','ready',array('actor_user_id'=>11))['success'],'incident → ready según mapa legacy');

$order=cvt_reset(); $order->meta['_cvd_delivery_status']='unassigned'; $external=0;
$offered=CVD_Order_Transition_Service::transition(459,'delivery','offered',array('actor_user_id'=>10,'idempotency_key'=>'offer-1','atomic_mutation'=>static function($order){$order->update_meta_data('_cvd_delivery_invited_messengers',array(20,21));},'after_commit'=>static function()use(&$external){$external++;}));
$offered_retry=CVD_Order_Transition_Service::transition(459,'delivery','offered',array('actor_user_id'=>10,'idempotency_key'=>'offer-1','after_commit'=>static function()use(&$external){$external++;}));
cvt_check($offered['success']&&$offered_retry['idempotent_replay']&&1===$external,'oferta y notificación exactamente una vez');

$winner=CVD_Order_Transition_Service::transition(459,'delivery','accepted',array('actor_user_id'=>20,'idempotency_key'=>'accept-20','precondition'=>static function($order) { return in_array(20,(array)$order->get_meta('_cvd_delivery_invited_messengers',true),true); },'atomic_mutation'=>static function($order){$order->update_meta_data('_cvd_messenger_user_id',20);}));
$loser=CVD_Order_Transition_Service::transition(459,'delivery','accepted',array('actor_user_id'=>21,'idempotency_key'=>'accept-21','precondition'=>static function($order){return absint($order->get_meta('_cvd_messenger_user_id',true))===21?true:CVD_Order_Transition_Service::CONFLICT;}));
cvt_check($winner['success']&&!$loser['success']&&CVD_Order_Transition_Service::CONFLICT===$loser['error_code']&&20===absint($order->get_meta('_cvd_messenger_user_id',true)),'dos mensajeros: un solo ganador seguro');
$to_store=CVD_Order_Transition_Service::transition(459,'delivery','to_store',array('actor_user_id'=>20,'atomic_mutation'=>static function($order,$from,$to,$actor,$at){$order->update_meta_data('_cvd_to_store_at',$at);}));
cvt_check($to_store['success']&&$order->get_meta('_cvd_to_store_at',true),'accepted → to_store conserva mensajero y timestamp');

$order=cvt_reset(); $order->meta['_cvd_delivery_status']='unassigned';
$direct=CVD_Order_Transition_Service::transition(459,'delivery','assigned',array('actor_user_id'=>11,'precondition'=>static fn()=>true,'atomic_mutation'=>static function($order){$order->update_meta_data('_cvd_messenger_user_id',20);}));
cvt_check($direct['success']&&20===absint($order->get_meta('_cvd_messenger_user_id',true)),'asignación directa autorizada');
$bad_actor=CVD_Order_Transition_Service::transition(459,'delivery','accepted',array('actor_user_id'=>12));
cvt_check(!$bad_actor['success']&&CVD_Order_Transition_Service::UNAUTHORIZED===$bad_actor['error_code'],'actor no autorizado en logística');

$delivery_events=CVD_Order_Event_Timeline::read(459,array('domain'=>'delivery'),1,20)['events'];
cvt_check(1===count(array_filter($delivery_events,fn($e)=>'assigned'===$e['to_state'])),'evento logístico exactamente una vez');

// Fase 1C.2: custodia, ruta y resultado.
$order=cvt_reset();$order->meta['_cvd_operation_status']='ready';$order->meta['_cvd_delivery_status']='accepted';$order->meta['_cvd_messenger_user_id']=20;$external=0;
$pickup_context=array('actor_user_id'=>10,'idempotency_key'=>'pickup-accepted','coupled_operation_state'=>'with_courier','precondition'=>static fn($o)=>absint($o->get_meta('_cvd_messenger_user_id',true))?true:CVD_Order_Transition_Service::PRECONDITION_FAILED,'atomic_mutation'=>static function($o,$from,$to,$actor,$at){$o->update_meta_data('_cvd_handed_over_by',$actor->ID);$o->update_meta_data('_cvd_handed_over_at',$at);},'after_commit'=>static function()use(&$external){$external++;});
$pickup=CVD_Order_Transition_Service::transition(459,'delivery','picked_up',$pickup_context);$pickup_retry=CVD_Order_Transition_Service::transition(459,'delivery','picked_up',$pickup_context);
cvt_check($pickup['success']&&$pickup_retry['idempotent_replay']&&$pickup['event_id']===$pickup_retry['event_id']&&1===$external,'accepted → picked_up y doble pickup idempotente');
$pickup_events=CVD_Order_Event_Timeline::read(459,array(),1,20)['events'];
cvt_check('with_courier'===$order->get_meta('_cvd_operation_status',true)&&1===count(array_filter($pickup_events,fn($e)=>'operation'===$e['domain']&&'with_courier'===$e['to_state'])),'operation → with_courier sincronizado exactamente una vez');

$order=cvt_reset();$order->meta['_cvd_operation_status']='ready';$order->meta['_cvd_delivery_status']='to_store';$order->meta['_cvd_messenger_user_id']=20;
cvt_check(CVD_Order_Transition_Service::transition(459,'delivery','picked_up',array('actor_user_id'=>11,'idempotency_key'=>'pickup-to-store','coupled_operation_state'=>'with_courier'))['success'],'to_store → picked_up');
$wrong=CVD_Order_Transition_Service::transition(459,'delivery','handed_over',array('actor_user_id'=>21));cvt_check(!$wrong['success']&&CVD_Order_Transition_Service::UNAUTHORIZED===$wrong['error_code'],'mensajero incorrecto no puede iniciar ruta');
$route=CVD_Order_Transition_Service::transition(459,'delivery','handed_over',array('actor_user_id'=>20,'idempotency_key'=>'route-1','atomic_mutation'=>static function($o,$f,$t,$a,$at){$o->update_meta_data('_cvd_to_customer_at',$at);}));$route_retry=CVD_Order_Transition_Service::transition(459,'delivery','handed_over',array('actor_user_id'=>20,'idempotency_key'=>'route-1'));
cvt_check($route['success']&&$route_retry['idempotent_replay']&&$order->get_meta('_cvd_to_customer_at',true),'picked_up → handed_over y doble pulsación');
$delivered=CVD_Order_Transition_Service::transition(459,'delivery','delivered',array('actor_user_id'=>20,'idempotency_key'=>'delivered-1','coupled_payment_state'=>'pending_return','atomic_mutation'=>static function($o,$f,$t,$a,$at){$o->update_meta_data('_cvd_delivered_by',$a->ID);$o->update_meta_data('_cvd_delivered_at',$at);}));$delivered_retry=CVD_Order_Transition_Service::transition(459,'delivery','delivered',array('actor_user_id'=>20,'idempotency_key'=>'delivered-1'));
cvt_check($delivered['success']&&$delivered_retry['idempotent_replay']&&'pending_return'===$order->get_meta('_cvd_cash_status',true),'handed_over → delivered sin cierre y doble delivered');
$late_failed=CVD_Order_Transition_Service::transition(459,'delivery','failed',array('actor_user_id'=>20));cvt_check(!$late_failed['success']&&CVD_Order_Transition_Service::CONFLICT===$late_failed['error_code'],'delivered vs failed: solo un resultado gana');
$delivered_events=CVD_Order_Event_Timeline::read(459,array(),1,20)['events'];cvt_check(1===count(array_filter($delivered_events,fn($e)=>'payment'===$e['domain']&&'pending_return'===$e['to_state'])),'pending_return produce un evento de pago');

foreach(array('failed','returned')as$result_state){$order=cvt_reset();$order->meta['_cvd_delivery_status']='handed_over';$order->meta['_cvd_messenger_user_id']=20;$result=CVD_Order_Transition_Service::transition(459,'delivery',$result_state,array('actor_user_id'=>20,'idempotency_key'=>'result-'.$result_state));cvt_check($result['success']&&$result_state===$order->get_meta('_cvd_delivery_status',true),'handed_over → '.$result_state);}

$order=cvt_reset();$order->meta['_cvd_delivery_status']='accepted';$order->meta['_cvd_messenger_user_id']=20;
$pickup_unauthorized=CVD_Order_Transition_Service::transition(459,'delivery','picked_up',array('actor_user_id'=>20));cvt_check(!$pickup_unauthorized['success']&&CVD_Order_Transition_Service::UNAUTHORIZED===$pickup_unauthorized['error_code'],'solo Casa Viva transfiere custodia');
$rollback=CVD_Order_Transition_Service::transition(459,'delivery','picked_up',array('actor_user_id'=>10,'coupled_operation_state'=>'with_courier','atomic_mutation'=>static function(){throw new RuntimeException('pickup write failed');}));cvt_check(!$rollback['success']&&CVD_Order_Transition_Service::SIDE_EFFECT_FAILED===$rollback['error_code']&&'accepted'===$order->get_meta('_cvd_delivery_status',true)&&'new'===(sanitize_key((string)$order->get_meta('_cvd_operation_status',true))?:'new'),'fallo atómico revierte custodia y operación');

echo "FASE 1C.2: pruebas unitarias completadas.\n";
