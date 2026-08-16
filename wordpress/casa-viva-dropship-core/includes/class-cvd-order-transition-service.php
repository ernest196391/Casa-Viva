<?php

defined( 'ABSPATH' ) || exit;

/** Autoridad progresiva para transiciones de pedido Casa Viva. */
final class CVD_Order_Transition_Service {
	public const INVALID_TRANSITION = 'INVALID_TRANSITION';
	public const UNAUTHORIZED = 'UNAUTHORIZED';
	public const PRECONDITION_FAILED = 'PRECONDITION_FAILED';
	public const CONFLICT = 'CONFLICT';
	public const ALREADY_APPLIED = 'ALREADY_APPLIED';
	public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
	public const SIDE_EFFECT_FAILED = 'SIDE_EFFECT_FAILED';
	private const RECEIPTS_META = '_cvd_transition_receipts';
	private const OPERATION_TRANSITIONS = array(
		'new'=>array('preparing','incident'), 'confirmed'=>array('preparing','incident'),
		'preparing'=>array('ready','incident'), 'incident'=>array('confirmed','preparing','ready'),
	);
	private const DELIVERY_TRANSITIONS = array(
		'unassigned'=>array('offered','assigned'), 'offered'=>array('assigned','accepted'),
		'assigned'=>array('accepted'), 'accepted'=>array('to_store','picked_up'),
		'to_store'=>array('picked_up'), 'picked_up'=>array('handed_over'),
		'handed_over'=>array('delivered','failed','returned'), 'delivered'=>array('cash_returned'),
		'cash_returned'=>array('closed'),
	);

	public static function governs( string $domain, string $from, string $to ): bool {
		$map = 'operation' === $domain ? self::OPERATION_TRANSITIONS : ( 'delivery' === $domain ? self::DELIVERY_TRANSITIONS : array() );
		return in_array( $to, $map[$from] ?? array(), true );
	}

