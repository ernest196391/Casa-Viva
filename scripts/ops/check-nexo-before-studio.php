<?php
defined('WP_CLI') && WP_CLI || exit(1);
if (wp_parse_url(home_url(),PHP_URL_HOST)!=='casaviva.company') throw new RuntimeException('Incorrect site');
$start=microtime(true);
add_action('http_api_debug',static function($res,$context,$class,$args,$url) use($start){
 if(strpos($url,'/api/messaging/parse-voucher')===false) return;
 $err=is_wp_error($res)?$res->get_error_message():'';
 preg_match('/cURL error ([0-9]+)/i',$err,$m);
 printf("NEXO host=%s seconds=%.1f HTTP=%d transport=%s curl=%s\n",wp_parse_url($url,PHP_URL_HOST),microtime(true)-$start,wp_remote_retrieve_response_code($res),is_wp_error($res)?sanitize_key($res->get_error_code()):'ok',$m[1]??'none');
},10,5);
$request=new WP_REST_Request('POST','/cvd/v1/voucher/parse');
$request->set_param('text',"Casa Viva\nCliente: Prueba sintética\nTeléfono: 50000000\nDirección: Calle 1, Cotorro\nProducto: Artículo sintético, cantidad 1, 10 USD\nMensajería: 3300 CUP");
$r=CVD_Voucher_Intake::parse($request);
if(is_wp_error($r)) throw new RuntimeException(sanitize_key($r->get_error_code()));
$data=$r instanceof WP_REST_Response?$r->get_data():$r;
if(empty($data['draft']['products'])) throw new RuntimeException('Sin productos');
echo "Borrador verificado.\n";
