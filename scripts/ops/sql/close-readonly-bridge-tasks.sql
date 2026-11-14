CREATE OR REPLACE FUNCTION public.ops_project_bridge_event()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public'
AS $function$
declare
  v_thread uuid;
  v_public bigint;
  v_event text;
  v_detail jsonb;
begin
  if new.ops_task_id is null then
    return new;
  end if;

  select thread_id,public_id into v_thread,v_public
  from public.ops_tasks
  where id=new.ops_task_id;

  if v_public is null then
    return new;
  end if;

  if tg_op='INSERT' then
    v_event='bridge_queued';
  elsif new.status is distinct from old.status then
    v_event='bridge_'||new.status;
  else
    return new;
  end if;

  v_detail=jsonb_build_object(
    'bridge_id',new.id,
    'action',new.action,
    'status',new.status,
    'started_at',new.started_at,
    'finished_at',new.finished_at
  );

  if new.error is not null then
    v_detail=v_detail||jsonb_build_object('error',left(new.error,1200));
  end if;

  insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
  values(new.ops_task_id,v_thread,v_event,'worker','cv-ops-bridge',v_detail);

  if new.status in ('done','failed') then
    update public.ops_tasks
    set evidence=coalesce(evidence,'[]'::jsonb) ||
      jsonb_build_array(
        jsonb_build_object(
          'type','bridge',
          'bridge_id',new.id,
          'action',new.action,
          'status',new.status,
          'finished_at',new.finished_at,
          'error',new.error
        )
      )
    where id=new.ops_task_id;
  end if;


  -- Explicit contract for one read-only operation, bound to this exact bridge run.
  -- Development, financial and approval workflows keep their existing owners.
  if new.status in ('done','failed','blocked') and new.action = any(array[
      'health','bridge_status','wp_status','vivabot_git_status','control_sync_service_status']) then
    with completed as (
      update public.ops_tasks t
      set status=case when new.status='done' and new.result->'returncode'='0'::jsonb
                      then 'done' else 'failed' end,
          result=coalesce(t.result,'{}'::jsonb)||jsonb_build_object(
              'bridge_id',new.id,'action',new.action,'closure_mode','bridge_readonly_v1',
              'verified',new.status='done' and new.result->'returncode'='0'::jsonb),
          error=case when new.status='done' and new.result->'returncode'='0'::jsonb
                     then null else left(coalesce(new.error,'Resultado del puente no verificado'),1200) end,
          finished_at=coalesce(new.finished_at,now()),updated_at=now(),
          claimed_by=null,claimed_at=null,claim_expires_at=null
      where t.id=new.ops_task_id and t.status='running'
        and t.executor='ops' and t.task_type='ops_operation'
        and t.risk='safe' and t.approval_required=false
        and t.payload->'ops'->>'completion_mode'='bridge_readonly_v1'
        and t.payload->'ops'->>'action'=new.action
        and t.payload->'ops'->'bridge_id'=to_jsonb(new.id)
      returning t.id,t.thread_id,t.status
    )
    insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
    select id,thread_id,'ops_operation_'||status,'worker','cv-ops-bridge',
      jsonb_build_object('bridge_id',new.id,'action',new.action,'automatic',true)
    from completed;
  end if;
  return new;
end
$function$
