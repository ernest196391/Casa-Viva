-- Reversible only before a probe task exists. Never delete task/audit history.
DO $guardian_isolation_rollback$
DECLARE
  installed text;
  expected_old text;
BEGIN
  SELECT pg_get_constraintdef(oid) INTO installed FROM pg_constraint
   WHERE conrelid='public.crm_ops_bridge'::regclass
    AND conname='crm_ops_bridge_action_chk' AND contype='c';
  IF installed IS NULL OR position('guardian_isolation_probe' IN installed)=0 THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_unexpected';
  END IF;
  IF (SELECT relrowsecurity FROM pg_class WHERE oid='public.crm_ops_bridge'::regclass) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_rls_changed';
  END IF;
  IF EXISTS(SELECT 1 FROM public.crm_ops_bridge WHERE action='guardian_isolation_probe') THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_history_must_be_preserved';
  END IF;
  -- Remove only the fixed trailing branch, retaining all original policy.
  expected_old := replace(installed,
    ' OR ((action = ''guardian_isolation_probe''::text) AND (args IS NOT DISTINCT FROM ''{}''::jsonb) AND (requested_by IS NOT DISTINCT FROM ''chatgpt''::text))', '');
  IF expected_old=installed OR md5(expected_old) <> '3e08e16a5a93e1313a96caa31229745a' THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_drift';
  END IF;
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk ' || expected_old;
END $guardian_isolation_rollback$;
