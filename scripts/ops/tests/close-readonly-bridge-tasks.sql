begin;
do $test$
declare t uuid; b bigint; scenario text; actual text; expected text; event_count int;
begin
foreach scenario in array array['success','failure','blocked','bad_result','wrong_run','sensitive','approval','dev','cancelled','no_contract'] loop
 insert into public.ops_tasks(source,task_type,title,status,executor,risk,approval_required,payload)
 values('control-os-sql-test','ops_operation','Fixture cierre: '||scenario,'running','ops','safe',false,
 '{"ops":{"action":"health","completion_mode":"bridge_readonly_v1"}}') returning id into t;
 insert into public.crm_ops_bridge(action,ops_task_id) values('health',t) returning id into b;
 update public.ops_tasks set payload=jsonb_set(payload,'{ops,bridge_id}',to_jsonb(b)) where id=t;
 if scenario='wrong_run' then update ops_tasks set payload=jsonb_set(payload,'{ops,bridge_id}',to_jsonb(b+1)) where id=t;
 elsif scenario='sensitive' then update ops_tasks set risk='sensitive' where id=t;
 elsif scenario='approval' then update ops_tasks set approval_required=true where id=t;
 elsif scenario='dev' then update ops_tasks set executor='dev' where id=t;
 elsif scenario='cancelled' then update ops_tasks set status='cancelled' where id=t;
 elsif scenario='no_contract' then update ops_tasks set payload='{}' where id=t;
 end if;
 update public.crm_ops_bridge set status=case when scenario='failure' then 'failed' when scenario='blocked' then 'blocked' else 'done' end,
 result=jsonb_build_object('returncode',case when scenario='bad_result' then 7 else 0 end), finished_at=now() where id=b;
 select status into actual from ops_tasks where id=t;
 expected:=case when scenario='success' then 'done' when scenario in ('failure','blocked','bad_result') then 'failed' when scenario='cancelled' then 'cancelled' else 'running' end;
 if actual<>expected then raise exception 'scenario % expected % got %',scenario,expected,actual; end if;
 select count(*) into event_count from ops_events where task_id=t and event_type in ('ops_operation_done','ops_operation_failed');
 if event_count<>(case when expected in ('done','failed') then 1 else 0 end) then raise exception 'event count wrong: %',scenario; end if;
 update public.crm_ops_bridge set result=result where id=b;
 if (select count(*) from ops_events where task_id=t and event_type in ('ops_operation_done','ops_operation_failed'))<>event_count then raise exception 'duplicate event: %',scenario; end if;
end loop;
end $test$;
select '10 scenarios + duplicate checks passed; fixtures rolled back' as validation;
rollback;