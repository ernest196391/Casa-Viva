<?php
// Ejecucion administrativa unica autorizada por Ernesto, 8-nov-2026.
// wp eval-file repair.php preflight|apply|verify /ruta/privada/manifest.json
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('CLI only'); }
$mode=$args[0]??'preflight';$file=$args[1]??'';
if(!in_array($mode,['preflight','apply','verify'],true)||!is_file($file)){throw new RuntimeException('Invalid manifest/mode');}
$manifest=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(($manifest['authorization']??'')!=='Ernesto 2026-11-08: recibir6324/6325 sin duplicar110USD ni tocarcomisiones'){throw new RuntimeException('Authorization mismatch');}
$plans=$manifest['orders'];if(array_map('intval',array_keys($plans))!==[6324,6325]){throw new RuntimeException('Scope mismatch');}
function snapshot6324($order){global $wpdb;$meta=[];foreach($order->get_meta_data() as $v){$d=$v->get_data();$meta[$d['key']]=$d['value'];}ksort($meta);$items=[];foreach($order->get_items() as $k=>$v){$items[$k]=$v->get_data();}return json_decode(json_encode(['status'=>$order->get_status(),'total'=>$order->get_total(),'meta'=>$meta,'items'=>$items,'messenger_ledger'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cvd_messenger_ledger WHERE order_id=%d ORDER BY id",$order->get_id()),ARRAY_A)]),true);}
function protected6324($s){$m=[];foreach($s['meta'] as $k=>$v){if(preg_match('/commission|gestora|messenger_(earning|ledger)|collection|payment_obligation/',$k)){$m[$k]=$v;}}return ['status'=>$s['status'],'total'=>$s['total'],'items'=>$s['items'],'meta'=>$m,'messenger_ledger'=>$s['messenger_ledger']];}
$actor=absint(get_option('cvd_panel_actor_user_id',0));if(!$actor||!user_can($actor,'manage_woocommerce')){throw new RuntimeException('Admin signer unavailable');}
foreach($plans as $id=>$p){if(($id==6324?$p['usd_minor']!==5000:$p['usd_minor']!==6000)||($p['before']['total']??'')!==($id==6324?'50.00':'60.00')){throw new RuntimeException('Amount mismatch');}}
$before=[];
foreach($plans as $id=>$p){$o=wc_get_order($id);if(!$o){throw new RuntimeException('Order missing');}$o->read_meta_data(true);$before[$id]=snapshot6324($o);
 if($mode==='preflight'){
  if($before[$id]!==$p['before']){throw new RuntimeException('Snapshot changed '.$id);}
  if($o->get_meta('_cvd_cash_status',true)!=='pending_return'||$o->get_meta('_cvd_delivery_status',true)!=='delivered'||$o->get_meta('_cvd_caja_charge',true)||!$o->has_status('on-hold')){throw new RuntimeException('Unexpected state');}
 }
}
if($mode==='preflight'){echo json_encode(['ok'=>true,'mode'=>$mode,'actor'=>$actor,'orders'=>array_keys($plans)]);return;}
wp_set_current_user($actor);$results=[];
foreach($plans as $id=>$p){
 if($mode==='apply'){
 $result=CVD_Order_Transition_Service::transition((int)$id,'delivery','cash_returned',[
  'actor_user_id'=>$actor,'idempotency_key'=>'caja-receipt-only:'.$p['charge_id'],'source'=>'caja_receipt_reconciliation',
  'coupled_payment_state'=>'returned','metadata'=>['authorization'=>$manifest['authorization'],'charge_id'=>$p['charge_id'],'source_event'=>$p['source_event'],'received_at'=>$p['received_at'],'commission_preserved'=>true],
  'precondition'=>static function($locked)use($p){return snapshot6324($locked)===$p['before']?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},
  'atomic_mutation'=>static function($locked,$from,$to,$actor,$at)use($p){
   $locked->update_meta_data('_cvd_caja_charge',['id'=>$p['charge_id'],'receipt_only'=>true,'method'=>'cash_usd','usd_minor'=>$p['usd_minor'],'cup_minor'=>0,'by'=>'Zaymi','clerk'=>64,'device'=>'pc-casa-viva-1021-281b','at'=>$p['received_at'],'reconciled_at'=>$at,'authorized_by'=>$actor->ID,'source_event'=>$p['source_event']]);
   $locked->update_meta_data('_cvd_cash_returned_by',64);
   $locked->update_meta_data('_cvd_cash_returned_at',$p['received_at']);
   $locked->update_meta_data('_cvd_cash_received_day','2026-11-08');
   $locked->update_meta_data('_cvd_cash_day_reason','conciliacion_cobro_caja_existente');
   $locked->update_meta_data('_cvd_cash_day_by',$actor->ID);
   $locked->update_meta_data('_cvd_cash_day_at',$at);
  },
  'atomic_after_state'=>static function($locked)use($p){if(protected6324(snapshot6324($locked))!==protected6324($p['before'])){throw new RuntimeException('Protected fields changed');}return [];},
 ]);
 if(empty($result['success'])){throw new RuntimeException('Transition failed '.$id.' '.json_encode($result));}
 $results[$id]['transition']=$result;
 }
 $o=wc_get_order($id);$o->read_meta_data(true);$after=snapshot6324($o);
 if(protected6324($after)!==protected6324($p['before'])){throw new RuntimeException('Protected snapshot changed '.$id);}
 if($o->get_meta('_cvd_cash_status',true)!=='returned'||$o->get_meta('_cvd_delivery_status',true)!=='cash_returned'){throw new RuntimeException('Receipt not reflected');}
 $r=new WP_REST_Request('POST','/');$r->set_param('id',$id);$r->set_body(json_encode(['chargeId'=>$p['charge_id'],'collectionMethod'=>'cash_usd','collectedUsd'=>$p['usd_minor']/100,'collectedCup'=>0]));$r->set_header('content-type','application/json');
 for($i=0;$i<2;$i++){$a=CVD_Caja_Bridge::charge($r);if(is_wp_error($a)||empty($a->get_data()['receiptOnly'])){throw new RuntimeException('Replay failed');}}
 $fresh=wc_get_order($id);$fresh->read_meta_data(true);if(snapshot6324($fresh)!==$after){throw new RuntimeException('Replay changed data');}
 $results[$id]['verified']=['cash'=>'returned','delivery'=>'cash_returned','deliveryLabel'=>CVD_Delivery::label('cash_returned'),'commission'=>$o->get_meta('_cvd_commission_amount',true),'commissionStatus'=>$o->get_meta('_cvd_commission_status',true),'protected_unchanged'=>true,'replay_twice_unchanged'=>true,'charge_id'=>$p['charge_id']];
}
echo json_encode(['ok'=>true,'mode'=>$mode,'results'=>$results],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
