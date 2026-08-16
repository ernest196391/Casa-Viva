#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose=(docker compose -f "$repo_dir/integration/compose.yml")
command_name="${1:-help}"

wp() { "${compose[@]}" run --rm --no-deps cli wp "$@"; }

case "$command_name" in
  up)
    "${compose[@]}" up -d db wordpress
    for attempt in $(seq 1 60); do
      if curl --fail --silent --show-error --max-time 5 http://127.0.0.1:8889/wp-admin/install.php >/dev/null; then break; fi
      if [[ "$attempt" == 60 ]]; then echo "WordPress no quedó disponible." >&2; exit 1; fi
      sleep 2
    done
    if ! wp core is-installed >/dev/null 2>&1; then
      wp core install --url=http://localhost:8889 --title="Casa Viva Integration" --admin_user=cvt_admin --admin_password='Synthetic-Admin-Only-1!' --admin_email=admin@example.invalid --skip-email
    fi
    if ! wp plugin is-installed woocommerce; then wp plugin install woocommerce --version=8.2.2 --activate; else wp plugin activate woocommerce; fi
    wp plugin activate casa-viva-dropship-core
    wp core version
    wp plugin get woocommerce --field=version
    wp eval 'echo PHP_VERSION . PHP_EOL;'
    "${compose[@]}" exec -T db mariadb -ucasa_viva_test -pcasa_viva_test_only casa_viva_test -e 'SELECT VERSION();'
    ;;
  test)
	"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    "$0" up
    wp eval-file /var/www/html/integration-tests/upgrade.php
    wp eval-file /var/www/html/integration-tests/bootstrap.php
    wp eval-file /var/www/html/integration-tests/action.php operation preparing
	wp eval-file /var/www/html/integration-tests/repeat-operation.php
    wp eval-file /var/www/html/integration-tests/action.php operation ready
    wp eval-file /var/www/html/integration-tests/action.php assign assigned
    wp eval-file /var/www/html/integration-tests/action.php delivery accepted
    wp eval-file /var/www/html/integration-tests/action.php delivery to_store
    wp eval-file /var/www/html/integration-tests/action.php handover picked_up
    wp eval-file /var/www/html/integration-tests/action.php delivery handed_over
    wp eval-file /var/www/html/integration-tests/action.php delivery incident
    wp eval-file /var/www/html/integration-tests/action.php delivery handed_over
    wp eval-file /var/www/html/integration-tests/action.php delivery incident
    wp eval-file /var/www/html/integration-tests/action.php delivery handed_over
    wp eval-file /var/www/html/integration-tests/action.php delivery delivered
    wp eval-file /var/www/html/integration-tests/action.php delivery cash_returned
    wp eval-file /var/www/html/integration-tests/action.php delivery closed
    wp eval-file /var/www/html/integration-tests/verify.php
	wp eval-file /var/www/html/integration-tests/transition-bootstrap.php
	wp eval-file /var/www/html/integration-tests/transition-run.php clerk_id & first_transition_pid=$!
	wp eval-file /var/www/html/integration-tests/transition-run.php admin_id & second_transition_pid=$!
	transition_failed=0
	wait "$first_transition_pid" || transition_failed=1
	wait "$second_transition_pid" || transition_failed=1
	if [[ "$transition_failed" != 0 ]]; then echo "Una transición concurrente falló." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/transition-verify.php
	wp eval-file /var/www/html/integration-tests/logistics-bootstrap.php
	wp eval-file /var/www/html/integration-tests/logistics-accept.php messenger_one & first_accept_pid=$!
	wp eval-file /var/www/html/integration-tests/logistics-accept.php messenger_two & second_accept_pid=$!
	accept_failed=0
	wait "$first_accept_pid" || accept_failed=1
	wait "$second_accept_pid" || accept_failed=1
	if [[ "$accept_failed" != 0 ]]; then echo "La aceptación concurrente falló de forma insegura." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/logistics-finish.php
	wp eval-file /var/www/html/integration-tests/logistics-verify.php
	wp eval-file /var/www/html/integration-tests/custody-bootstrap.php
	wp eval-file /var/www/html/integration-tests/custody-pickup.php clerk_id & first_pickup_pid=$!
	wp eval-file /var/www/html/integration-tests/custody-pickup.php admin_id & second_pickup_pid=$!
	pickup_failed=0
	wait "$first_pickup_pid" || pickup_failed=1
	wait "$second_pickup_pid" || pickup_failed=1
	if [[ "$pickup_failed" != 0 ]]; then echo "La custodia concurrente falló." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/custody-finish.php
	wp eval-file /var/www/html/integration-tests/custody-result.php delivered & delivered_result_pid=$!
	wp eval-file /var/www/html/integration-tests/custody-result.php failed & failed_result_pid=$!
	result_failed=0
	wait "$delivered_result_pid" || result_failed=1
	wait "$failed_result_pid" || result_failed=1
	if [[ "$result_failed" != 0 ]]; then echo "La carrera de resultados falló de forma insegura." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/custody-verify.php
	wp eval-file /var/www/html/integration-tests/closeout-bootstrap.php
	wp eval-file /var/www/html/integration-tests/closeout-cash.php clerk_id & first_cash_pid=$!
	wp eval-file /var/www/html/integration-tests/closeout-cash.php admin_id & second_cash_pid=$!
	cash_failed=0
	wait "$first_cash_pid" || cash_failed=1
	wait "$second_cash_pid" || cash_failed=1
	if [[ "$cash_failed" != 0 ]]; then echo "La devolución concurrente de efectivo falló." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/closeout-close.php manual & manual_close_pid=$!
	wp eval-file /var/www/html/integration-tests/closeout-close.php automatic & automatic_close_pid=$!
	close_failed=0
	wait "$manual_close_pid" || close_failed=1
	wait "$automatic_close_pid" || close_failed=1
	if [[ "$close_failed" != 0 ]]; then echo "El cierre manual/automático concurrente falló." >&2; exit 1; fi
	wp eval-file /var/www/html/integration-tests/closeout-verify.php
	wp eval-file /var/www/html/integration-tests/closeout-failures.php
	wp eval-file /var/www/html/integration-tests/exceptions-bootstrap.php
	wp eval-file /var/www/html/integration-tests/exceptions-cancel.php & first_cancel_pid=$!
	wp eval-file /var/www/html/integration-tests/exceptions-cancel.php & second_cancel_pid=$!
	wait "$first_cancel_pid" "$second_cancel_pid"
	wp eval-file /var/www/html/integration-tests/exceptions-verify.php
	concurrent_sql="INSERT IGNORE INTO cvt_cvd_order_events (event_id,idempotency_key,order_id,event_type,domain,from_state,to_state,actor_user_id,actor_role,occurred_at,source,metadata,created_at) VALUES ('cv_evt_concurrency_probe','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',999998,'order.concurrent_probe','order','','',0,'system',UTC_TIMESTAMP(),'integration','{}',UTC_TIMESTAMP());"
	"${compose[@]}" exec -T db mariadb -ucasa_viva_test -pcasa_viva_test_only casa_viva_test -e "$concurrent_sql" & first_pid=$!
	"${compose[@]}" exec -T db mariadb -ucasa_viva_test -pcasa_viva_test_only casa_viva_test -e "$concurrent_sql" & second_pid=$!
	wait "$first_pid" "$second_pid"
	concurrent_count="$("${compose[@]}" exec -T db mariadb -N -s -ucasa_viva_test -pcasa_viva_test_only casa_viva_test -e "SELECT COUNT(*) FROM cvt_cvd_order_events WHERE order_id=999998")"
	if [[ "$concurrent_count" != "1" ]]; then echo "Concurrencia creó $concurrent_count filas." >&2; exit 1; fi
	echo "OK: dos inserciones concurrentes produjeron una fila."
    wp eval-file /var/www/html/integration-tests/failure-store.php
    "${compose[@]}" exec -T db mariadb -ucasa_viva_test -pcasa_viva_test_only casa_viva_test -e 'SHOW CREATE TABLE cvt_cvd_order_events\G'
    ;;
  down)
    "${compose[@]}" down --volumes --remove-orphans
    ;;
  logs)
    "${compose[@]}" logs --no-color wordpress db
    ;;
  *)
    echo "Uso: scripts/integration.sh {up|test|down|logs}"
    ;;
esac
