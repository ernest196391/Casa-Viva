<?php

defined("ABSPATH") || exit();

/** Inventario auditable sobre el stock oficial de WooCommerce. */
final class CVD_Inventory
{
    private const CODE_META = "_cvd_inventory_code";

    public static function register(): void
    {
        add_shortcode("casa_viva_inventory", [__CLASS__, "render"]);
        add_action("rest_api_init", [__CLASS__, "routes"]);
        add_action("save_post_product", [__CLASS__, "ensure_product_code"], 20);
        add_action(
            "woocommerce_save_product_variation",
            [__CLASS__, "ensure_product_code"],
            20,
        );
        add_action("woocommerce_product_options_inventory_product_data", [
            __CLASS__,
            "admin_code_field",
        ]);
        add_action("wp_enqueue_scripts", [__CLASS__, "assets"]);
        // WooCommerce ya modificó el stock cuando ejecuta estos hooks. Aquí solo
        // dejamos una trazabilidad contable; nunca volvemos a tocar existencias.
        add_action("woocommerce_reduce_order_item_stock", [
            __CLASS__,
            "record_order_reduction",
        ], 20, 3);
        add_action("woocommerce_restore_order_item_stock", [
            __CLASS__,
            "record_order_restore",
        ], 20, 4);
    }

    /** Assign deterministic codes to the existing catalogue during activation. */
    public static function install_codes(): void
    {
        $ids = get_posts([
            "post_type" => ["product", "product_variation"],
            "post_status" => ["publish", "private", "draft"],
            "posts_per_page" => -1,
            "fields" => "ids",
            "no_found_rows" => true,
        ]);
        foreach ($ids as $product_id) {
            self::ensure_product_code((int) $product_id);
        }
    }

    public static function routes(): void
    {
        register_rest_route("casa-viva/v1", "/inventory/product", [
            "methods" => "GET",
            "callback" => [__CLASS__, "product"],
            "permission_callback" => [__CLASS__, "can_manage"],
            "args" => [
                "code" => [
                    "required" => true,
                    "sanitize_callback" => "sanitize_text_field",
                ],
            ],
        ]);
        register_rest_route("casa-viva/v1", "/inventory/movement", [
            "methods" => "POST",
            "callback" => [__CLASS__, "movement"],
            "permission_callback" => [__CLASS__, "can_manage"],
        ]);
        register_rest_route("casa-viva/v1", "/inventory/report", [
            "methods" => "GET",
            "callback" => [__CLASS__, "report"],
            "permission_callback" => [__CLASS__, "can_manage"],
        ]);
    }

    public static function can_manage(): bool
    {
        return current_user_can("cvd_manage_inventory") ||
            current_user_can("manage_woocommerce");
    }

    public static function ensure_product_code(int $product_id): void
    {
        if (
            $product_id <= 0 ||
            get_post_meta($product_id, self::CODE_META, true)
        ) {
            return;
        }
        update_post_meta($product_id, self::CODE_META, "CV-" . $product_id);
    }

    public static function admin_code_field(): void
    {
        global $post;
        if (!($post instanceof WP_Post)) {
            return;
        }
        self::ensure_product_code($post->ID);
        woocommerce_wp_text_input([
            "id" => self::CODE_META,
            "label" => "Código Casa Viva",
            "value" => get_post_meta($post->ID, self::CODE_META, true),
            "desc_tip" => true,
            "description" =>
                "Identificador interno estable para escáner e inventario.",
            "custom_attributes" => ["readonly" => "readonly"],
        ]);
    }

    private static function find_product(string $raw_code): ?WC_Product
    {
        $code = trim(wp_strip_all_tags($raw_code));
        if (
            preg_match(
                "/\/producto\/(?:[^?]+).*?[?&]cv_code=([^&]+)/i",
                $code,
                $match,
            )
        ) {
            $code = rawurldecode($match[1]);
        }
        if (preg_match('/^CV-(\d+)$/i', $code, $match)) {
            $product = wc_get_product(absint($match[1]));
            if ($product) {
                self::ensure_product_code($product->get_id());
                return $product;
            }
        }
        $product_id = wc_get_product_id_by_sku($code);
        if ($product_id) {
            return wc_get_product($product_id);
        }
        $query = new WP_Query([
            "post_type" => ["product", "product_variation"],
            "post_status" => ["publish", "private"],
            "posts_per_page" => 1,
            "fields" => "ids",
            "meta_query" => [
                "relation" => "OR",
                ["key" => self::CODE_META, "value" => $code],
                ["key" => "_global_unique_id", "value" => $code],
            ],
        ]);
        return $query->posts ? wc_get_product((int) $query->posts[0]) : null;
    }

