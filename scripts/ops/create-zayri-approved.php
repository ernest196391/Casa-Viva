<?php
/** Single approved onboarding. Only a public encryption key is sent to the server. */
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'casaviva.company' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Wrong environment.' ); }
$expected = 'd39e7b3a5cf9da9fae151beea667eee5541422dd';
$marker = WP_PLUGIN_DIR . '/casa-viva-dropship-core/.cvd-deployed-sha';
if ( ! is_file( $marker ) || $expected !== trim( file_get_contents( $marker ) ) || ! class_exists( 'CVD_Content_Studio' ) ) { throw new RuntimeException( 'Approved studio release is not deployed.' ); }
$public = file_get_contents( '/tmp/cvd-zayri-onboarding.pub' );
if ( ! $public || ! openssl_pkey_get_public( $public ) ) { throw new RuntimeException( 'Missing public encryption key.' ); }
$fingerprint = hash( 'sha256', $public );
$existing = get_user_by( 'login', 'zayri' );
if ( $existing ) {
	if ( $existing->has_cap( CVD_Content_Studio::CAP ) && $fingerprint === get_user_meta( $existing->ID, '_cvd_content_setup_key', true ) && '1' === get_user_meta( $existing->ID, '_cvd_content_temporary_password', true ) ) {
		$encrypted = get_user_meta( $existing->ID, '_cvd_content_setup_envelope', true );
		if ( $encrypted ) { echo 'ZAYRI_CREDENTIALS_ENCRYPTED=' . $encrypted . PHP_EOL; return; }
	}
	throw new RuntimeException( 'Account already exists; no password or permissions changed.' );
}
$password = wp_generate_password( 20, false );
$payload = wp_json_encode( array( 'u' => 'zayri', 'p' => $password, 'url' => home_url( '/?cvd_contenido=1' ) ) );
if ( ! openssl_public_encrypt( $payload, $ciphertext, $public, OPENSSL_PKCS1_OAEP_PADDING ) ) { throw new RuntimeException( 'Encryption failed; no account created.' ); }
$id = wp_insert_user( array( 'user_login' => 'zayri', 'display_name' => 'Zayri', 'user_pass' => $password, 'role' => 'subscriber' ) );
if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
$user = new WP_User( $id ); $user->add_cap( CVD_Content_Studio::CAP );
update_user_meta( $id, '_cvd_content_temporary_password', '1' );
update_user_meta( $id, '_cvd_content_setup_key', $fingerprint );
update_user_meta( $id, '_cvd_content_setup_envelope', base64_encode( $ciphertext ) );
if ( $user->has_cap( 'manage_woocommerce' ) || ! $user->has_cap( CVD_Content_Studio::CAP ) ) { throw new RuntimeException( 'Unexpected account permissions.' ); }
echo 'ZAYRI_CREDENTIALS_ENCRYPTED=' . base64_encode( $ciphertext ) . PHP_EOL;
