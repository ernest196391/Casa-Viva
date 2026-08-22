<?php
/**
 * Plugin Name: Casa Viva Ruta MVP
 * Description: Piloto móvil para prellamada, preparación, ruta, cobros y tarifas de mensajería.
 * Version: 0.1.0
 * Author: Casa Viva
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

final class CVR_MVP {
    private const MANIFEST = 'cvr_mvp_manifest';
    private const RATES = 'cvr_mvp_rates';
    private const NONCE = 'cvr_mvp_action';

    public static function boot(): void {
        add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
        add_action( 'admin_post_cvr_save_order', array( __CLASS__, 'save_order' ) );
        add_action( 'admin_post_cvr_order_action', array( __CLASS__, 'order_action' ) );
        add_action( 'admin_post_cvr_save_rate', array( __CLASS__, 'save_rate' ) );
    }

    private static function roles(): array {
        return (array) wp_get_current_user()->roles;
    }

    private static function can_operate(): bool {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || (bool) array_intersect( array( 'cvd_operator', 'cvd_clerk', 'cvd_messenger' ), self::roles() );
    }

    private static function can_edit(): bool {
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || (bool) array_intersect( array( 'cvd_operator', 'cvd_clerk' ), self::roles() );
    }

    private static function app_url( string $tab = 'hoy' ): string {
        return add_query_arg( 'tab', $tab, home_url( '/ruta-cv/' ) );
    }

    private static function rates_url(): string {
        return home_url( '/tarifas-mensajeria/' );
    }

    public static function route(): void {
        $path = trailingslashit( (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH ) );
        if ( '/tarifas-mensajeria/' === $path ) {
            self::render_rates();
            exit;
        }
        if ( '/ruta-cv/' !== $path ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( self::app_url() ) );
            exit;
        }
        if ( ! self::can_operate() ) {
            status_header( 403 );
            wp_die( esc_html__( 'No tienes acceso a la ruta operativa.', 'casa-viva-ruta' ) );
        }
        self::render_app();
        exit;
    }

    private static function manifest(): array {
        $value = get_option( self::MANIFEST, array() );
        return is_array( $value ) ? array_values( $value ) : array();
    }

    private static function rates(): array {
        $value = get_option( self::RATES, array() );
        return is_array( $value ) ? array_values( $value ) : array();
    }

    private static function phone( string $value ): string {
        return preg_replace( '/[^0-9+]/', '', $value ) ?: '';
    }

    private static function clean_order( array $raw, array $old = array() ): array {
        return array(
            'uid' => sanitize_key( $old['uid'] ?? wp_generate_uuid4() ),
            'order_id' => sanitize_text_field( $raw['order_id'] ?? $old['order_id'] ?? '' ),
            'original_url' => esc_url_raw( $raw['original_url'] ?? $old['original_url'] ?? '' ),
            'customer' => sanitize_text_field( $raw['customer'] ?? $old['customer'] ?? '' ),
            'phone' => self::phone( sanitize_text_field( $raw['phone'] ?? $old['phone'] ?? '' ) ),
            'alt_phone' => self::phone( sanitize_text_field( $raw['alt_phone'] ?? $old['alt_phone'] ?? '' ) ),
            'address' => sanitize_textarea_field( $raw['address'] ?? $old['address'] ?? '' ),
            'zone' => sanitize_text_field( $raw['zone'] ?? $old['zone'] ?? '' ),
            'reference' => sanitize_text_field( $raw['reference'] ?? $old['reference'] ?? '' ),
            'seller' => sanitize_text_field( $raw['seller'] ?? $old['seller'] ?? '' ),
            'products' => sanitize_textarea_field( $raw['products'] ?? $old['products'] ?? '' ),
            'product_collect' => sanitize_text_field( $raw['product_collect'] ?? $old['product_collect'] ?? '' ),
            'delivery_total' => sanitize_text_field( $raw['delivery_total'] ?? $old['delivery_total'] ?? '' ),
            'delivery_collect' => sanitize_text_field( $raw['delivery_collect'] ?? $old['delivery_collect'] ?? '' ),
            'delivery_note' => sanitize_text_field( $raw['delivery_note'] ?? $old['delivery_note'] ?? '' ),
            'alert' => sanitize_textarea_field( $raw['alert'] ?? $old['alert'] ?? '' ),
            'time_window' => sanitize_text_field( $raw['time_window'] ?? $old['time_window'] ?? '' ),
            'contact_status' => sanitize_key( $old['contact_status'] ?? 'no_llamado' ),
            'prepared' => ! empty( $old['prepared'] ),
            'loaded' => ! empty( $old['loaded'] ),
            'route_status' => sanitize_key( $old['route_status'] ?? 'pendiente' ),
            'route_order' => absint( $old['route_order'] ?? 999 ),
        );
    }

    private static function index_of( array $manifest, string $uid ): int {
        foreach ( $manifest as $i => $order ) {
            if ( ( $order['uid'] ?? '' ) === $uid ) return (int) $i;
        }
        return -1;
    }

    public static function save_order(): void {
        if ( ! self::can_edit() ) wp_die( 'Sin permiso.' );
        check_admin_referer( self::NONCE );
        $raw = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array();
        $manifest = self::manifest();
        $order = self::clean_order( $raw );
        $order['route_order'] = count( $manifest ) + 1;
        $manifest[] = $order;
        update_option( self::MANIFEST, $manifest, false );
        wp_safe_redirect( self::app_url() );
        exit;
    }

    public static function order_action(): void {
        if ( ! self::can_operate() ) wp_die( 'Sin permiso.' );
        check_admin_referer( self::NONCE );
        $uid = sanitize_key( wp_unslash( $_POST['uid'] ?? '' ) );
        $do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
        $tab = sanitize_key( wp_unslash( $_POST['tab'] ?? 'hoy' ) );
        $manifest = self::manifest();
        $i = self::index_of( $manifest, $uid );
        if ( $i >= 0 ) {
            if ( 0 === strpos( $do, 'contact_' ) ) $manifest[$i]['contact_status'] = str_replace( 'contact_', '', $do );
            elseif ( 'prepared' === $do && self::can_edit() ) $manifest[$i]['prepared'] = true;
            elseif ( 'loaded' === $do && self::can_edit() ) $manifest[$i]['loaded'] = true;
            elseif ( in_array( $do, array( 'pendiente','entregado','incidencia','reprogramado' ), true ) ) $manifest[$i]['route_status'] = $do;
            elseif ( in_array( $do, array( 'up','down' ), true ) ) {
                $target = 'up' === $do ? $i - 1 : $i + 1;
                if ( isset( $manifest[$target] ) ) {
                    $tmp = $manifest[$i]; $manifest[$i] = $manifest[$target]; $manifest[$target] = $tmp;
                }
            }
            foreach ( $manifest as $n => &$item ) $item['route_order'] = $n + 1;
            unset( $item );
            update_option( self::MANIFEST, array_values( $manifest ), false );
        }
        wp_safe_redirect( self::app_url( $tab ) );
        exit;
    }

    public static function save_rate(): void {
        if ( ! self::can_edit() ) wp_die( 'Sin permiso.' );
        check_admin_referer( self::NONCE );
        $zone = sanitize_text_field( wp_unslash( $_POST['zone'] ?? '' ) );
        $price = sanitize_text_field( wp_unslash( $_POST['price'] ?? '' ) );
        if ( $zone && $price ) {
            $rates = self::rates();
            $rates[] = array(
                'origin' => sanitize_text_field( wp_unslash( $_POST['origin'] ?? 'Casa Viva' ) ),
                'zone' => $zone,
                'price' => $price,
                'notes' => sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                'updated' => current_time( 'Y-m-d' ),
            );
            update_option( self::RATES, $rates, false );
        }
        wp_safe_redirect( self::rates_url() );
        exit;
    }

    private static function css(): string {
        return 'body{margin:0;background:#f6f1e8;color:#173329;font-family:Inter,system-ui,sans-serif}.cv{max-width:760px;margin:auto;padding:16px 14px 92px}.cv h1{font-size:25px;margin:8px 0}.cv h2{font-size:19px}.muted{color:#65736d}.card{background:#fff;border:1px solid #e6dfd3;border-radius:18px;padding:15px;margin:12px 0;box-shadow:0 4px 18px #0000000a}.alert{background:#fff2d8}.critical{background:#fff0ef}.row{display:flex;gap:8px;flex-wrap:wrap}.grow{flex:1}.btn,button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:0;border-radius:12px;padding:0 14px;background:#214d3c;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.soft{background:#e8efe9!important;color:#173329!important}.warn{background:#8a4f2d!important}.cv input,.cv textarea{width:100%;box-sizing:border-box;border:1px solid #cfc8bd;border-radius:10px;padding:11px;background:#fff;margin:4px 0 10px;font:inherit}.cv label{font-size:13px;font-weight:700}.nav{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #ddd;display:flex;justify-content:center;z-index:10}.nav a{flex:1;max-width:150px;text-align:center;padding:13px 4px;color:#264b3d;text-decoration:none;font-size:12px}.nav a.on{font-weight:800;background:#edf3ee}.status{display:inline-block;padding:4px 9px;border-radius:999px;background:#edf3ee;font-size:12px;font-weight:800}.big{font-size:22px;font-weight:800}.money{font-size:18px;font-weight:800}@media(max-width:520px){.btn,button{width:100%}}';
    }

    private static function head( string $title ): void {
        status_header( 200 ); nocache_headers();
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title><style>'.self::css().'</style></head><body><main class="cv">';
    }

    private static function foot( string $tab = '' ): void {
        if ( $tab ) {
            $items = array( 'hoy'=>'Hoy','contactos'=>'Contactos','preparar'=>'Preparar','ruta'=>'Ruta','cobros'=>'Cobros' );
            echo '<nav class="nav">';
            foreach ( $items as $k=>$label ) echo '<a class="'.esc_attr($tab===$k?'on':'').'" href="'.esc_url(self::app_url($k)).'">'.esc_html($label).'</a>';
            echo '</nav>';
        }
        echo '</main></body></html>';
    }

    private static function action_button( string $uid, string $do, string $label, string $tab, string $class = 'soft' ): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="grow">'; wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="cvr_order_action"><input type="hidden" name="uid" value="'.esc_attr($uid).'"><input type="hidden" name="do" value="'.esc_attr($do).'"><input type="hidden" name="tab" value="'.esc_attr($tab).'"><button class="'.esc_attr($class).'">'.esc_html($label).'</button></form>';
    }

    private static function render_app(): void {
        $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'hoy' ) );
        $manifest = self::manifest();
        self::head( 'Casa Viva · Ruta' );
        echo '<div class="row"><div class="grow"><div class="muted">CASA VIVA · PILOTO</div><h1>Ruta de hoy</h1></div><a class="btn soft" href="'.esc_url(self::rates_url()).'">Tarifas</a></div>';
        if ( 'contactos' === $tab ) self::contacts( $manifest );
        elseif ( 'preparar' === $tab ) self::prepare( $manifest );
        elseif ( 'ruta' === $tab ) self::route_view( $manifest );
        elseif ( 'cobros' === $tab ) self::money( $manifest );
        else { $tab='hoy'; self::today( $manifest ); }
        self::foot( $tab );
    }

    private static function today( array $manifest ): void {
        $done = count(array_filter($manifest, static fn($o)=>'entregado'===($o['route_status']??'')));
        $called = count(array_filter($manifest, static fn($o)=>'no_llamado'!==($o['contact_status']??'no_llamado')));
        echo '<div class="card"><div class="row"><div class="grow"><div class="big">'.count($manifest).'</div><div class="muted">pedidos</div></div><div class="grow"><div class="big">'.$called.'/'.count($manifest).'</div><div class="muted">contactados</div></div><div class="grow"><div class="big">'.$done.'</div><div class="muted">entregados</div></div></div></div>';
        if ( self::can_edit() ) { echo '<details class="card"><summary><strong>+ Añadir pedido</strong></summary>'; self::order_form(); echo '</details>'; }
        foreach ( $manifest as $o ) {
            echo '<section class="card '.(!empty($o['alert'])?'alert':'').'"><div class="row"><div class="grow"><strong>'.esc_html($o['order_id']?:'Sin ID').'</strong><div class="muted">'.esc_html($o['zone']).'</div></div><span class="status">'.esc_html($o['route_status']).'</span></div><h2>'.esc_html($o['customer']).'</h2><div>'.nl2br(esc_html($o['products'])).'</div>';
            if ( $o['alert'] ) echo '<p><strong>⚠ '.nl2br(esc_html($o['alert'])).'</strong></p>';
            echo '<div class="row"><a class="btn" href="tel:'.esc_attr($o['phone']).'">Llamar</a><a class="btn soft" href="'.esc_url(self::app_url('ruta')).'">Ver ruta</a></div></section>';
        }
    }

    private static function order_form(): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field(self::NONCE); echo '<input type="hidden" name="action" value="cvr_save_order">';
        $fields=array('order_id'=>'ID pedido','customer'=>'Cliente','phone'=>'Teléfono principal','alt_phone'=>'Teléfono alternativo','zone'=>'Zona','seller'=>'Gestora','products'=>'Productos / cantidades','product_collect'=>'Cobrar producto','delivery_total'=>'Mensajería total','delivery_collect'=>'Cobrar mensajería al cliente','delivery_note'=>'Nota mensajería','time_window'=>'Horario/franja','reference'=>'Referencia','original_url'=>'Link original');
        foreach($fields as $k=>$label) echo '<label>'.esc_html($label).'<input name="order['.esc_attr($k).']"></label>';
        echo '<label>Dirección<textarea name="order[address]" rows="2"></textarea></label><label>Alertas / vuelto / llamada<textarea name="order[alert]" rows="2"></textarea></label><button>Guardar pedido</button></form>';
    }

    private static function contacts( array $manifest ): void {
        $missing=count(array_filter($manifest, static fn($o)=>'no_llamado'===($o['contact_status']??'no_llamado')));
        echo '<div class="card"><div class="big">'.$missing.'</div><div class="muted">clientes sin gestionar</div></div>';
        foreach($manifest as $o){
            echo '<section class="card"><div class="row"><div class="grow"><h2>'.esc_html($o['customer']).'</h2><div>'.esc_html($o['zone']).'</div><div class="money">'.esc_html($o['phone']).'</div>'.($o['alt_phone']?'<div>Alternativo: '.esc_html($o['alt_phone']).'</div>':'').'</div><span class="status">'.esc_html($o['contact_status']).'</span></div><div class="row"><a class="btn" href="tel:'.esc_attr($o['phone']).'">📞 Llamar</a>'.($o['alt_phone']?'<a class="btn soft" href="tel:'.esc_attr($o['alt_phone']).'">Alternativo</a>':'').'</div><div class="row">';
            self::action_button($o['uid'],'contact_confirmado','✓ Confirmó','contactos'); self::action_button($o['uid'],'contact_no_responde','No responde','contactos','warn'); self::action_button($o['uid'],'contact_reprogramar','Reprogramar','contactos'); echo '</div></section>';
        }
    }

    private static function prepare( array $manifest ): void {
        echo '<div class="card"><h2>Preparación de tienda</h2><p class="muted">Verifica cada pedido contra la carga física.</p></div>';
        foreach($manifest as $o){
            echo '<section class="card"><div class="row"><div class="grow"><strong>'.esc_html($o['order_id']).'</strong><h2>'.esc_html($o['zone']).'</h2><div>'.nl2br(esc_html($o['products'])).'</div><div class="muted">Gestora: '.esc_html($o['seller']?:'—').'</div></div><div><span class="status">'.($o['prepared']?'PREPARADO':'PENDIENTE').'</span><br><span class="status">'.($o['loaded']?'CARGADO':'NO CARGADO').'</span></div></div>';
            if($o['alert']) echo '<p><strong>⚠ '.esc_html($o['alert']).'</strong></p>';
            if(self::can_edit()){echo '<div class="row">'; if(!$o['prepared']) self::action_button($o['uid'],'prepared','✓ Preparado','preparar'); if(!$o['loaded']) self::action_button($o['uid'],'loaded','✓ Cargado al mensajero','preparar'); echo '</div>';}
            if($o['original_url']) echo '<p><a href="'.esc_url($o['original_url']).'" target="_blank" rel="noopener">Ver pedido original ↗</a></p>';
            echo '</section>';
        }
    }

    private static function route_view( array $manifest ): void {
        usort($manifest, static fn($a,$b)=>($a['route_order']??999)<=>($b['route_order']??999));
        foreach($manifest as $n=>$o){
            echo '<section class="card"><div class="muted">PARADA '.($n+1).' DE '.count($manifest).'</div><div class="row"><div class="grow"><h2>'.esc_html($o['zone']).'</h2><strong>'.esc_html($o['customer']).'</strong></div><span class="status">'.esc_html($o['route_status']).'</span></div><p>'.nl2br(esc_html($o['address'])).'</p>';
            if($o['reference']) echo '<p>📍 '.esc_html($o['reference']).'</p>'; if($o['alert']) echo '<div class="card critical"><strong>⚠ '.nl2br(esc_html($o['alert'])).'</strong></div>';
            echo '<div><strong>'.nl2br(esc_html($o['products'])).'</strong></div><p>Producto: <strong>'.esc_html($o['product_collect']).'</strong><br>Mensajería a cobrar: <strong>'.esc_html($o['delivery_collect']?:$o['delivery_total']).'</strong></p>'; if($o['delivery_note']) echo '<p class="muted">'.esc_html($o['delivery_note']).'</p>';
            $map='https://www.openstreetmap.org/search?query='.rawurlencode(trim($o['address'].' '.$o['zone']));
            echo '<div class="row"><a class="btn" href="tel:'.esc_attr($o['phone']).'">📞 Llamar</a><a class="btn soft" target="_blank" rel="noopener" href="'.esc_url($map).'">🗺 Abrir mapa</a></div><div class="row">'; self::action_button($o['uid'],'up','↑ Subir','ruta'); self::action_button($o['uid'],'down','↓ Bajar','ruta'); echo '</div><div class="row">'; self::action_button($o['uid'],'entregado','✓ ENTREGADO','ruta'); self::action_button($o['uid'],'incidencia','Incidencia','ruta','warn'); self::action_button($o['uid'],'reprogramado','Reprogramar','ruta'); echo '</div></section>';
        }
    }

    private static function money( array $manifest ): void {
        echo '<div class="card"><h2>Cobros esperados</h2><p class="muted">Separados por pedido y moneda.</p></div>';
        foreach($manifest as $o){echo '<section class="card"><strong>'.esc_html($o['order_id']).' · '.esc_html($o['customer']).'</strong><p>Producto: <span class="money">'.esc_html($o['product_collect']?:'—').'</span><br>Mensajería total: <strong>'.esc_html($o['delivery_total']?:'—').'</strong><br><span class="money">COBRAR MENSAJERÍA: '.esc_html($o['delivery_collect']?:$o['delivery_total']?:'—').'</span></p>'.($o['delivery_note']?'<div class="card alert">'.esc_html($o['delivery_note']).'</div>':'').'</section>';}
    }

    private static function render_rates(): void {
        self::head('Tarifas de mensajería · Casa Viva'); echo '<div class="muted">CASA VIVA</div><h1>Tarifas de mensajería</h1><p>Consulta la tarifa vigente antes de cerrar un pedido.</p><div class="card"><label>Buscar zona<input id="rateSearch" type="search" placeholder="Ej. Alamar, Boyeros, Guanabo"></label></div><div id="rates">';
        $rates=self::rates(); if(!$rates) echo '<div class="card alert">Todavía no hay tarifas publicadas en este piloto.</div>';
        foreach($rates as $r){echo '<section class="card rate" data-zone="'.esc_attr(strtolower($r['zone'])).'"><div class="muted">Desde '.esc_html($r['origin']).'</div><h2>'.esc_html($r['zone']).'</h2><div class="big">'.esc_html($r['price']).'</div>'.($r['notes']?'<p>'.esc_html($r['notes']).'</p>':'').'<div class="muted">Actualizada: '.esc_html($r['updated']).'</div></section>';}
        echo '</div>';
        if(is_user_logged_in()&&self::can_edit()){echo '<details class="card"><summary><strong>+ Añadir tarifa</strong></summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field(self::NONCE); echo '<input type="hidden" name="action" value="cvr_save_rate"><label>Origen<input name="origin" value="Casa Viva"></label><label>Zona / destino<input name="zone" required></label><label>Precio<input name="price" placeholder="Ej. 3,800 CUP" required></label><label>Notas<input name="notes"></label><button>Publicar tarifa</button></form></details>';}
        echo '<script>const q=document.getElementById("rateSearch");q&&q.addEventListener("input",()=>document.querySelectorAll(".rate").forEach(x=>x.style.display=x.dataset.zone.includes(q.value.toLowerCase())?"block":"none"));</script>'; if(is_user_logged_in()&&self::can_operate()) echo '<p><a class="btn" href="'.esc_url(self::app_url()).'">Volver a Ruta</a></p>'; self::foot();
    }
}

add_action( 'plugins_loaded', array( 'CVR_MVP', 'boot' ) );
