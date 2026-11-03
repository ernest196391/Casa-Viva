<?php
if(PHP_SAPI!=='cli') exit(1);
$stage=$argv[1];
$raw=base64_decode(file_get_contents("$stage/approved.enc"),true);
$keyfile=getenv('HOME').'/.cvd-foto-revision-01/transfer.pem';
if(!$raw||!openssl_private_decrypt(substr($raw,0,256),$key,file_get_contents($keyfile),OPENSSL_PKCS1_OAEP_PADDING))exit(2);
$plain=openssl_decrypt(substr($raw,284),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,256,12),substr($raw,268,16));
if(!$plain||hash('sha256',$plain)!=='27959029d4b7ed24ce4a0713696143bb69231bb8cfb405a08e933ce99f529ae6')exit(3);
file_put_contents("$stage/approved.png",$plain);
