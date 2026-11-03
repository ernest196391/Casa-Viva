<?php
if(!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='casaviva.company') throw new RuntimeException('Sitio incorrecto.');
$stage=getenv('CV_PHOTO_STAGE');
if(!$stage || !is_dir($stage)) throw new RuntimeException('Falta directorio privado.');
$u=get_user_by('login','zayri');
if(!$u || !$u->has_cap(CVD_Content_Studio::CAP) || $u->has_cap('manage_options')) throw new RuntimeException('Cuenta incorrecta.');
$userSessions=WP_Session_Tokens::get_instance($u->ID);
if(getenv('CV_PHOTO_MODE')==='revoke'){
 if(file_exists("$stage/session-token")) $userSessions->destroy(trim(file_get_contents("$stage/session-token")));
 return;
}
$products=get_posts(['post_type'=>'product','post_status'=>'publish','s'=>'cortinero extensible','posts_per_page'=>50]);
foreach($products as $i){
 if(str_contains(wp_strip_all_tags($i->post_title),'122') && str_contains($i->post_title,'213') && str_contains($i->post_title,'espiral')){
  $p=wc_get_product($i->ID); break;
 }
}
if(empty($p)) throw new RuntimeException('No se encontró el cortinero concreto.');
$state=[$p->get_id(),$p->get_status(),$p->get_image_id(),$p->get_gallery_image_ids(),$p->get_price(),$p->get_stock_quantity(),$p->get_date_modified()->getTimestamp()];
if(getenv('CV_PHOTO_MODE')==='compare'){
 if(json_decode(file_get_contents("$stage/product-before"),true)!==$state) throw new RuntimeException('La ficha pública cambió durante la prueba.');
 echo "Producto, foto principal, galería, precio y existencias verificados.\n"; return;
}
file_put_contents("$stage/product-before",wp_json_encode($state));
$photo=get_attached_file($p->get_image_id()); if(!$photo || !copy($photo,"$stage/reference.jpg"))throw new RuntimeException('Foto inaccesible.');
$end=time()+900;$token=$userSessions->create($end);file_put_contents("$stage/session-token",$token);
$cookies=[];
foreach([LOGGED_IN_COOKIE=>'logged_in',SECURE_AUTH_COOKIE=>'secure_auth'] as $name=>$scheme){
 $cookies[]=['name'=>$name,'value'=>wp_generate_auth_cookie($u->ID,$end,$scheme,$token),'domain'=>'casaviva.company','path'=>'/','secure'=>true,'httpOnly'=>true,'sameSite'=>'Lax','expires'=>$end];
}
file_put_contents("$stage/browser.json",wp_json_encode(['cookies'=>$cookies,'id'=>$p->get_id(),'title'=>$p->get_name()]));
