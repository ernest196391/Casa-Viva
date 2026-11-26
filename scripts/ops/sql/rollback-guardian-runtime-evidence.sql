-- Restore the exact original constraint captured before PR #312.
-- Apply only if the Guardian addition exists and no Guardian task is in flight.
DO $cv$
DECLARE prior text;
DECLARE original_def text := 'CHECK (((action = ANY (ARRAY[''health''::text, ''errors_open''::text, ''supervisor_status''::text, ''pm2_status''::text, ''wp_status''::text, ''wp_plugins''::text, ''wp_version''::text, ''wp_catalog_search''::text, ''wp_product''::text, ''cv_order''::text, ''bot_access_audit''::text, ''vivabot_git_status''::text, ''deploy_vivabot''::text, ''bridge_status''::text, ''update_bridge''::text, ''bridge_service_status''::text, ''install_bridge_service''::text, ''update_control_sync''::text, ''deploy_control_web''::text, ''control_sync_service_status''::text, ''install_control_sync_service''::text, ''dev_agent_status''::text, ''dev_environment_audit''::text, ''dev_push_probe''::text, ''dev_claude_probe''::text, ''update_dev_worker''::text, ''install_dev_worker_service''::text, ''dev_worker_status''::text, ''core_drift_audit''::text, ''snapshot_live_core_branch''::text, ''deploy_casa_viva''::text, ''casa_viva_deploy_status''::text, ''ai_audit''::text, ''catalog_sync_status''::text, ''cvdev_control_status''::text, ''cvdev_rebase_resume''::text, ''cvdev_control_tests''::text, ''cvdev_control_push''::text, ''snapshot_vivabot_live_branch''::text, ''snapshot_vivabot_dirty_branch''::text, ''opencode_planner_probe''::text, ''prepare_control_dev_checkout''::text, ''control_codex_setup''::text, ''control_codex_login''::text, ''control_codex_consumer_build''::text, ''control_codex_sandbox''::text, ''control_codex_consumer_publish''::text])) OR ((action = ''local_crm_status''::text) AND (args = ''{}''::jsonb)) OR ((action = ''local_recovery_preflight''::text) AND (args = ''{}''::jsonb)) OR ((action = ''recovery_dispatch_auth''::text) AND (args = ''{}''::jsonb)) OR ((action = ''recovery_preflight_evidence''::text) AND (args = ''{}''::jsonb)) OR ((action = ''install_recovery_preflight''::text) AND (args = ''{}''::jsonb)) OR ((action = ''restore_recovery_config''::text) AND (args = ''{}''::jsonb)) OR ((action = ''restore_effective_recovery''::text) AND (args = ''{}''::jsonb)) OR ((action = ''receiver_crm_contract''::text) AND (args = ''{}''::jsonb))))';
BEGIN
  IF EXISTS (SELECT 1 FROM public.crm_ops_bridge
             WHERE action = 'guardian_runtime_evidence'
               AND status IN ('queued', 'running')) THEN
    RAISE EXCEPTION 'guardian_tasks_in_flight';
  END IF;
  SELECT pg_get_constraintdef(oid) INTO prior
    FROM pg_constraint
   WHERE conrelid='public.crm_ops_bridge'::regclass
     AND conname='crm_ops_bridge_action_chk' AND contype='c';
  IF prior IS NULL OR position('guardian_runtime_evidence' in prior) = 0
     OR position('local_sql_reconcile' in prior) > 0
     OR md5(original_def) <> 'de228436c4c60d8820858cf4dba191dc' THEN
    RAISE EXCEPTION 'unexpected_guardian_constraint_shape';
  END IF;
  -- Do not restore over an unrelated schema drift. A canonical attestation
  -- should check the full live predicate before running this rollback.
  IF position('''receiver_crm_contract''::text' in prior) = 0
     OR position('''control_codex_consumer_publish''::text' in prior) = 0 THEN
    RAISE EXCEPTION 'original_allowlist_missing';
  END IF;
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk ' || original_def;
END $cv$;
