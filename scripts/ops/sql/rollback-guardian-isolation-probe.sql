-- Restore original CHECK when empty; otherwise freeze probe history and deny new probes.
DO $guardian_isolation_rollback$
DECLARE
  installed text;
  expected_old text;
BEGIN
  LOCK TABLE public.crm_ops_bridge IN ACCESS EXCLUSIVE MODE;
  SELECT pg_get_constraintdef(oid) INTO installed FROM pg_constraint
   WHERE conrelid='public.crm_ops_bridge'::regclass
    AND conname='crm_ops_bridge_action_chk' AND contype='c';
  IF installed IS NULL OR position('guardian_isolation_probe' IN installed)=0 THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_unexpected';
  END IF;
  IF (SELECT relrowsecurity FROM pg_class WHERE oid='public.crm_ops_bridge'::regclass) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_rls_changed';
  END IF;
  -- PostgreSQL rewrites IS NOT DISTINCT FROM as NOT (x IS DISTINCT FROM y).
  -- Strip only the exact final normalized action branch.
  expected_old := replace(installed,
    ' OR ((action = ''guardian_isolation_probe''::text) AND (NOT (args IS DISTINCT FROM ''{}''::jsonb)) AND (NOT (requested_by IS DISTINCT FROM ''chatgpt''::text))))',
    ')');
  IF expected_old=installed OR md5(expected_old) <> '3e08e16a5a93e1313a96caa31229745a' THEN
    RAISE EXCEPTION 'guardian_isolation_rollback_drift';
  END IF;
  IF EXISTS(SELECT 1 FROM public.crm_ops_bridge WHERE action='guardian_isolation_probe') THEN
    IF EXISTS(SELECT 1 FROM public.crm_ops_bridge WHERE action='guardian_isolation_probe'
              AND status IS DISTINCT FROM 'done' AND status IS DISTINCT FROM 'failed') THEN
      RAISE EXCEPTION 'guardian_isolation_rollback_active_task';
    END IF;
    -- guardian_isolation_rollback_history_must_be_preserved
    EXECUTE $ddl$CREATE FUNCTION public.cv_guardian_isolation_revoked()
      RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $fn$
      BEGIN
        IF TG_OP = 'INSERT' THEN
          IF NEW.action = 'guardian_isolation_probe' THEN
            RAISE EXCEPTION 'guardian_isolation_revoked' USING ERRCODE = '23514';
          END IF;
        ELSIF OLD.action = 'guardian_isolation_probe' OR NEW.action = 'guardian_isolation_probe' THEN
          RAISE EXCEPTION 'guardian_isolation_revoked' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
      END; $fn$ $ddl$;
    EXECUTE 'CREATE TRIGGER cv_guardian_isolation_revoked BEFORE INSERT OR UPDATE ON public.crm_ops_bridge FOR EACH ROW EXECUTE FUNCTION public.cv_guardian_isolation_revoked()';
    EXECUTE 'ALTER TABLE public.crm_ops_bridge ENABLE ALWAYS TRIGGER cv_guardian_isolation_revoked';
    RETURN;
  END IF;
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk ' || expected_old;
END $guardian_isolation_rollback$;
