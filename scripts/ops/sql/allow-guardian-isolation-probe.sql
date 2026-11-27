-- CV-AUTO-002: add exactly one synthetic diagnostic action. No RLS, GRANT or flags.
-- Observed live constraint fingerprint on 27 Nov 2026: 3e08e16a5a93e1313a96caa31229745a
-- Requires a fresh read of the production constraint before applying; abort on drift.
DO $guardian_isolation$
DECLARE
  prior text;
  predicate text;
BEGIN
  SELECT pg_get_constraintdef(oid) INTO prior FROM pg_constraint
   WHERE conrelid='public.crm_ops_bridge'::regclass
     AND conname='crm_ops_bridge_action_chk' AND contype='c';
  IF prior IS NULL OR md5(prior) <> '3e08e16a5a93e1313a96caa31229745a' THEN
    RAISE EXCEPTION 'guardian_isolation_baseline_drift';
  END IF;
  IF (SELECT relrowsecurity FROM pg_class WHERE oid='public.crm_ops_bridge'::regclass) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'guardian_isolation_rls_missing';
  END IF;
  IF EXISTS(SELECT 1 FROM public.crm_ops_bridge WHERE action='guardian_isolation_probe') THEN
    RAISE EXCEPTION 'guardian_isolation_history_exists';
  END IF;
  predicate := substring(prior FROM 8 FOR length(prior)-8);
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk CHECK (' ||
    predicate || ' OR (action = ''guardian_isolation_probe''::text AND args IS NOT DISTINCT FROM ''{}''::jsonb AND requested_by IS NOT DISTINCT FROM ''chatgpt''::text))';
END $guardian_isolation$;
