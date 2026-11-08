<?php
// Ejecutar aislado; no carga WordPress ni se conecta a datos reales.
define('ABSPATH', __DIR__);
function absint($x){return abs((int)$x);}
function sanitize_text_field($x){return trim((string)$x);}
class WP_Error {public function __construct(public $code, public $message, public $data){} }
class WP_REST_Response {public function __construct(public $data){} public function header($a,$b){} }
class WP_REST_Request implements ArrayAccess {public function __construct(public $p){} public function get_json_params(){return $this->p;} public function offsetExists($k):bool{return $k==='id';} public function offsetGet($k):mixed{return 6324;} public function offsetSet($k,$v):void{throw new Exception('write');} public function offsetUnset($k):void{throw new Exception('write');}}
class WC_Order {public $status='on-hold'; public function __construct(public $m){} public function get_meta($k,$single=true){return $this->m[$k]??'';} public function has_status($s){return in_array($this->status,(array)$s,true);} public function get_type(){return 'shop_order';} public function save(){throw new Exception('Unexpected write');}}
class WC_Order_Refund extends WC_Order {}
function wc_get_order($id){return $GLOBALS['order'];}
require __DIR__.'/../../wordpress/casa-viva-dropship-core/includes/class-cvd-caja-bridge.php';
$base=['_cvd_caja_charge'=>['id'=>'original-charge','receipt_only'=>true,'method'=>'cash_usd','usd_minor'=>5000,'cup_minor'=>0],'_cvd_delivery_status'=>'cash_returned','_cvd_cash_status'=>'returned','_cvd_commission_amount'=>'16.0000','_cvd_commission_status'=>'pending'];
$params=['chargeId'=>'original-charge','collectionMethod'=>'cash_usd','collectedUsd'=>50,'collectedCup'=>0];
$n=0;
function check($yes,$label){global $n;if(!$yes){throw new Exception($label);}++$n;}
$order=new WC_Order($base);$before=serialize($order);
for($i=0;$i<3;$i++){$r=CVD_Caja_Bridge::charge(new WP_REST_Request($params));check($r instanceof WP_REST_Response && $r->data['ok'] && $r->data['receiptOnly'],'replay');}
check(serialize($order)===$before,'zero mutations');
foreach(['chargeId'=>'other-charge','collectedUsd'=>60,'collectedCup'=>1,'collectionMethod'=>'cash_cup'] as $k=>$v){$p=$params;$p[$k]=$v;$r=CVD_Caja_Bridge::charge(new WP_REST_Request($p));check($r instanceof WP_Error && $r->code==='cvd_caja_receipt_conflict','mismatch safe');}
$order->m['_cvd_cash_status']='pending_return';$r=CVD_Caja_Bridge::charge(new WP_REST_Request($params));check($r instanceof WP_Error,'wrong state refused');
$order->m=$base;$order->status='completed';$order->m['_cvd_delivery_status']='closed';$order->m['_cvd_cash_status']='verified';$r=CVD_Caja_Bridge::charge(new WP_REST_Request($params));check($r instanceof WP_REST_Response,'later legitimate close replay');
$order->m=$base;unset($order->m['_cvd_caja_charge']['receipt_only']);$r=CVD_Caja_Bridge::charge(new WP_REST_Request($params));check($r instanceof WP_REST_Response && !isset($r->data['receiptOnly']),'legacy complete replay');
echo "$n PASS: replays, conflicts, unchanged commissions, legacy closure.\n";
