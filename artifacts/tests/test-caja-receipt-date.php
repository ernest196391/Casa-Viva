<?php
// Prueba aislada: sin WordPress, red ni base de datos.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);
function wp_date($fmt,$ts,$tz){return (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format($fmt);}
function absint($x){return abs((int)$x);}function sanitize_key($x){return (string)$x;}function sanitize_text_field($x){return (string)$x;}function sanitize_textarea_field($x){return (string)$x;}
function user_can($id,$cap){return $cap==='cvd_manage_sales';}function get_user_meta(){return [];}function update_user_meta(){}
function current_time($format,$gmt=false){return gmdate('Y-m-d H:i:s');}function get_option(){return 14;}function get_userdata(){return null;}function get_users(){return [];}function wp_set_current_user(){}
class WP_Error {public function __construct(public $code,public $message,public $data){} }
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_REST_Response {public function __construct(public $data){}public function header($a,$b){}public function get_data(){return $this->data;}public function is_error(){return false;}}
class WP_REST_Request implements ArrayAccess {public $params=[];public function __construct($a=null,$b=null){}public function get_json_params(){return $this->params;}public function get_param($k){return $this->params[$k]??null;}public function set_param($k,$v){$this->params[$k]=$v;}public function offsetExists($k):bool{return isset($this->params[$k]);}public function offsetGet($k):mixed{return $this->params[$k]??null;}public function offsetSet($k,$v):void{$this->params[$k]=$v;}public function offsetUnset($k):void{unset($this->params[$k]);}}
class WC_Order {public $status='on-hold';public $saves=0;public $notes=[];public function __construct(public $meta){}public function get_type(){return 'shop_order';}public function get_id(){return 8000;}public function get_meta($k,$single=true){return $this->meta[$k]??'';}public function has_status($s){return in_array($this->status,(array)$s,true);}public function update_meta_data($k,$v){$this->meta[$k]=$v;}public function delete_meta_data($k){unset($this->meta[$k]);}public function save(){++$this->saves;}public function add_order_note($n){$this->notes[]=$n;}}
class WC_Order_Refund extends WC_Order{}
class CVD_Delivery {public static function status($o){return $o->get_meta('_cvd_delivery_status',true);}}
function wc_get_order($id){return $GLOBALS['order'];}
function rest_do_request($request){global $order,$closes,$inner; $inner=$request->params;$checked=CVD_Cash_Day::check($order,$request,64);if(is_wp_error($checked)){throw new Exception('Unexpected date question');}CVD_Cash_Day::record($order,$checked,64);++$closes;$order->status='completed';return new WP_REST_Response(['message'=>'fixture']);}
require __DIR__.'/../../wordpress/casa-viva-dropship-core/includes/class-cvd-cash-day.php';
require __DIR__.'/../../wordpress/casa-viva-dropship-core/includes/class-cvd-caja-bridge.php';
$n=0;function check($yes,$label){global $n;if(!$yes){throw new Exception($label);}++$n;}
$now=strtotime('2026-11-09T13:00:00Z');
foreach([
 ['2026-11-08T04:50:00.123Z','2026-11-07','no'],
 ['2026-11-09T05:00:00Z','2026-11-09','yes'],
 ['2026-11-01T04:30:00Z','2026-11-01','no'],
 ['2026-11-01T05:30:00Z','2026-11-01','no'],
 ['2026-10-31T03:30:00Z','2026-10-30','no']
] as [$raw,$day,$answer]){$x=CVD_Cash_Day::from_receipt_timestamp($raw,$now);check(!is_wp_error($x)&&$x['day']===$day&&$x['answer']===$answer,'Havana midnight/DST');}
foreach(['bad','2026-02-30T12:00:00Z','2026-11-09T25:00:00Z','2026-11-10T00:00:00Z','2019-01-01T00:00:00Z','2026-11-08',123] as $x){check(is_wp_error(CVD_Cash_Day::from_receipt_timestamp($x,$now)),'invalid date');}
check(CVD_Cash_Day::from_receipt_timestamp(null,$now)===null,'legacy');
$raw=gmdate('Y-m-d\TH:i:s\Z',time()-3*86400);$original=CVD_Cash_Day::from_receipt_timestamp($raw);
$base=['_cvd_delivery_status'=>'delivered','_cvd_fulfillment_type'=>'delivery','_cvd_delivered_at'=>gmdate('Y-m-d H:i:s',time()-4*86400),'_cvd_commission_amount'=>'16.0000','_cvd_commission_status'=>'pending'];
$order=new WC_Order($base);$closes=0;
$req=new WP_REST_Request();$req->params=['id'=>8000,'chargeId'=>'persisted-charge','collectionMethod'=>'cash_usd','collectedUsd'=>50,'collectedCup'=>0,'cashReceivedAt'=>$raw];
$res=CVD_Caja_Bridge::charge($req);check($res->data['ok']===true,'offline accepted');
check($inner['cashReceivedDay']===$original['day']&&$inner['cashReceivedToday']==='no','original day sent');
check($order->meta['_cvd_cash_received_day']===$original['day'],'original day stored');
check($order->meta['_cvd_caja_charge']['at']===$original['at'],'original receipt instant stored');
check($order->meta['_cvd_commission_status']==='pending'&&$order->meta['_cvd_commission_amount']==='16.0000','adapter leaves commissions alone');
$state=serialize($order);CVD_Caja_Bridge::charge($req);CVD_Caja_Bridge::charge($req);check($closes===1&&serialize($order)===$state,'retries no duplicate financial call');
$order=new WC_Order($base);$req->params['cashReceivedAt']='not-date';$res=CVD_Caja_Bridge::charge($req);check(is_wp_error($res)&&$order->saves===0&&$closes===1,'invalid date no financial call');
// Short delay across midnight must preserve explicit date even below the 24h question threshold.
$order=new WC_Order($base);$order->meta['_cvd_delivered_at']=gmdate('Y-m-d H:i:s',time()-3600);
$req->params=['cashReceivedAt'=>$raw,'cashReceivedToday'=>'no','cashReceivedDay'=>$original['day']];
$c=CVD_Cash_Day::check($order,$req,64);check($c['day']===$original['day']&&$c['reasons']===[],'explicit date even without old-delivery question');
$req->params=[];check(CVD_Cash_Day::check($order,$req,64)===[],'manual flow unchanged');
echo "$n PASS: original date, Havana midnight/DST, no duplicate close, unchanged commission fields, legacy, invalid dates.\n";
