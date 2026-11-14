-- PostgreSQL desechable del CI, sin conexión a producción.
begin;
create table public.ops_tasks(
 id uuid primary key default gen_random_uuid(), public_id bigint generated always as identity,
 thread_id uuid, business_id text default 'casa-viva', source text default 'control-private',
 task_type text default 'owner_request', requested_by_role text default 'owner',
 requested_by_ref text default 'ernesto', payload jsonb default '{}', risk text default 'guarded',
 executor text, status text default 'triaged', approval_required boolean default false,
 started_at timestamptz,updated_at timestamptz,finished_at timestamptz,
 result jsonb,error text,evidence jsonb default '[]',claimed_by text,claimed_at timestamptz,claim_expires_at timestamptz);
create table public.crm_ops_bridge(id bigint generated always as identity primary key,requested_by text,
 action text,args jsonb,status text,ops_task_id uuid,started_at timestamptz,finished_at timestamptz,result jsonb,error text);
create table public.ops_events(id bigint generated always as identity,task_id uuid,thread_id uuid,event_type text,actor_type text,actor_ref text,detail jsonb);
\ir owner-bot-error-review.sql
create trigger route_owner after insert or update of status,approval_required on public.ops_tasks
 for each row execute function public.ops_route_readonly_owner_task();
create trigger project_bridge after insert or update of status on public.crm_ops_bridge
 for each row execute function public.ops_project_bridge_event();
do $tests$
declare tid uuid; number bigint; bridge bigint; n integer;
begin
 -- Ordinary owner request routes and binds atomically.
 insert into public.ops_tasks(payload) values('{"text":"revisar errores del bot"}') returning id,public_id into tid,number;
 select id into strict bridge from public.crm_ops_bridge where ops_task_id=tid;
 if not exists(select 1 from public.ops_tasks where id=tid and status='running' and risk='safe'
    and executor='ops' and task_type='ops_operation' and payload->'ops'->'bridge_id'=to_jsonb(bridge))
 then raise exception 'missing atomic routing/binding'; end if;
 update public.crm_ops_bridge set status='done',result='{"returncode":0,"output":"21 errores registrados"}' where id=bridge;
 if not exists(select 1 from public.ops_tasks where id=tid and status='done' and result->>'summary' like '%21 errores registrados%')
 then raise exception 'result not closed/summarized'; end if;
 update public.crm_ops_bridge set status='done' where id=bridge;
 if (select count(*) from public.ops_events where task_id=tid and event_type='ops_operation_done')<>1 then raise exception 'duplicate close'; end if;
 -- Unknown instructions and risk/approval/actor gates stay intact.
 select count(*) into n from public.crm_ops_bridge;
 insert into public.ops_tasks(payload) values('{"text":"revisar errores del bot y cambiar los precios"}');
 insert into public.ops_tasks(payload,risk) values('{"text":"errores del bot"}','sensitive');
 insert into public.ops_tasks(payload,approval_required) values('{"text":"errores del bot"}',true);
 insert into public.ops_tasks(payload,requested_by_ref) values('{"text":"errores del bot"}','otro');
 insert into public.ops_tasks(payload,requested_by_ref) values('{"text":"errores del bot"}',null);
 insert into public.ops_tasks(payload,requested_by_role) values('{"text":"errores del bot"}',null);
 insert into public.ops_tasks(payload,source) values('{"text":"errores del bot"}','legacy-crm-supervisor');
 if (select count(*) from public.crm_ops_bridge)<>n then raise exception 'guard bypass'; end if;
 -- WhatsApp path preserves owner_change and requires its original identity.
 insert into public.ops_tasks(payload,source,task_type,requested_by_role,requested_by_ref)
 values('{"request":"errores del bot"}','whatsapp-owner','owner_change','owner_operational','fixture-owner') returning id into tid;
 if not exists(select 1 from public.ops_tasks where id=tid and status='running' and task_type='owner_change') then raise exception 'WhatsApp contract broken'; end if;
 select id into strict bridge from public.crm_ops_bridge where ops_task_id=tid;
 update public.crm_ops_bridge set status='failed',result='{"returncode":1}' where id=bridge;
 if not exists(select 1 from public.ops_tasks where id=tid and status='failed') then raise exception 'failure not projected'; end if;
 -- A result from the wrong bridge cannot complete a task.
 insert into public.ops_tasks(payload) values('{"text":"revisa los errores del bot"}') returning id into tid;
 select id into strict bridge from public.crm_ops_bridge where ops_task_id=tid;
 update public.ops_tasks set payload=jsonb_set(payload,'{ops,bridge_id}',to_jsonb(bridge+1000)) where id=tid;
 update public.crm_ops_bridge set status='done',result='{"returncode":0}' where id=bridge;
 if not exists(select 1 from public.ops_tasks where id=tid and status='running') then raise exception 'wrong run completed task'; end if;
end
$tests$;
rollback;
