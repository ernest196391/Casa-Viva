-- CV-AUTO-002 / PR #321. Restricted action permission; NO RLS/GRANT/flag changes.
-- Exact live baseline md5: 962a244741ea68010e25aa8b336448ab.
-- Only this specific release's SHA is allowed, with exactly one JSON key.
-- PostgreSQL DDL is transactional. Abort on any drift.
DO $guardian$
DECLARE
    original text;
    expression text;
    enabled_rls boolean;
BEGIN
    SELECT pg_get_constraintdef(c.oid)
      INTO original
      FROM pg_constraint AS c
     WHERE c.conrelid = 'public.crm_ops_bridge'::regclass
       AND c.conname = 'crm_ops_bridge_action_chk'
       AND c.contype = 'c';
    IF original IS NULL OR md5(original) <> '962a244741ea68010e25aa8b336448ab' THEN
        RAISE EXCEPTION 'guardian_gate_baseline_changed';
    END IF;
    SELECT relrowsecurity INTO enabled_rls FROM pg_class WHERE oid = 'public.crm_ops_bridge'::regclass;
    IF enabled_rls IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'guardian_gate_rls_not_enabled';
    END IF;
    IF EXISTS (SELECT 1 FROM public.crm_ops_bridge WHERE action = 'guardian_recover_gate') THEN
        RAISE EXCEPTION 'guardian_gate_rows_preexisting';
    END IF;
    expression := substr(original, 8, char_length(original) - 8);
    EXECUTE 'ALTER TABLE public.crm_ops_bridge DROP CONSTRAINT crm_ops_bridge_action_chk';
    EXECUTE 'ALTER TABLE public.crm_ops_bridge ADD CONSTRAINT crm_ops_bridge_action_chk CHECK (' ||
        expression ||
        ' OR ((action = ''guardian_recover_gate''::text) AND (args IS NOT DISTINCT FROM ''{"sha":"c5765a2088532d4940f5503f0f80aa005b85481b"}''::jsonb) AND (requested_by IS NOT DISTINCT FROM ''chatgpt''::text)))';
    IF NOT (SELECT relrowsecurity FROM pg_class WHERE oid = 'public.crm_ops_bridge'::regclass) THEN
        RAISE EXCEPTION 'guardian_gate_rls_changed';
    END IF;
END $guardian$;
