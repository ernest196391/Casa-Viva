-- CV-AUTO-002 Guardian: remove metadata-only action from the queue allowlist.
-- Safeguard: no outstanding guardian tasks; preserve every pre-existing authorization.
BEGIN;
DO $cv$
DECLARE prior text; core text;
BEGIN
  IF EXISTS (SELECT 1 FROM public.crm_ops_bridge
             WHERE action='guardian_runtime_evidence' AND status IN ('queued','running')) THEN
    RAISE EXCEPTION 'guardian_tasks_in_flight';
  END IF;
  SELECT pg_get_constraintdef(oid) INTO prior
  FROM pg_constraint
  WHERE conrelid='public.crm_ops_bridge'::regclass
    AND conname='crm_ops_bridge_action_chk'
    AND contype='c';
  IF prior IS NULL OR position('guardian_runtime_evidence' in prior)=0 THEN
    RAISE EXCEPTION 'guardian_authorization_not_present';
  END IF;
  core := replace(prior, ' OR (action = ''guardian_runtime_evidence'' AND args = ''{}''::jsonb)', '');
  IF core = prior OR position('guardian_runtime_evidence' in core)>0 THEN
    RAISE EXCEPTION 'unexpected_constraint_shape';
  END IF;
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk ' || core;
END $cv$;
COMMIT;
