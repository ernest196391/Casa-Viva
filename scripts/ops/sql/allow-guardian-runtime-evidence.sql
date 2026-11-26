-- CV-AUTO-002 Guardian: permit ONLY fixed metadata attestation with exact empty arguments.
-- Apply only after PR/CI approval and snapshot of the pre-existing constraint.
-- Not an authorization for local_sql_reconcile or any other action.
DO $cv$
DECLARE prior text;
BEGIN
  SELECT pg_get_constraintdef(oid) INTO prior
  FROM pg_constraint
  WHERE conrelid='public.crm_ops_bridge'::regclass
    AND conname='crm_ops_bridge_action_chk'
    AND contype='c';
  IF prior IS NULL OR md5(prior) <> 'de228436c4c60d8820858cf4dba191dc' THEN
    RAISE EXCEPTION 'unexpected_existing_bridge_constraint';
  END IF;
  IF position('guardian_runtime_evidence' in prior)>0 THEN
    RAISE EXCEPTION 'guardian_action_already_present';
  END IF;
  IF position('local_sql_reconcile' in prior)>0 THEN
    RAISE EXCEPTION 'unexpected_sql_action_authorization';
  END IF;
  EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
  EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk CHECK ('
    || substring(prior FROM 8 FOR char_length(prior)-8)
    || ' OR (action = ''guardian_runtime_evidence'' AND args = ''{}''::jsonb))';
END $cv$;