    private static function payload(WC_Product $product): array
    {
        self::ensure_product_code($product->get_id());
        $stock = $product->get_stock_quantity();
        return [
            "id" => $product->get_id(),
            "parentId" => $product->get_parent_id(),
            "code" => (string) get_post_meta(
                $product->get_id(),
                self::CODE_META,
                true,
            ),
            "sku" => $product->get_sku(),
            "name" => $product->get_name(),
            "description" => wp_trim_words(
                wp_strip_all_tags(
                    $product->get_short_description() ?:
                    $product->get_description(),
                ),
                24,
            ),
            "price" => wp_strip_all_tags(wc_price($product->get_price())),
            "stock" => null === $stock ? 0 : (float) $stock,
            "stockStatus" => $product->get_stock_status(),
            "image" =>
                wp_get_attachment_image_url(
                    $product->get_image_id(),
                    "medium",
                ) ?:
                wc_placeholder_img_src("medium"),
            "publicUrl" => get_permalink(
                $product->get_parent_id() ?: $product->get_id(),
            ),
        ];
    }

    public static function product(WP_REST_Request $request)
    {
        $product = self::find_product((string) $request->get_param("code"));
        return $product
            ? rest_ensure_response(self::payload($product))
            : new WP_Error(
                "cvd_product_not_found",
                "No encontramos un producto con ese código.",
                ["status" => 404],
            );
    }

