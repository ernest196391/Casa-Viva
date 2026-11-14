-- Stop any result consumer first. Preserve receipts, pending approvals and evidence.
revoke execute on function public.ops_accept_opencode_result(jsonb) from service_role;
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

