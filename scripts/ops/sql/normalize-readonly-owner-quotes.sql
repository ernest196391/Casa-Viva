CREATE OR REPLACE FUNCTION public.ops_route_readonly_owner_task()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public'
AS $function$
declare command text; action_name text;
begin
 if new.business_id<>'casa-viva' or new.status<>'triaged' or new.approval_required
    or new.risk not in ('safe','guarded') then return new; end if;
 if not ((new.source='control-private' and new.task_type='owner_request'
          and new.requested_by_role='owner' and new.requested_by_ref='ernesto')
      or (new.source='whatsapp-owner' and new.task_type='owner_change'
          and new.requested_by_role='owner_operational' and new.requested_by_ref is not null))
 then return new; end if;
 command:=lower(btrim(coalesce(new.payload->>'request',new.payload->>'text',''), ' «»“”"'));
 command:=regexp_replace(command,'[.!?]+$','','g');
 action_name:=case command
   when 'salud del sistema' then 'health'
   when 'revisa la salud del sistema' then 'health'
   when 'revisar salud' then 'health'
   when 'estado de wordpress' then 'wp_status'
   when 'estado del bot' then 'vivabot_git_status'
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
end $function$
