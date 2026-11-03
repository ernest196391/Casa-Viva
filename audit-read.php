<?php
if(!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='casaviva.company') throw new RuntimeException('Sitio equivocado.');
function cv_audit_get($id=0){$r=new WP_REST_Request('GET','/casa-viva/v1/contenido/products');$r->set_query_params(['id'=>$id]);return rest_do_request($r);}
wp_set_current_user(0);
if(cv_audit_get()->get_status()<400) throw new RuntimeException('Acceso anónimo inesperado.');
$user=get_user_by('login','zayri');if(!$user)throw new RuntimeException('Falta perfil.');
wp_set_current_user($user->ID);
if(current_user_can('manage_options')||current_user_can('manage_woocommerce')||cv_audit_get()->get_status()!==200)throw new RuntimeException('Permisos de perfil inesperados.');
echo "AUDIT LIVE visitante bloqueado, Zayri lee el catálogo con permisos limitados.\n";
foreach(CVD_Photo_AI::providers() as $k=>$v)echo "AUDIT LIVE proveedor ".$k.' '.($v?'configurado':'sin configurar')."\n";
$products=get_posts(['post_type'=>'product','post_status'=>'publish','s'=>'cortinero extensible','posts_per_page'=>50]);
foreach($products as $post){if(str_contains($post->post_title,'122')&&str_contains($post->post_title,'213')&&str_contains($post->post_title,'espiral')){
$d=cv_audit_get($post->ID)->get_data();$raw=$d['review']??'';$bytes=base64_decode(substr($raw,strpos($raw,',')+1),true);
echo 'AUDIT LIVE vista privada coincide con la aprobada: '.(is_string($bytes)&&hash('sha256',$bytes)==='27959029d4b7ed24ce4a0713696143bb69231bb8cfb405a08e933ce99f529ae6'?'sí':'no')."\n";return;}}
echo "AUDIT LIVE no se encontró el producto de control.\n";