-- PROPUESTA NO APLICADA. Revisar constraints/tipos de ops_tasks/ops_approvals antes de aplicar.
-- ops_dev_runs no tiene diff/result: evidencia inmutable por run en tabla separada.
create table public.ops_ai_result_receipts (
 run_id bigint primary key references public.ops_dev_runs(id),
 task_id uuid not null references public.ops_tasks(id),
 payload jsonb not null,
 created_at timestamptz not null default clock_timestamp()
);
alter table public.ops_ai_result_receipts enable row level security;
revoke all on public.ops_ai_result_receipts from public, anon, authenticated;
revoke all on public.ops_ai_result_receipts from service_role;
grant select, insert on public.ops_ai_result_receipts to service_role;

create function public.ops_accept_opencode_result(p_result jsonb) returns jsonb
language plpgsql security invoker set search_path = pg_catalog, public as $$
declare
 r public.ops_dev_runs%rowtype;
 t public.ops_tasks%rowtype;
 prior jsonb;
 k text;
 path text;
 finished timestamptz;
 expires timestamptz;
 moment timestamptz := clock_timestamp();
 outcome text;
begin
 if jsonb_typeof(p_result) is distinct from 'object' or octet_length(p_result::text)>1000000 then
  raise exception 'invalid result size/type';
 end if;
 for k in select jsonb_object_keys(p_result) loop
  if k <> all(array['version','executor','run_id','task_id','task_public_id','repo','status','error','base_sha','changed_paths','diff','logs','finished_at','ttl_seconds','expires_at']) then
   raise exception 'unknown result key';
  end if;
 end loop;
 if (p_result->>'version') is distinct from 'opencode-result-v1'
 or (p_result->>'repo') is distinct from 'casa-viva'
 or coalesce(p_result->>'status','') not in ('completed','failed')
 or jsonb_typeof(p_result->'run_id') is distinct from 'string'
 or coalesce(p_result->>'run_id','') !~ '^[1-9][0-9]{0,18}$'
 or jsonb_typeof(p_result->'task_id') is distinct from 'string'
 or coalesce(p_result->>'task_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
 then raise exception 'invalid identity/contract'; end if;
 -- Lock order: run then task. Serializa reintentos concurrentes de la misma run.
 select * into strict r from public.ops_dev_runs where id=(p_result->>'run_id')::bigint for update;
 select * into strict t from public.ops_tasks where id=r.task_id for update;
 moment := clock_timestamp();
 if t.business_id is distinct from 'casa-viva' or t.executor is distinct from 'dev'
 or r.task_id <> (p_result->>'task_id')::uuid or r.repo is distinct from p_result->>'repo'
 or r.mode is distinct from 'ai' or r.auto_merge is distinct from false
 or (p_result ? 'task_public_id' and p_result->'task_public_id' <> 'null'::jsonb
     and (jsonb_typeof(p_result->'task_public_id') <> 'number'
          or (p_result->>'task_public_id') is distinct from t.public_id::text)) then
  raise exception 'run/task mismatch or unsafe mode';
 end if;
 select payload into prior from public.ops_ai_result_receipts where run_id=r.id;
 if found then
  if prior <> p_result then raise exception 'conflicting duplicate'; end if;
  return jsonb_build_object('status','duplicate','run_id',r.id);
 end if;
 if exists(select 1 from public.ops_dev_runs other where other.task_id=t.id and other.id<>r.id and other.status in ('waiting_ai','waiting_approval','waiting_approval','running')) then raise exception 'ambiguous task runs'; end if;
 if r.status is distinct from 'waiting_ai' or t.status is distinct from 'waiting_ai' then raise exception 'invalid state'; end if;
 if jsonb_typeof(p_result->'ttl_seconds') is distinct from 'number'
 or coalesce(p_result->>'ttl_seconds','') !~ '^[0-9]+$'
 or (p_result->>'ttl_seconds')::int not between 1 and 3600 then raise exception 'invalid ttl'; end if;
 finished := (p_result->>'finished_at')::timestamptz;
 expires := (p_result->>'expires_at')::timestamptz;
 if finished is null or expires is null or finished>moment or expires<=moment or expires<=finished
 or expires-finished > make_interval(secs => (p_result->>'ttl_seconds')::int) then raise exception 'invalid result time'; end if;
 if p_result ? 'executor' and p_result->'executor' <> 'null'::jsonb and
 (jsonb_typeof(p_result->'executor') <> 'string' or length(p_result->>'executor') not between 1 and 80) then raise exception 'invalid executor'; end if;
 if p_result ? 'logs' and p_result->'logs' <> 'null'::jsonb then
  if jsonb_typeof(p_result->'logs') <> 'array' then raise exception 'invalid logs'; end if;
  if jsonb_array_length(p_result->'logs')>10 or exists(select 1 from jsonb_array_elements(p_result->'logs') x where jsonb_typeof(x)<>'string' or length(x #>> '{}')>2000) then raise exception 'logs limit'; end if;
 end if;
 if p_result->>'status'='completed' then
  if p_result->'error' is distinct from 'null'::jsonb
  or jsonb_typeof(p_result->'base_sha') is distinct from 'string'
  or coalesce(p_result->>'base_sha','') !~ '^[0-9a-f]{40}$'
  or r.base_sha is null or r.base_sha <> p_result->>'base_sha'
  or jsonb_typeof(p_result->'diff') is distinct from 'string'
  or coalesce(length(p_result->>'diff'),0) not between 1 and 400000
  or jsonb_typeof(p_result->'changed_paths') is distinct from 'array'
  then raise exception 'invalid completed result'; end if;
  if jsonb_array_length(p_result->'changed_paths') not between 1 and 12
  or (select count(distinct x) from jsonb_array_elements(p_result->'changed_paths') x) <> jsonb_array_length(p_result->'changed_paths')
  or jsonb_typeof(r.allowed_paths) is distinct from 'array' then raise exception 'invalid paths'; end if;
  if exists(select 1 from jsonb_array_elements(p_result->'changed_paths') x where jsonb_typeof(x)<>'string') then raise exception 'invalid path type'; end if;
  for path in select x #>> '{}' from jsonb_array_elements(p_result->'changed_paths') x loop
   if jsonb_typeof(to_jsonb(path)) <> 'string' or path is null or length(path) not between 1 and 220 or path ~ '(^/|(^|/)\.\.?(/|$)|//|/$|\\|~|(^|/)\.git)'
   or not exists(select 1 from jsonb_array_elements_text(r.allowed_paths) a(prefix)
     where prefix<>'' and (path=prefix or (right(prefix,1)='/' and starts_with(path,prefix))))
   then raise exception 'path outside allowlist'; end if;
  end loop;
  outcome := 'waiting_approval';
 else
  if jsonb_typeof(p_result->'error') is distinct from 'string'
  or coalesce(length(p_result->>'error'),0) not between 1 and 8000
  or p_result->'changed_paths' is distinct from '[]'::jsonb
  or p_result->'diff' is distinct from 'null'::jsonb then raise exception 'invalid failed result'; end if;
  outcome := 'failed';
 end if;
 insert into public.ops_ai_result_receipts(run_id,task_id,payload) values(r.id,t.id,p_result);
 if outcome='waiting_approval' then
  if exists(select 1 from public.ops_approvals where task_id=t.id and status='pending') then raise exception 'approval already pending'; end if;
  insert into public.ops_approvals(task_id,requested_for_role,warning,impact,proposed_change,status)
  values(t.id,'owner','Revisión humana obligatoria. Sin integración automática.',
    jsonb_build_object('summary','Diff IA recibido; no aplicado.','risk',t.risk), jsonb_build_object('run_id',r.id,'receipt','ops_ai_result_receipts','base_sha',r.base_sha,'changed_paths',p_result->'changed_paths'),'pending');
 end if;
 update public.ops_dev_runs set status=outcome, summary='Resultado IA recibido; sin integración.',
 error=p_result->>'error', logs=p_result->>'logs', updated_at=moment,
 finished_at=case when outcome='failed' then moment else null end where id=r.id;
 update public.ops_tasks set status=case when outcome='failed' then 'failed' else 'waiting_approval' end,
 approval_required=(outcome='waiting_approval'), claim_expires_at=null,
 claimed_by=null, claimed_at=null, error=p_result->>'error', updated_at=moment,
 finished_at=case when outcome='failed' then moment else null end,
 result=jsonb_build_object('summary','Resultado IA recibido; sin integración.','run_id',r.id,'receipt','ops_ai_result_receipts') where id=t.id;
 insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
 values(t.id,t.thread_id,case when outcome='failed' then 'ai_result_failed' else 'ai_result_validated' end,
 'worker','opencode-supabase-result-consumer-v1',jsonb_build_object('run_id',r.id,'receipt','ops_ai_result_receipts'));
 return jsonb_build_object('status',outcome,'run_id',r.id);
end $$;
revoke all on function public.ops_accept_opencode_result(jsonb) from public, anon, authenticated;
grant execute on function public.ops_accept_opencode_result(jsonb) to service_role;

-- Human decision keeps AI results out of the dev queue; generic decisions retain existing behavior.
CREATE OR REPLACE FUNCTION public.ops_decide_approval(p_public_id bigint, p_decision text, p_actor_role text DEFAULT 'owner'::text, p_actor_ref text DEFAULT 'ernesto'::text, p_note text DEFAULT NULL::text)
 RETURNS TABLE(approval_status text, task_public_id bigint, task_status text)
 LANGUAGE plpgsql
 SET search_path TO 'public'
AS $function$
declare
  v_approval public.ops_approvals%rowtype;
  v_task public.ops_tasks%rowtype;
  v_decision text;
  v_pending integer;
begin
  v_decision:=lower(trim(coalesce(p_decision,'')));
  if v_decision not in ('approve','reject') then
    raise exception 'decisión inválida';
  end if;

  select * into v_approval
  from public.ops_approvals
  where public_id=p_public_id
  for update;

  if not found then
    raise exception 'aprobación % no existe',p_public_id;
  end if;

  select * into v_task
  from public.ops_tasks
  where id=v_approval.task_id
  for update;

  if v_approval.proposed_change->>'receipt' = 'ops_ai_result_receipts' then
    if v_approval.status='pending' then
      if v_task.status is distinct from 'waiting_approval' then
        raise exception 'la tarea IA ya no espera aprobación';
      end if;
      if v_approval.expires_at is not null and v_approval.expires_at <= now() then
        raise exception 'aprobación IA caducada';
      end if;
      if not exists(
        select 1 from public.ops_ai_result_receipts receipt
        join public.ops_dev_runs run on run.id=receipt.run_id
        where receipt.task_id=v_task.id
          and receipt.run_id::text=v_approval.proposed_change->>'run_id'
          and run.task_id=v_task.id and run.status='waiting_approval'
      ) then raise exception 'recibo/run IA no conciliados'; end if;
      update public.ops_approvals
      set status=case when v_decision='approve' then 'approved' else 'rejected' end,
          decided_by_role=coalesce(nullif(p_actor_role,''),'owner'),
          decided_by_ref=coalesce(nullif(p_actor_ref,''),'ernesto'),
          decision_note=coalesce(p_note,'Decisión humana sobre resultado IA'),
          decided_at=now()
      where id=v_approval.id;
      -- An accepted result waits for technical promotion; NEVER requeue it.
      update public.ops_dev_runs
      set status='blocked',
          summary=case when v_decision='approve'
            then 'Resultado aprobado; pendiente de PR, CI y promoción técnica.'
            else 'Resultado rechazado por revisión humana.' end,
          updated_at=now(), finished_at=case when v_decision='reject' then now() else null end
      where id::text=v_approval.proposed_change->>'run_id' and task_id=v_task.id;
      update public.ops_tasks
      set status=case when v_decision='approve' then 'verifying' else 'blocked' end,
          executor='human', approval_required=false,
          result=coalesce(result,'{}'::jsonb)||jsonb_build_object(
            'summary',case when v_decision='approve'
              then 'Cambio aprobado; pendiente de PR, pruebas y promoción técnica.'
              else 'Cambio rechazado; no se aplicó ningún diff.' end,
            'human_decision',v_decision,'promotion_required',v_decision='approve'),
          updated_at=now()
      where id=v_task.id;
      insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
      values(v_task.id,v_task.thread_id,
        case when v_decision='approve' then 'ai_result_approved' else 'ai_result_rejected' end,
        coalesce(nullif(p_actor_role,''),'owner'),coalesce(nullif(p_actor_ref,''),'ernesto'),
        jsonb_build_object('approval_public_id',p_public_id,'run_id',v_approval.proposed_change->'run_id',
                          'note',p_note,'requeued',false,'deployed',false));
    end if;
    return query select a.status,t.public_id,t.status
      from public.ops_approvals a join public.ops_tasks t on t.id=a.task_id where a.id=v_approval.id;
    return;
  end if;

  if v_approval.status='pending' then
    if v_decision='approve' then
      update public.ops_approvals
      set status='approved',
          decided_by_role=coalesce(nullif(p_actor_role,''),'owner'),
          decided_by_ref=coalesce(nullif(p_actor_ref,''),'ernesto'),
          decision_note=coalesce(p_note,'Aprobado desde Casa Viva Control OS'),
          decided_at=now()
      where id=v_approval.id;

      select count(*) into v_pending
      from public.ops_approvals
      where task_id=v_task.id and status='pending' and id<>v_approval.id;

      update public.ops_tasks
      set status=case when status='waiting_approval' and v_pending=0 then 'triaged' else status end,
          approval_required=(v_pending>0),
          updated_at=now()
      where id=v_task.id;

      insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
      values(v_task.id,v_task.thread_id,'approval_approved',
             coalesce(nullif(p_actor_role,''),'owner'),
             coalesce(nullif(p_actor_ref,''),'ernesto'),
             jsonb_build_object('approval_public_id',p_public_id,'note',p_note));
    else
      update public.ops_approvals
      set status='rejected',
          decided_by_role=coalesce(nullif(p_actor_role,''),'owner'),
          decided_by_ref=coalesce(nullif(p_actor_ref,''),'ernesto'),
          decision_note=coalesce(p_note,'Rechazado desde Casa Viva Control OS'),
          decided_at=now()
      where id=v_approval.id;

      update public.ops_tasks
      set status=case when status not in ('done','cancelled','superseded') then 'blocked' else status end,
          approval_required=false,
          updated_at=now()
      where id=v_task.id;

      insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
      values(v_task.id,v_task.thread_id,'approval_rejected',
             coalesce(nullif(p_actor_role,''),'owner'),
             coalesce(nullif(p_actor_ref,''),'ernesto'),
             jsonb_build_object('approval_public_id',p_public_id,'note',p_note));
    end if;
  end if;

  return query
  select a.status,t.public_id,t.status
  from public.ops_approvals a
  join public.ops_tasks t on t.id=a.task_id
  where a.id=v_approval.id;
end
$function$