    public static function movement(WP_REST_Request $request)
    {
        global $wpdb;
        $data = (array) $request->get_json_params();
        $uuid = preg_replace(
            "/[^a-zA-Z0-9-]/",
            "",
            (string) ($data["uuid"] ?? ""),
        );
        $type = sanitize_key((string) ($data["type"] ?? ""));
        $quantity = (float) wc_format_decimal($data["quantity"] ?? 0);
        $reason = sanitize_text_field((string) ($data["reason"] ?? ""));
        $product = wc_get_product(absint($data["productId"] ?? 0));
        $allowed = ["entry", "exit", "sale", "return", "loss", "count"];
        if (
            strlen($uuid) < 16 ||
            !$product ||
            !in_array($type, $allowed, true) ||
            $quantity < 0 ||
            ("count" !== $type && 0.0 === $quantity)
        ) {
            return new WP_Error(
                "cvd_invalid_movement",
                "Revisa producto, operación y cantidad.",
                ["status" => 422],
            );
        }
        if (in_array($type, ["exit", "loss", "count"], true) && !$reason) {
            return new WP_Error(
                "cvd_reason_required",
                "Escribe el motivo de esta operación.",
                ["status" => 422],
            );
        }
        if ($product->is_type("variable") && !$product->get_manage_stock()) {
            return new WP_Error(
                "cvd_variation_required",
                "Este producto controla existencias por variación. Escanea el código de la variante exacta.",
                ["status" => 422],
            );
        }
        $table = $wpdb->prefix . "cvd_inventory_movements";
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE movement_uuid=%s",
                $uuid,
            ),
            ARRAY_A,
        );
        if ($existing) {
            return rest_ensure_response([
                "duplicate" => true,
                "movement" => $existing,
                "product" => self::payload($product),
            ]);
        }

        $lock_name = "cvd-stock-" . $product->get_id();
        $locked = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s,5)", $lock_name),
        );
        if (1 !== $locked) {
            return new WP_Error(
                "cvd_stock_busy",
                "El producto está siendo actualizado. Intenta otra vez.",
                ["status" => 409],
            );
        }

        try {
            $wpdb->query("START TRANSACTION");
            $product = wc_get_product($product->get_id());
            $before = (float) ($product->get_stock_quantity() ?? 0);
            if (!$product->get_manage_stock()) {
                $product->set_manage_stock(true);
                $product->save();
            }
            $delta = match ($type) {
                "entry", "return" => $quantity,
                "exit", "sale", "loss" => -$quantity,
                "count" => $quantity - $before,
            };
            $after = $before + $delta;
            if ($after < 0) {
                throw new RuntimeException(
                    "No hay existencias suficientes para completar la salida.",
                );
            }
            wc_update_product_stock($product, $after, "set");
            $inserted = $wpdb->insert(
                $table,
                [
                    "movement_uuid" => $uuid,
                    "product_id" =>
                        $product->get_parent_id() ?: $product->get_id(),
                    "variation_id" => $product->get_parent_id()
                        ? $product->get_id()
                        : 0,
                    "movement_type" => $type,
                    "quantity_delta" => $delta,
                    "stock_before" => $before,
                    "stock_after" => $after,
                    "reason" => $reason,
                    "reference_type" => sanitize_key(
                        (string) ($data["referenceType"] ?? "manual"),
                    ),
                    "reference_id" => absint($data["referenceId"] ?? 0),
                    "actor_user_id" => get_current_user_id(),
                    "created_at" => current_time("mysql", true),
                    "metadata" => wp_json_encode([
                        "price" => $product->get_price(),
                        "source" => "inventory-app",
                    ]),
                ],
                [
                    "%s",
                    "%d",
                    "%d",
                    "%s",
                    "%f",
                    "%f",
                    "%f",
                    "%s",
                    "%s",
                    "%d",
                    "%d",
                    "%s",
                    "%s",
                ],
            );
            if (false === $inserted) {
                throw new RuntimeException("No se pudo guardar el movimiento.");
            }
            $wpdb->query("COMMIT");
            return rest_ensure_response([
                "duplicate" => false,
                "message" => "Inventario actualizado.",
                "product" => self::payload(wc_get_product($product->get_id())),
            ]);
        } catch (Throwable $error) {
            $wpdb->query("ROLLBACK");
            return new WP_Error("cvd_movement_failed", $error->getMessage(), [
                "status" => 409,
            ]);
        } finally {
            $wpdb->get_var(
                $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name),
            );
        }
    }

    /** Registra la rebaja que WooCommerce ya realizó al confirmar un pedido. */
    public static function record_order_reduction(
        WC_Order_Item_Product $item,
        array $change,
        WC_Order $order
    ): void {
        $product = $change["product"] ?? $item->get_product();
        if (!($product instanceof WC_Product)) {
            return;
        }
        $sequence = self::next_item_sequence($item, "_cvd_stock_reduction_sequence");
        self::record_woocommerce_movement(
            "sale",
            $product,
            (float) ($change["from"] ?? 0),
            (float) ($change["to"] ?? 0),
            $order,
            $item,
            "reduce-" . $sequence
        );
    }

    /** Registra la devolución que WooCommerce ya realizó al cancelar/reembolsar. */
    public static function record_order_restore(
        WC_Order_Item_Product $item,
        $new_stock,
        $old_stock,
        WC_Order $order
    ): void {
        $product = $item->get_product();
        if (!($product instanceof WC_Product)) {
            return;
        }
        $sequence = self::next_item_sequence($item, "_cvd_stock_restore_sequence");
        self::record_woocommerce_movement(
            "return",
            $product,
            (float) $old_stock,
            (float) $new_stock,
            $order,
            $item,
            "restore-" . $sequence
        );
    }

    private static function next_item_sequence(
        WC_Order_Item_Product $item,
        string $meta_key
    ): int {
        $sequence = max(0, (int) $item->get_meta($meta_key, true)) + 1;
        $item->update_meta_data($meta_key, $sequence);
        $item->save_meta_data();
        return $sequence;
    }

    private static function record_woocommerce_movement(
        string $type,
        WC_Product $product,
        float $before,
        float $after,
        WC_Order $order,
        WC_Order_Item_Product $item,
        string $cycle
    ): void {
        global $wpdb;
        $delta = $after - $before;
        if (0.0 === $delta) {
            return;
        }
        $uuid = sprintf(
            "wc-%d-%d-%s",
            $order->get_id(),
            $item->get_id(),
            $cycle
        );
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}cvd_inventory_movements
                (movement_uuid,product_id,variation_id,movement_type,quantity_delta,stock_before,stock_after,reason,reference_type,reference_id,actor_user_id,created_at,metadata)
                VALUES (%s,%d,%d,%s,%f,%f,%f,%s,%s,%d,%d,%s,%s)",
                $uuid,
                $product->get_parent_id() ?: $product->get_id(),
                $product->get_parent_id() ? $product->get_id() : 0,
                $type,
                $delta,
                $before,
                $after,
                "sale" === $type
                    ? "Pedido WooCommerce #" . $order->get_order_number()
                    : "Reposición del pedido #" . $order->get_order_number(),
                "woocommerce_order",
                $order->get_id(),
                get_current_user_id(),
                current_time("mysql", true),
                wp_json_encode([
                    "item_id" => $item->get_id(),
                    "quantity" => $item->get_quantity(),
                    "order_status" => $order->get_status(),
                    "source" => "woocommerce",
                ])
            )
        );
        if (false === $inserted && function_exists("wc_get_logger")) {
            wc_get_logger()->error(
                "No se pudo registrar el movimiento {$uuid}: {$wpdb->last_error}",
                ["source" => "casa-viva-inventory"]
            );
        }
    }

    /** Informe operativo sin datos privados de clientes. */
    public static function report(WP_REST_Request $request)
    {
        global $wpdb;
        $table = $wpdb->prefix . "cvd_inventory_movements";
        $limit = min(100, max(10, absint($request->get_param("limit") ?: 30)));
        $type = sanitize_key((string) $request->get_param("type"));
        $allowed = ["entry", "exit", "sale", "return", "loss", "count"];
        $where = "1=1";
        $params = [];
        if (in_array($type, $allowed, true)) {
            $where .= " AND movement_type=%s";
            $params[] = $type;
        }
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC,id DESC LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        $movements = [];
        foreach ($rows as $row) {
            $lookup_id = (int) ($row["variation_id"] ?: $row["product_id"]);
            $product = wc_get_product($lookup_id);
            $actor = $row["actor_user_id"] ? get_userdata((int) $row["actor_user_id"]) : false;
            $movements[] = [
                "id" => (int) $row["id"],
                "date" => get_date_from_gmt($row["created_at"], "d/m/Y H:i"),
                "type" => $row["movement_type"],
                "product" => $product ? $product->get_name() : "Producto #" . $lookup_id,
                "code" => (string) get_post_meta($lookup_id, self::CODE_META, true),
                "delta" => (float) $row["quantity_delta"],
                "before" => (float) $row["stock_before"],
                "after" => (float) $row["stock_after"],
                "reason" => $row["reason"],
                "source" => "woocommerce_order" === $row["reference_type"] ? "Pedido" : "Manual",
                "reference" => (int) $row["reference_id"],
                "actor" => $actor ? $actor->display_name : "Sistema",
                "productUrl" => $product ? get_permalink($product->get_parent_id() ?: $product->get_id()) : "",
                "orderUrl" => ("woocommerce_order" === $row["reference_type"] && $row["reference_id"])
                    ? add_query_arg("order", (int) $row["reference_id"], home_url("/ventas/"))
                    : "",
            ];
        }
        $today = current_time("Y-m-d", true);
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                COALESCE(SUM(CASE WHEN quantity_delta > 0 THEN quantity_delta ELSE 0 END),0) AS entries,
                COALESCE(ABS(SUM(CASE WHEN quantity_delta < 0 THEN quantity_delta ELSE 0 END)),0) AS exits,
                COUNT(*) AS movements
                FROM {$table} WHERE created_at >= %s",
                $today . " 00:00:00"
            ),
            ARRAY_A
        );
        $low_stock = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} stock
             INNER JOIN {$wpdb->posts} product ON product.ID=stock.post_id
             WHERE stock.meta_key='_stock'
             AND product.post_type IN ('product','product_variation')
             AND product.post_status IN ('publish','private')
             AND CAST(stock.meta_value AS DECIMAL(18,4)) BETWEEN 0 AND 2"
        );
        return rest_ensure_response([
            "summary" => [
                "entries" => (float) ($summary["entries"] ?? 0),
                "exits" => (float) ($summary["exits"] ?? 0),
                "movements" => (int) ($summary["movements"] ?? 0),
                "lowStock" => $low_stock,
            ],
            "movements" => $movements,
        ]);
    }

    public static function assets(): void
    {
        if (!is_page("inventario")) {
            return;
        }
        wp_enqueue_style(
            "cvd-inventory",
            CVD_URL . "assets/inventory.css",
            [],
            CVD_VERSION,
        );
        wp_enqueue_script(
            "cvd-qr-code",
            CVD_URL . "assets/qr-code.js",
            [],
            CVD_VERSION,
            true,
        );
        wp_enqueue_script(
            "cvd-inventory",
            CVD_URL . "assets/inventory.js",
            ["cvd-qr-code"],
            CVD_VERSION,
            true,
        );
        wp_localize_script("cvd-inventory", "cvdInventory", [
            "productUrl" => rest_url("casa-viva/v1/inventory/product"),
            "movementUrl" => rest_url("casa-viva/v1/inventory/movement"),
            "reportUrl" => rest_url("casa-viva/v1/inventory/report"),
            "nonce" => wp_create_nonce("wp_rest"),
            "homeUrl" => home_url("/"),
            "logoutUrl" => wp_logout_url(home_url("/casa-viva-app/")),
        ]);
    }

    public static function render(): string
    {
        if (!is_user_logged_in()) {
            return '<section class="cvd-inventory-denied"><h1>Inventario Casa Viva</h1><p>Inicia sesión desde la aplicación para continuar.</p><a href="' .
                esc_url(home_url("/casa-viva-app/")) .
                '">Iniciar sesión</a></section>';
        }
        if (!self::can_manage()) {
            return '<section class="cvd-inventory-denied"><h1>Acceso restringido</h1><p>Esta cuenta no tiene permiso para modificar inventario.</p></section>';
        }
        return '<section class="cvd-inventory-app"><header><h1>Inventario</h1></header><div class="cvd-scan-box"><video id="cvd-scanner" playsinline hidden></video><div class="cvd-code-row"><input id="cvd-product-code" autocomplete="off" inputmode="text" placeholder="Código del producto"><button id="cvd-find-product" type="button">Buscar</button></div><button class="cvd-camera" id="cvd-start-camera" type="button">Escanear con la cámara</button><p id="cvd-inventory-message" role="status"></p></div><article class="cvd-product-result" id="cvd-product-result" hidden><img alt="" id="cvd-product-image"><div><small id="cvd-product-code-label"></small><h2 id="cvd-product-name"></h2><p id="cvd-product-description"></p><div class="cvd-product-numbers"><strong id="cvd-product-price"></strong><b><span id="cvd-product-stock"></span> disponibles</b></div><a id="cvd-product-link" target="_blank" rel="noopener">Abrir producto</a></div><aside class="cvd-qr-label"><canvas id="cvd-product-qr" aria-label="Código QR del producto"></canvas><strong id="cvd-qr-name"></strong><small id="cvd-qr-code"></small><button id="cvd-print-qr" type="button">Imprimir QR</button></aside></article><form id="cvd-movement-form" hidden><h2>Movimiento</h2><input id="cvd-product-id" type="hidden"><label>Operación<select id="cvd-movement-type" required><option value="entry">Entrada</option><option value="exit">Salida</option><option value="sale">Venta presencial</option><option value="return">Devolución</option><option value="loss">Rotura o pérdida</option><option value="count">Conteo físico</option></select></label><label><span id="cvd-quantity-label">Cantidad</span><input id="cvd-movement-quantity" min="0" required step="1" type="number"></label><label>Observación<textarea id="cvd-movement-reason" rows="3"></textarea></label><button type="submit">Confirmar</button><p id="cvd-movement-message" role="status"></p></form><section class="cvd-inventory-report" aria-labelledby="cvd-report-title"><div class="cvd-report-heading"><div><h2 id="cvd-report-title">Actividad</h2></div><label>Filtrar<select id="cvd-report-filter"><option value="">Todos</option><option value="entry">Entradas</option><option value="sale">Ventas</option><option value="return">Devoluciones</option><option value="exit">Salidas</option><option value="loss">Pérdidas</option><option value="count">Conteos</option></select></label></div><div class="cvd-report-summary"><article><span>Entradas hoy</span><strong id="cvd-summary-entries">—</strong></article><article><span>Salidas hoy</span><strong id="cvd-summary-exits">—</strong></article><article><span>Movimientos hoy</span><strong id="cvd-summary-movements">—</strong></article><article><span>Stock bajo</span><strong id="cvd-summary-low">—</strong></article></div><div class="cvd-movement-list" id="cvd-movement-list" aria-live="polite"><p>Cargando…</p></div></section></section>';
    }
}