	/**
	 * `precondition` y `atomic_mutation` son adaptadores internos migrados.
	 * `after_commit` contiene únicamente consecuencias externas.
	 * @return array{success:bool,previous_state:string,new_state:string,event_id:string,idempotent_replay:bool,error_code:string}
	 */
	public static function transition( int $order_id, string $domain, string $target_state, array $context=array() ): array {
		$domain=sanitize_key($domain); $target_state=sanitize_key($target_state);
		$order=wc_get_order($order_id); if(!$order){return self::failure(self::ORDER_NOT_FOUND);}
		$actor_id=array_key_exists('actor_user_id',$context)?absint($context['actor_user_id']):get_current_user_id();
		$actor=$actor_id?get_userdata($actor_id):null; if(!$actor){return self::failure(self::UNAUTHORIZED);}
		$idempotency_key=trim((string)($context['idempotency_key']??'')); $receipt_hash=$idempotency_key?hash('sha256',$idempotency_key):'';
		$receipt=$receipt_hash?(self::receipts($order)[$receipt_hash]??null):null;
		if(is_array($receipt)){return self::replay($receipt,$domain,$target_state);}
		global $wpdb; $lock_key='cvd_transition_'.$order_id;
		if(!self::acquire_lock($wpdb,$lock_key)){return self::failure(self::CONFLICT);}
		$transaction_started=false;
		try {
			$order=self::fresh_order($order_id); if(!$order){return self::failure(self::ORDER_NOT_FOUND);}
			$current=self::state($order,$domain); $receipts=self::receipts($order);
			if($receipt_hash&&isset($receipts[$receipt_hash])){return self::replay($receipts[$receipt_hash],$domain,$target_state);}
			// El pedido ya aceptado por otro mensajero es una carrera perdida, no
			// una fuga de autorización ni un replay aplicable al segundo actor.
			if('delivery'===$domain&&'accepted'===$current&&'accepted'===$target_state&&$actor->ID!==absint($order->get_meta('_cvd_messenger_user_id',true))){return self::failure(self::CONFLICT,$current);}
			if('delivery'===$domain&&in_array($current,array('delivered','failed','returned'),true)&&in_array($target_state,array('delivered','failed','returned'),true)&&$current!==$target_state){return self::failure(self::CONFLICT,$current);}
			if(!self::authorized($order,$domain,$current,$target_state,$actor,$context)){return self::failure(self::UNAUTHORIZED,$current);}
			if(isset($context['precondition'])&&is_callable($context['precondition'])){
				$checked=call_user_func($context['precondition'],$order,$current,$target_state,$actor);
				if(true!==$checked){return self::failure(is_string($checked)?$checked:self::PRECONDITION_FAILED,$current);}
			}
			if($current===$target_state){return self::success($current,$current,'',true,self::ALREADY_APPLIED);}
			if(!self::governs($domain,$current,$target_state)){return self::failure(self::INVALID_TRANSITION,$current);}
			$terminal_wc=in_array($order->get_status(),array('completed','cancelled','refunded','failed'),true);
			if($terminal_wc&&!( 'delivery'===$domain&&'closed'===$target_state&&'completed'===$order->get_status())){return self::failure(self::PRECONDITION_FAILED,$current);}
			$wpdb->query('START TRANSACTION'); $transaction_started=true;
			$at=current_time('mysql',true); $anchor=$idempotency_key?:wp_generate_uuid4();
			$operation_target=sanitize_key((string)($context['coupled_operation_state']??'')); $operation_from=$operation_target?self::state($order,'operation'):'';
			$payment_target=sanitize_key((string)($context['coupled_payment_state']??'')); $payment_from=$payment_target?sanitize_key((string)$order->get_meta('_cvd_cash_status',true)):'';
			if(isset($context['atomic_mutation'])&&is_callable($context['atomic_mutation'])){call_user_func($context['atomic_mutation'],$order,$current,$target_state,$actor,$at);}
			self::write_state_and_history($order,$domain,$current,$target_state,$actor_id,$at,$anchor,(array)($context['metadata']??array()));
			if($operation_target&&$operation_from!==$operation_target){self::write_state_and_history($order,'operation',$operation_from,$operation_target,$actor_id,$at,$anchor.':operation',array('trigger'=>'delivery.'.$target_state,'suppress_note'=>true));}
			if($payment_target&&$payment_from!==$payment_target){$order->update_meta_data('_cvd_cash_status',$payment_target);}
			$additional_events=array();
			if(isset($context['atomic_after_state'])&&is_callable($context['atomic_after_state'])){$atomic_result=call_user_func($context['atomic_after_state'],$order,$current,$target_state,$actor,$at,$anchor);if(is_array($atomic_result)){$additional_events=(array)($atomic_result['events']??array());}}
			$order->save();
			$is_incident='incident'===$target_state||'incident'===$current; $event_domain=$is_incident?'incident':$domain;
			$event_type='incident'===$target_state?'incident.opened':('incident'===$current?'incident.resolved':$domain.'.state_changed');
			$event=CVD_Order_Events::record(array(
				'order_id'=>$order_id,'event_type'=>$event_type,'domain'=>$event_domain,'from_state'=>$current,'to_state'=>$target_state,
				'actor_user_id'=>$actor_id,'actor_role'=>self::actor_role($actor),'timestamp'=>$at,
				'source'=>sanitize_key((string)($context['source']??'cvd_order_transition_service'))?:'cvd_order_transition_service',
				'metadata'=>array_merge((array)($context['metadata']??array()),array('centralized'=>true)),
				'idempotency_key'=>CVD_Order_Events::transition_key($order_id,$event_domain,$current,$target_state,'cvd_order_transition_service',$anchor),
			));
			if(empty($event['created'])){throw new RuntimeException('canonical_event_not_created');}
			if($operation_target&&$operation_from!==$operation_target){$operation_event=CVD_Order_Events::record(array('order_id'=>$order_id,'event_type'=>'operation.state_changed','domain'=>'operation','from_state'=>$operation_from,'to_state'=>$operation_target,'actor_user_id'=>$actor_id,'actor_role'=>self::actor_role($actor),'timestamp'=>$at,'source'=>'cvd_order_transition_service','metadata'=>array('centralized'=>true,'trigger'=>'delivery.'.$target_state),'idempotency_key'=>CVD_Order_Events::transition_key($order_id,'operation',$operation_from,$operation_target,'cvd_order_transition_service',$anchor.':operation')));if(empty($operation_event['created'])){throw new RuntimeException('coupled_operation_event_not_created');}}
			if($payment_target&&$payment_from!==$payment_target){$payment_event=CVD_Order_Events::record(array('order_id'=>$order_id,'event_type'=>'payment.state_changed','domain'=>'payment','from_state'=>$payment_from,'to_state'=>$payment_target,'actor_user_id'=>$actor_id,'actor_role'=>self::actor_role($actor),'timestamp'=>$at,'source'=>'cvd_order_transition_service','metadata'=>array('centralized'=>true,'trigger'=>'delivery.'.$target_state),'idempotency_key'=>CVD_Order_Events::transition_key($order_id,'payment',$payment_from,$payment_target,'cvd_order_transition_service',$anchor.':payment')));if(empty($payment_event['created'])){throw new RuntimeException('coupled_payment_event_not_created');}}
			foreach($additional_events as $index=>$extra){$extra_domain=sanitize_key((string)($extra['domain']??''));$extra_from=sanitize_key((string)($extra['from']??''));$extra_to=sanitize_key((string)($extra['to']??''));if(!$extra_domain||!$extra_to){throw new RuntimeException('invalid_atomic_event');}$extra_event=CVD_Order_Events::record(array('order_id'=>$order_id,'event_type'=>$extra_domain.'.state_changed','domain'=>$extra_domain,'from_state'=>$extra_from,'to_state'=>$extra_to,'actor_user_id'=>$actor_id,'actor_role'=>self::actor_role($actor),'timestamp'=>$at,'source'=>'cvd_order_transition_service','metadata'=>array_merge(array('centralized'=>true,'trigger'=>'delivery.'.$target_state),(array)($extra['metadata']??array())),'idempotency_key'=>CVD_Order_Events::transition_key($order_id,$extra_domain,$extra_from,$extra_to,'cvd_order_transition_service',$anchor.':extra:'.$index)));if(empty($extra_event['created'])){throw new RuntimeException('atomic_event_not_created');}}
			if($receipt_hash){$receipts[$receipt_hash]=array('domain'=>$domain,'from'=>$current,'to'=>$target_state,'event_id'=>$event['event_id']);$order->update_meta_data(self::RECEIPTS_META,array_slice($receipts,-50,null,true));$order->save();}
			$wpdb->query('COMMIT'); $transaction_started=false; $result=self::success($current,$target_state,(string)$event['event_id']);
			if(isset($context['after_commit'])&&is_callable($context['after_commit'])){
				try{call_user_func($context['after_commit'],self::fresh_order($order_id),$result);}catch(Throwable $external_error){do_action('cvd_order_transition_after_commit_failed',$order_id,$domain,$target_state,$external_error);}
			}
			return $result;
		} catch(Throwable $error){if($transaction_started){$wpdb->query('ROLLBACK');}if(function_exists('clean_post_cache')){clean_post_cache($order_id);}return self::failure(self::SIDE_EFFECT_FAILED);}
		finally{self::release_lock($wpdb,$lock_key);}
	}

