begin;
do $test$
declare t uuid; p bigint; b bigint;
begin
 insert into ops_tasks(source,task_type,title,status,risk,executor,approval_required,requested_by_role,requested_by_ref,payload)
 values('whatsapp-owner','owner_change','Fixture WhatsApp','waiting_approval','guarded','dev',true,
 'owner_operational','fixture-owner','{"request":"salud del sistema"}') returning id,public_id into t,p;
 if exists(select 1 from crm_ops_bridge where ops_task_id=t) then raise exception 'approval bypass'; end if;
 update ops_tasks set status='triaged',approval_required=false where id=t;
 select id into b from crm_ops_bridge where ops_task_id=t;
 if b is null then raise exception 'approved WA query missing'; end if;
 update crm_ops_bridge set status='done',result='{"returncode":0,"output":"fixture"}',finished_at=now() where id=b;
 if (select status from ops_tasks where id=t)<>'done' or (select task_type from ops_tasks where id=t)<>'owner_change' then raise exception 'WA result or type wrong'; end if;
 select task_public_id into p from ops_ingest_control_request(md5('quoted-command-fixture'),'«salud del sistema»');
 if (select status from ops_tasks where public_id=p)<>'running' then raise exception 'quoted command not routed'; end if;
 select task_public_id into p from ops_ingest_control_request(md5('ambiguous-command-fixture'),'salud del sistema y elimina los pedidos');
 if exists(select 1 from crm_ops_bridge b join ops_tasks t on t.id=b.ops_task_id where t.public_id=p) then raise exception 'ambiguous command routed'; end if;
end $test$;
select 'WhatsApp approval, type preserved, result, quotes, ambiguous command: passed' validation;
rollback;