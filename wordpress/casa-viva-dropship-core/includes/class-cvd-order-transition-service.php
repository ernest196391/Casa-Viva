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
	private const INCIDENT_ACTIVE_SUFFIX = '_incident_active';
	private static array $cancellation_in_progress=array();
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

	/** Catálogo de destinos gobernados por este servicio para un actor, sin escribir. */
	public static function available_targets( WC_Order $order, string $domain, int $actor_user_id ): array {
		$domain = sanitize_key( $domain );
		$current = self::state( $order, $domain );
		$map = 'operation' === $domain ? self::OPERATION_TRANSITIONS : ( 'delivery' === $domain ? self::DELIVERY_TRANSITIONS : array() );
		$actor = $actor_user_id ? get_userdata( $actor_user_id ) : null;
		if ( ! $actor || empty( $map[ $current ] ) || in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) { return array(); }
		$result = array();
		foreach ( $map[ $current ] as $target ) {
			if ( self::authorized( $order, $domain, $current, $target, $actor ) ) { $result[] = $target; }
		}
		return $result;
	}

	/** Abre una incidencia sin sustituir la etapa logística u operativa. */
	public static function open_incident( int $order_id, string $affected_domain, array $context=array() ): array {
		return self::incident($order_id,$affected_domain,true,$context);
	}

	/** Resuelve hacia la misma etapa demostrada; nunca adivina un origen legacy. */
	public static function resolve_incident( int $order_id, string $affected_domain, array $context=array() ): array {
		return self::incident($order_id,$affected_domain,false,$context);
	}

	/**
	 * Cierra una recogida en tienda como una sola operación canónica.
	 * No crea estados de mensajería: exige pedido pickup, operación ready y
	 * confirmación explícita de entrega física y cobro.
	 */
	public static function complete_pickup( int $order_id, array $context=array() ): array {
		$order=wc_get_order($order_id);if(!$order){return self::failure(self::ORDER_NOT_FOUND);}
		$actor_id=array_key_exists('actor_user_id',$context)?absint($context['actor_user_id']):get_current_user_id();$actor=$actor_id?get_userdata($actor_id):null;
		if(!$actor||(!user_can($actor,'cvd_manage_sales')&&!user_can($actor,'manage_woocommerce'))){return self::failure(self::UNAUTHORIZED);}
		$idempotency_key=trim((string)($context['idempotency_key']??''));$hash=$idempotency_key?hash('sha256',$idempotency_key):'';$receipt=$hash?(self::receipts($order)[$hash]??null):null;
		if(is_array($receipt)){return self::replay($receipt,'pickup','delivered');}
		$method=sanitize_key((string)($context['collection_method']??''));$money_confirmed=!empty($context['money_confirmed']);$handover_confirmed=!empty($context['handover_confirmed']);
		if(!in_array($method,array('cash_usd','cash_cup','transfer','mixed','other'),true)||!$money_confirmed||!$handover_confirmed){return self::failure(self::PRECONDITION_FAILED,self::state($order,'operation'));}
		global $wpdb;$lock_key='cvd_transition_'.$order_id;if(!self::acquire_lock($wpdb,$lock_key)){return self::failure(self::CONFLICT);} $started=false;
		try{
			$order=self::fresh_order($order_id);$receipts=self::receipts($order);if($hash&&isset($receipts[$hash])){return self::replay($receipts[$hash],'pickup','delivered');}
			if('pickup'!==sanitize_key((string)$order->get_meta('_cvd_fulfillment_type',true))){return self::failure(self::PRECONDITION_FAILED,self::state($order,'operation'));}
			$operation=self::state($order,'operation');
			if('delivered'===$operation&&$order->has_status('completed')){return self::success('ready','delivered','',true,self::ALREADY_APPLIED);}
			if('ready'!==$operation||in_array($order->get_status(),array('completed','cancelled','refunded','failed'),true)){return self::failure(self::PRECONDITION_FAILED,$operation);}
			$wpdb->query('START TRANSACTION');$started=true;$at=current_time('mysql',true);$anchor=$idempotency_key?:wp_generate_uuid4();$cash_from=sanitize_key((string)$order->get_meta('_cvd_cash_status',true));
			$order->update_meta_data('_cvd_collection_method',$method);
			$order->update_meta_data('_cvd_collection_amount_usd',wc_format_decimal($context['collected_usd']??0,2));
			$order->update_meta_data('_cvd_collection_amount_cup',wc_format_decimal($context['collected_cup']??0,2));
			$order->update_meta_data('_cvd_collection_note',sanitize_textarea_field((string)($context['collection_note']??'')));
			$order->update_meta_data('_cvd_collection_received_by',$actor_id);$order->update_meta_data('_cvd_collection_received_at',$at);
			$order->update_meta_data('_cvd_pickup_handed_over_by',$actor_id);$order->update_meta_data('_cvd_pickup_handed_over_at',$at);$order->update_meta_data('_cvd_pickup_handover_confirmed','yes');
			$order->update_meta_data('_cvd_cash_status','verified');$order->update_meta_data('_cvd_commission_review_ready','yes');
			self::write_state_and_history($order,'operation','ready','delivered',$actor_id,$at,$anchor,array('pickup_completion'=>true));
			$extra=array();if(class_exists('CVD_Commissions')){$commission=CVD_Commissions::approve_for_closeout($order,$actor_id,$at,$anchor.':commission');if($commission){$extra[]=$commission;}}
			$order->set_status('completed','Recogida en tienda entregada y cobro confirmado.');$order->save();
			$event=self::record_event($order_id,'operation.state_changed','operation','ready','delivered',$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'fulfillment'=>'pickup','handover_confirmed'=>true,'collection_method'=>$method),$anchor);
			if('verified'!==$cash_from){self::record_event($order_id,'payment.state_changed','payment',$cash_from?:'none','verified',$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'fulfillment'=>'pickup','collection_method'=>$method),$anchor.':payment');}
			foreach($extra as $index=>$change){self::record_event($order_id,$change['domain'].'.state_changed',$change['domain'],$change['from'],$change['to'],$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'fulfillment'=>'pickup'),$anchor.':extra:'.$index);}
			if($hash){$receipts[$hash]=array('domain'=>'pickup','from'=>'ready','to'=>'delivered','event_id'=>$event['event_id']);$order->update_meta_data(self::RECEIPTS_META,array_slice($receipts,-50,null,true));$order->save();}
			$wpdb->query('COMMIT');$started=false;return self::success('ready','delivered',(string)$event['event_id']);
		}catch(Throwable $error){if($started){$wpdb->query('ROLLBACK');}if(function_exists('clean_post_cache')){clean_post_cache($order_id);}return self::failure(self::SIDE_EFFECT_FAILED);}
		finally{self::release_lock($wpdb,$lock_key);}
	}

	/** Cascada única para estados terminales WooCommerce. */
	public static function cancel( int $order_id, string $woocommerce_status, array $context=array() ): array {
		$woocommerce_status=sanitize_key($woocommerce_status);
		if(!in_array($woocommerce_status,array('cancelled','refunded','failed'),true)){return self::failure(self::INVALID_TRANSITION);}
		$order=wc_get_order($order_id);if(!$order){return self::failure(self::ORDER_NOT_FOUND);}if(!empty(self::$cancellation_in_progress[$order_id])){return self::success('',$woocommerce_status,'',true,self::ALREADY_APPLIED);}
		$actor_id=array_key_exists('actor_user_id',$context)?absint($context['actor_user_id']):get_current_user_id();
		$actor=$actor_id?get_userdata($actor_id):null;
		if(empty($context['system'])&&(!$actor||!user_can($actor,'manage_woocommerce'))){return self::failure(self::UNAUTHORIZED);}
		$idempotency_key=trim((string)($context['idempotency_key']??('woocommerce:'.$order_id.':'.$woocommerce_status)));
		$hash=hash('sha256',$idempotency_key);$receipt=self::receipts($order)[$hash]??null;
		if(is_array($receipt)){return self::replay($receipt,'cancellation',$woocommerce_status);}
		global $wpdb;$lock_key='cvd_transition_'.$order_id;if(!self::acquire_lock($wpdb,$lock_key)){return self::failure(self::CONFLICT);}
		$started=false;
		try{
			$order=self::fresh_order($order_id);$receipts=self::receipts($order);if(isset($receipts[$hash])){return self::replay($receipts[$hash],'cancellation',$woocommerce_status);}
			$delivery=self::state($order,'delivery');$operation=self::state($order,'operation');
			if('closed'===$delivery||'verified'===sanitize_key((string)$order->get_meta('_cvd_cash_status',true))){return self::failure(self::CONFLICT,$delivery);}
			if(class_exists('CVD_Messenger_Accounting')&&!CVD_Messenger_Accounting::can_void_order($order)){return self::failure(self::CONFLICT,$delivery);}
			$wpdb->query('START TRANSACTION');$started=true;$at=current_time('mysql',true);$anchor=$idempotency_key;
			if('cancelled'!==$operation){self::write_state_and_history($order,'operation',$operation,'cancelled',$actor_id,$at,$anchor.':operation',array('suppress_note'=>true));}self::maybe_fail_cancellation($context,'after_operation');
			if('cancelled'!==$delivery){self::write_state_and_history($order,'delivery',$delivery,'cancelled',$actor_id,$at,$anchor.':delivery',array('reason'=>'woocommerce_'.$woocommerce_status));}self::maybe_fail_cancellation($context,'after_delivery');
			$order->update_meta_data('_cvd_messenger_earning_status','cancelled');
			self::maybe_fail_cancellation($context,'before_commission');$events=array();if(class_exists('CVD_Commissions')){$commission=CVD_Commissions::cancel_for_order($order,$actor_id,$at,$anchor.':commission');if($commission){$events[]=$commission;}}
			if(class_exists('CVD_Messenger_Accounting')){CVD_Messenger_Accounting::void_order_atomic($order);}self::maybe_fail_cancellation($context,'after_ledger');
			if(!$order->has_status($woocommerce_status)){self::$cancellation_in_progress[$order_id]=true;$order->set_status($woocommerce_status,'Estado sincronizado por Casa Viva.');}self::maybe_fail_cancellation($context,'during_woocommerce');
			$order->save();
			$primary='';
			foreach(array(array('operation',$operation,'cancelled'),array('delivery',$delivery,'cancelled')) as $index=>$change){if($change[1]===$change[2]){continue;}$event=self::record_event($order_id,$change[0].'.state_changed',$change[0],$change[1],$change[2],$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'woocommerce_status'=>$woocommerce_status),$anchor.':'.$index);if(!$primary){$primary=(string)$event['event_id'];}}
			foreach($events as $index=>$extra){self::record_event($order_id,$extra['domain'].'.state_changed',$extra['domain'],$extra['from'],$extra['to'],$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'woocommerce_status'=>$woocommerce_status),$anchor.':extra:'.$index);}
			$receipts[$hash]=array('domain'=>'cancellation','from'=>$operation,'to'=>$woocommerce_status,'event_id'=>$primary);$order->update_meta_data(self::RECEIPTS_META,array_slice($receipts,-50,null,true));$order->save();
			$wpdb->query('COMMIT');$started=false;
			if(class_exists('CVD_Live_Tracking')){CVD_Live_Tracking::reverse_cancelled_rating($order);}
			return self::success($operation,$woocommerce_status,$primary);
		}catch(Throwable $error){if($started){$wpdb->query('ROLLBACK');}return self::failure(self::SIDE_EFFECT_FAILED);}
		finally{unset(self::$cancellation_in_progress[$order_id]);self::release_lock($wpdb,$lock_key);}
	}
	private static function maybe_fail_cancellation(array $context,string $stage):void{if($stage===(string)($context['failure_stage']??'')){throw new RuntimeException('cancellation_failure_'.$stage);}}

	private static function incident(int $order_id,string $domain,bool $opening,array $context):array{
		$domain=sanitize_key($domain);if(!in_array($domain,array('operation','delivery'),true)){return self::failure(self::INVALID_TRANSITION);}
		$order=wc_get_order($order_id);if(!$order){return self::failure(self::ORDER_NOT_FOUND);}$actor_id=array_key_exists('actor_user_id',$context)?absint($context['actor_user_id']):get_current_user_id();$actor=$actor_id?get_userdata($actor_id):null;if(!$actor){return self::failure(self::UNAUTHORIZED);}
		$note=sanitize_textarea_field((string)($context['note']??''));if('delivery'===$domain&&$opening&&''===trim($note)){return self::failure(self::PRECONDITION_FAILED);}
		$idempotency_key=trim((string)($context['idempotency_key']??''));$hash=$idempotency_key?hash('sha256',$idempotency_key):'';$receipt=$hash?(self::receipts($order)[$hash]??null):null;$target=$opening?'open':'resolved';if(is_array($receipt)){return self::replay($receipt,'incident.'.$domain,$target);}
		global $wpdb;$lock_key='cvd_transition_'.$order_id;if(!self::acquire_lock($wpdb,$lock_key)){return self::failure(self::CONFLICT);}$started=false;
		try{$order=self::fresh_order($order_id);$active='yes'===$order->get_meta('_cvd_'.$domain.self::INCIDENT_ACTIVE_SUFFIX,true);$stage=self::state($order,$domain);$legacy='incident'===$stage;
			if(!$opening&&!$active&&$legacy){$stage=self::legacy_incident_stage($order,$domain);if(!$stage){return self::failure(self::CONFLICT,'incident');}}
			if($opening&&($active||$legacy)){return self::success($stage,$stage,'',true,self::ALREADY_APPLIED);}if(!$opening&&!$active&&!$legacy){return self::success($stage,$stage,'',true,self::ALREADY_APPLIED);}
			if(!self::incident_stage_allowed($domain,$stage,$opening)){return self::failure(self::INVALID_TRANSITION,$stage);}if(!self::incident_authorized($order,$domain,$stage,$opening,$actor)){return self::failure(self::UNAUTHORIZED,$stage);}
			$receipts=self::receipts($order);if($hash&&isset($receipts[$hash])){return self::replay($receipts[$hash],'incident.'.$domain,$target);}$wpdb->query('START TRANSACTION');$started=true;$at=current_time('mysql',true);$anchor=$idempotency_key?:wp_generate_uuid4();
			if($opening){$order->update_meta_data('_cvd_'.$domain.self::INCIDENT_ACTIVE_SUFFIX,'yes');$order->update_meta_data('_cvd_'.$domain.'_incident_stage',$stage);$order->update_meta_data('_cvd_'.$domain.'_incident_opened_at',$at);$order->update_meta_data('_cvd_'.$domain.'_incident_opened_by',$actor_id);$order->update_meta_data('_cvd_'.$domain.'_incident_note',$note);self::append_incident_history($order,$domain,$stage,'incident',$actor_id,$at,$anchor,$note);}
			else{$preserved=sanitize_key((string)$order->get_meta('_cvd_'.$domain.'_incident_stage',true))?:$stage;if($legacy){$key='operation'===$domain?'_cvd_operation_status':'_cvd_delivery_status';$order->update_meta_data($key,$preserved);} $order->update_meta_data('_cvd_'.$domain.self::INCIDENT_ACTIVE_SUFFIX,'no');$order->update_meta_data('_cvd_'.$domain.'_incident_resolved_at',$at);$order->update_meta_data('_cvd_'.$domain.'_incident_resolved_by',$actor_id);self::append_incident_history($order,$domain,'incident',$preserved,$actor_id,$at,$anchor,$note);$stage=$preserved;}
			$order->save();$event=self::record_event($order_id,$opening?'incident.opened':'incident.resolved','incident',$opening?$stage:'incident',$opening?'incident':$stage,$actor,$actor_id,$at,'cvd_order_transition_service',array('centralized'=>true,'affected_domain'=>$domain,'preserved_stage'=>$stage,'note'=>$note),$anchor);
			if($hash){$receipts[$hash]=array('domain'=>'incident.'.$domain,'from'=>$stage,'to'=>$target,'event_id'=>$event['event_id']);$order->update_meta_data(self::RECEIPTS_META,array_slice($receipts,-50,null,true));$order->save();}$wpdb->query('COMMIT');$started=false;return self::success($stage,$stage,(string)$event['event_id']);
		}catch(Throwable $error){if($started){$wpdb->query('ROLLBACK');}return self::failure(self::SIDE_EFFECT_FAILED);}finally{self::release_lock($wpdb,$lock_key);}
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
	private static function incident_authorized(WC_Order $order,string $domain,string $stage,bool $opening,WP_User $actor):bool{
		$is_admin=user_can($actor,'manage_woocommerce');$is_staff=user_can($actor,'cvd_manage_sales');if('operation'===$domain){return $is_admin||$is_staff;}
		$is_messenger=in_array('cvd_messenger',(array)$actor->roles,true)&&$actor->ID===absint($order->get_meta('_cvd_messenger_user_id',true));
		if($is_admin){return true;}if($is_staff){return $opening||in_array($stage,array('unassigned','offered','accepted','to_store','picked_up','delivered','cash_returned'),true);}return $is_messenger;
	}
	private static function incident_stage_allowed(string $domain,string $stage,bool $opening):bool{
		if('operation'===$domain){return in_array($stage,$opening?array('new','confirmed','preparing','ready','with_courier'):array('confirmed','preparing','ready','with_courier'),true);}
		return in_array($stage,$opening?array('unassigned','offered','assigned','accepted','to_store','picked_up','handed_over','delivered','cash_returned'):array('assigned','accepted','to_store','picked_up','handed_over','delivered','returned','failed'),true);
	}
	private static function legacy_incident_stage(WC_Order $order,string $domain):string{
		$key='operation'===$domain?'_cvd_operation_history':'_cvd_delivery_history';$history=$order->get_meta($key,true);if(!is_array($history)||!$history){return '';}$last=end($history);if(!is_array($last)||'incident'!==sanitize_key((string)($last['to']??''))){return '';}$from=sanitize_key((string)($last['from']??''));$catalog='operation'===$domain?array('new','confirmed','preparing','ready','with_courier'):array('unassigned','offered','assigned','accepted','to_store','picked_up','handed_over','delivered','cash_returned','failed','returned');return in_array($from,$catalog,true)?$from:'';
	}
	private static function append_incident_history(WC_Order $order,string $domain,string $from,string $to,int $actor_id,string $at,string $anchor,string $note):void{
		$key='operation'===$domain?'_cvd_operation_history':'_cvd_delivery_history';$history=$order->get_meta($key,true);$history=is_array($history)?$history:array();$entry='operation'===$domain?array('from'=>$from,'to'=>$to,'user_id'=>$actor_id,'at'=>$at,'event_anchor'=>$anchor,'incident_dimension'=>true):array('from'=>$from,'to'=>$to,'actor_user_id'=>$actor_id,'at'=>$at,'data'=>array('_canonical_event_anchor'=>$anchor,'note'=>$note,'incident_dimension'=>true));$history[]=$entry;$order->update_meta_data($key,array_slice($history,'operation'===$domain?-100:-150));$order->add_order_note(($opening='incident'===$to)?'Incidencia abierta sin alterar la etapa '.$from.'.':'Incidencia resuelta; continúa en '.$to.'.');
	}
	private static function record_event(int $order_id,string $type,string $domain,string $from,string $to,$actor,int $actor_id,string $at,string $source,array $metadata,string $anchor):array{
		$event=CVD_Order_Events::record(array('order_id'=>$order_id,'event_type'=>$type,'domain'=>$domain,'from_state'=>$from,'to_state'=>$to,'actor_user_id'=>$actor_id,'actor_role'=>$actor instanceof WP_User?self::actor_role($actor):'system','timestamp'=>$at,'source'=>$source,'metadata'=>$metadata,'idempotency_key'=>CVD_Order_Events::transition_key($order_id,$domain,$from,$to,$source,$anchor)));if(empty($event['created'])){throw new RuntimeException('canonical_event_not_created');}return $event;
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
