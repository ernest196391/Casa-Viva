-- CV-AUTO-002 reverse only when no guardian_recover_gate audit/task rows exist.
-- Completed task history is never removed automatically. RLS stays unchanged.
DO $guardian_rollback$
DECLARE
    installed text;
    baseline text := 'CHECK (((action = ANY (ARRAY[''health''::text, ''errors_open''::text, ''supervisor_status''::text, ''pm2_status''::text, ''wp_status''::text, ''wp_plugins''::text, ''wp_version''::text, ''wp_catalog_search''::text, ''wp_product''::text, ''cv_order''::text, ''bot_access_audit''::text, ''vivabot_git_status''::text, ''deploy_vivabot''::text, ''bridge_status''::text, ''update_bridge''::text, ''bridge_service_status''::text, ''install_bridge_service''::text, ''update_control_sync''::text, ''deploy_control_web''::text, ''control_sync_service_status''::text, ''install_control_sync_service''::text, ''dev_agent_status''::text, ''dev_environment_audit''::text, ''dev_push_probe''::text, ''dev_claude_probe''::text, ''update_dev_worker''::text, ''install_dev_worker_service''::text, ''dev_worker_status''::text, ''core_drift_audit''::text, ''snapshot_live_core_branch''::text, ''deploy_casa_viva''::text, ''casa_viva_deploy_status''::text, ''ai_audit''::text, ''catalog_sync_status''::text, ''cvdev_control_status''::text, ''cvdev_rebase_resume''::text, ''cvdev_control_tests''::text, ''cvdev_control_push''::text, ''snapshot_vivabot_live_branch''::text, ''snapshot_vivabot_dirty_branch''::text, ''opencode_planner_probe''::text, ''prepare_control_dev_checkout''::text, ''control_codex_setup''::text, ''control_codex_login''::text, ''control_codex_consumer_build''::text, ''control_codex_sandbox''::text, ''control_codex_consumer_publish''::text])) OR ((action = ''local_crm_status''::text) AND (args = ''{}''::jsonb)) OR ((action = ''local_recovery_preflight''::text) AND (args = ''{}''::jsonb)) OR ((action = ''recovery_dispatch_auth''::text) AND (args = ''{}''::jsonb)) OR ((action = ''recovery_preflight_evidence''::text) AND (args = ''{}''::jsonb)) OR ((action = ''install_recovery_preflight''::text) AND (args = ''{}''::jsonb)) OR ((action = ''restore_recovery_config''::text) AND (args = ''{}''::jsonb)) OR ((action = ''restore_effective_recovery''::text) AND (args = ''{}''::jsonb)) OR ((action = ''receiver_crm_contract''::text) AND (args = ''{}''::jsonb)) OR ((action = ''guardian_runtime_evidence''::text) AND (args = ''{}''::jsonb))))';
BEGIN
    IF md5(baseline) <> '962a244741ea68010e25aa8b336448ab' THEN
        RAISE EXCEPTION 'guardian_gate_rollback_baseline_corrupt';
    END IF;
    SELECT pg_get_constraintdef(c.oid) INTO installed
      FROM pg_constraint AS c
     WHERE c.conrelid = 'public.crm_ops_bridge'::regclass
       AND c.conname = 'crm_ops_bridge_action_chk'
       AND c.contype = 'c';
    IF installed IS NULL OR position('guardian_recover_gate' in installed) = 0
       OR position('c5765a2088532d4940f5503f0f80aa005b85481b' in installed) = 0
       OR position('guardian_runtime_evidence' in installed) = 0
       OR position('local_sql_reconcile' in installed) > 0 THEN
        RAISE EXCEPTION 'guardian_gate_rollback_unexpected_constraint';
    END IF;
    IF EXISTS (SELECT 1 FROM public.crm_ops_bridge WHERE action = 'guardian_recover_gate') THEN
        RAISE EXCEPTION 'guardian_gate_rollback_history_must_be_preserved';
    END IF;
    IF NOT (SELECT relrowsecurity FROM pg_class WHERE oid='public.crm_ops_bridge'::regclass) THEN
        RAISE EXCEPTION 'guardian_gate_rollback_rls_not_enabled';
    END IF;
    EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
    EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk ' || baseline;
END $guardian_rollback$;