	private static function authorized( WC_Order $order,string $domain,string $from,string $to,WP_User $actor,array $context=array() ): bool {
		$is_admin=user_can($actor,'manage_woocommerce'); $is_staff=user_can($actor,'cvd_manage_sales');
		if('operation'===$domain){return $is_admin||$is_staff;} if('delivery'!==$domain){return false;}
		if(in_array($to,array('offered','assigned','picked_up','cash_returned'),true)){return $is_admin||$is_staff;}
		if('closed'===$to){return $is_admin||($is_staff&&!empty($context['allow_staff_closeout']));}
		$is_assigned=$actor->ID===absint($order->get_meta('_cvd_messenger_user_id',true)); $is_messenger=in_array('cvd_messenger',(array)$actor->roles,true);
		if('accepted'===$to&&'offered'===$from){return $is_messenger;}
		return $is_admin||($is_messenger&&$is_assigned);
	}

	private static function write_state_and_history(WC_Order $order,string $domain,string $from,string $to,int $actor_id,string $at,string $anchor,array $metadata):void{
		if('operation'===$domain){$history=$order->get_meta('_cvd_operation_history',true);$history=is_array($history)?$history:array();$history[]=array('from'=>$from,'to'=>$to,'user_id'=>$actor_id,'at'=>$at,'event_anchor'=>$anchor);$order->update_meta_data('_cvd_operation_status',$to);$order->update_meta_data('_cvd_operation_updated_at',$at);$order->update_meta_data('_cvd_operation_history',array_slice($history,-100));if(empty($metadata['suppress_note'])){$order->add_order_note(sprintf('Operación Casa Viva: %s → %s.',self::label($from),self::label($to)));}return;}
		$metadata['_canonical_event_anchor']=$anchor;$history=$order->get_meta('_cvd_delivery_history',true);$history=is_array($history)?$history:array();$history[]=array('from'=>$from,'to'=>$to,'actor_user_id'=>$actor_id,'at'=>$at,'data'=>$metadata);$order->update_meta_data('_cvd_delivery_status',$to);$order->update_meta_data('_cvd_delivery_updated_at',$at);$order->update_meta_data('_cvd_delivery_history',array_slice($history,-150));$order->add_order_note('Mensajería: '.self::delivery_label($from).' → '.self::delivery_label($to).'.');
	}
	private static function state(WC_Order $order,string $domain):string{$key='operation'===$domain?'_cvd_operation_status':'_cvd_delivery_status';$value=sanitize_key((string)$order->get_meta($key,true));return $value?:('operation'===$domain?'new':'unassigned');}
	private static function fresh_order(int $id){$order=wc_get_order($id);if($order&&method_exists($order,'get_data_store')){$store=$order->get_data_store();if(is_object($store)&&method_exists($store,'read')){$store->read($order);}}if($order&&method_exists($order,'read_meta_data')){$order->read_meta_data(true);}return $order;}
	private static function receipts(WC_Order $order):array{$value=$order->get_meta(self::RECEIPTS_META,true);return is_array($value)?$value:array();}
	private static function replay(array $receipt,string $domain,string $target):array{if($domain!==($receipt['domain']??'')||$target!==($receipt['to']??'')){return self::failure(self::CONFLICT);}return self::success((string)($receipt['from']??''),(string)($receipt['to']??''),(string)($receipt['event_id']??''),true,self::ALREADY_APPLIED);}
	private static function acquire_lock($wpdb,string $key):bool{return 1===(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$key));}
	private static function release_lock($wpdb,string $key):void{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
	private static function actor_role(WP_User $actor):string{$roles=(array)$actor->roles;return sanitize_key((string)(reset($roles)?:'unknown'));}
	private static function label(string $status):string{$labels=array('new'=>'Nuevo','confirmed'=>'Confirmado','preparing'=>'Preparando','ready'=>'Listo para mensajería','incident'=>'Incidencia');return $labels[$status]??ucfirst($status);}
	private static function delivery_label(string $status):string{$labels=array('unassigned'=>'Por ofertar','offered'=>'Oferta abierta','assigned'=>'Asignada','accepted'=>'Aceptada','to_store'=>'Va a recoger','picked_up'=>'Entregado al mensajero','handed_over'=>'En camino al cliente','delivered'=>'Entregada · dinero pendiente','cash_returned'=>'Dinero recibido','closed'=>'Cerrada','failed'=>'No entregada','returned'=>'Devuelta a tienda');return $labels[$status]??ucfirst($status);}
	private static function success(string $previous,string $new,string $event_id,bool $replay=false,string $code=''):array{return array('success'=>true,'previous_state'=>$previous,'new_state'=>$new,'event_id'=>$event_id,'idempotent_replay'=>$replay,'error_code'=>$code);}
	private static function failure(string $code,string $previous=''):array{return array('success'=>false,'previous_state'=>$previous,'new_state'=>$previous,'event_id'=>'','idempotent_replay'=>false,'error_code'=>$code);}
}
