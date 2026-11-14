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
  where t.id=v_task and t.task_type='ops_operation' and t.executor='ops'
    and t.risk='safe' and not t.approval_required and t.status='running'
    and t.payload->'ops'->>'completion_mode'='bridge_readonly_v1'
    and t.payload->'ops'->>'action'=p_action
    and p_action=any(array['health','bridge_status','wp_status','vivabot_git_status','control_sync_service_status']);
  return v_id;
end
$function$
