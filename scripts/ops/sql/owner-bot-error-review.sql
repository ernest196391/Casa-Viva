-- Consulta exacta del propietario; reutiliza la cola y el bridge allowlist existentes.
CREATE OR REPLACE FUNCTION public.ops_enqueue_bridge(p_task_public_id bigint, p_action text, p_args jsonb DEFAULT '{}'::jsonb)
 RETURNS bigint
 LANGUAGE plpgsql
 SET search_path TO 'public'
AS $function$
declare
  v_task uuid;
  v_id bigint;
begin
  select id into v_task
  from public.ops_tasks
  where public_id=p_task_public_id
  limit 1;

  if v_task is null then
    raise exception 'ops_task % no existe',p_task_public_id;
  end if;

  insert into public.crm_ops_bridge(requested_by,action,args,status,ops_task_id)
  values('control-os',p_action,coalesce(p_args,'{}'::jsonb),'queued',v_task)
  returning id into v_id;

  -- Bind within the enqueue transaction: the worker cannot observe a half-bound task.
  update public.ops_tasks t
  set payload=jsonb_set(t.payload,'{ops,bridge_id}',to_jsonb(v_id)), updated_at=now()
  where t.id=v_task and t.task_type in ('ops_operation','owner_change') and t.executor='ops'
    and t.risk='safe' and not t.approval_required and t.status='running'
    and t.payload->'ops'->>'completion_mode'='bridge_readonly_v1'
    and t.payload->'ops'->>'action'=p_action
    and p_action=any(array['health','bridge_status','wp_status','vivabot_git_status','control_sync_service_status','errors_open']);
  return v_id;
end
$function$;

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
      'health','bridge_status','wp_status','vivabot_git_status','control_sync_service_status','errors_open']) then
    with completed as (
      update public.ops_tasks t
      set status=case when new.status='done' and new.result->'returncode'='0'::jsonb
                      then 'done' else 'failed' end,
          result=coalesce(t.result,'{}'::jsonb)||jsonb_build_object(
              'bridge_id',new.id,'action',new.action,'closure_mode','bridge_readonly_v1',
              'summary',case when new.action='errors_open' and new.status='done' and new.result->'returncode'='0'::jsonb then 'Errores registrados pendientes (solo lectura; no es una auditoría nueva de todos los chats):'||E'\n'||left(coalesce(new.result->>'output',''),12000) when new.action='errors_open' then 'No se pudo consultar el registro de errores; no se aplicaron cambios.' when new.action='health' then left(coalesce(new.result->>'output',''),4000) else 'Consulta completada: '||new.action end,
              'verified',new.status='done' and new.result->'returncode'='0'::jsonb),
          error=case when new.status='done' and new.result->'returncode'='0'::jsonb
                     then null else left(coalesce(new.error,'Resultado del puente no verificado'),1200) end,
          finished_at=coalesce(new.finished_at,now()),updated_at=now(),
          claimed_by=null,claimed_at=null,claim_expires_at=null
      where t.id=new.ops_task_id and t.status='running'
        and t.executor='ops' and t.task_type in ('ops_operation','owner_change')
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
$function$;

CREATE OR REPLACE FUNCTION public.ops_route_readonly_owner_task()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public'
AS $function$
declare command text; action_name text;
begin
 if new.business_id<>'casa-viva' or new.status<>'triaged' or new.approval_required
    or new.risk not in ('safe','guarded') then return new; end if;
 if ((new.source='control-private' and new.task_type='owner_request'
          and new.requested_by_role='owner' and new.requested_by_ref='ernesto')
      or (new.source='whatsapp-owner' and new.task_type='owner_change'
          and new.requested_by_role='owner_operational' and new.requested_by_ref is not null)) is not true
 then return new; end if;
 command:=lower(btrim(coalesce(new.payload->>'request',new.payload->>'text',''), ' «»“”"'));
 command:=regexp_replace(command,'[.!?]+$','','g');
 action_name:=case command
   when 'salud del sistema' then 'health'
   when 'revisa la salud del sistema' then 'health'
   when 'revisar salud' then 'health'
   when 'estado de wordpress' then 'wp_status'
   when 'estado del bot' then 'vivabot_git_status'
   when 'revisar errores del bot' then 'errors_open'
   when 'revisa los errores del bot' then 'errors_open'
   when 'errores del bot' then 'errors_open'
   else null end;
 if action_name is null then return new; end if;
 update public.ops_tasks
 set task_type=case when source='control-private' then 'ops_operation' else task_type end,
     risk='safe',executor='ops',status='running',started_at=now(),
     payload=jsonb_set(payload,'{ops}',jsonb_build_object(
       'action',action_name,'completion_mode','bridge_readonly_v1')),
     updated_at=now()
 where id=new.id and status='triaged' and not approval_required;
 if found then
   perform public.ops_enqueue_bridge(new.public_id,action_name,'{}');
 end if;
 return new;
end $function$;
