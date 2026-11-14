-- SOLO base PostgreSQL desechable vacía; nunca producción. NO ejecutada en esta entrega.
-- psql -X -v ON_ERROR_STOP=1 -f scripts/ops/sql/opencode-result-receipts.fixture.sql
begin;
create table public.ops_tasks(id uuid primary key, public_id bigint, thread_id uuid, status text,
 approval_required boolean, claim_expires_at timestamptz, claimed_by text, claimed_at timestamptz,
 risk text default 'safe', result jsonb, finished_at timestamptz, error text, updated_at timestamptz,
 check(status in ('waiting_ai','waiting_approval','failed','cancelled')));
create table public.ops_dev_runs(id bigint primary key,task_id uuid,repo text,mode text,status text,
 base_sha text,work_sha text,auto_merge boolean,allowed_paths jsonb,summary text,error text,logs text,
 started_at timestamptz,updated_at timestamptz,finished_at timestamptz,
 check(status in ('claimed','preparing','editing','testing','pushed','waiting_ci','waiting_approval','deploy_queued','deploying','done','failed','blocked','waiting_ai')));
create table public.ops_approvals(task_id uuid,requested_for_role text,warning text,impact jsonb,
 proposed_change jsonb,status text check(status in ('pending','approved','rejected','expired','cancelled')));
create table public.ops_events(task_id uuid,thread_id uuid,event_type text,actor_type text,actor_ref text,detail jsonb);
-- Roles anon/authenticated/service_role deben existir en el entorno de pruebas.
\ir opencode-result-receipts.sql
insert into public.ops_tasks(id,public_id,status) values('00000000-0000-0000-0000-000000000001',42,'waiting_ai');
insert into public.ops_dev_runs(id,task_id,repo,mode,status,base_sha,auto_merge,allowed_paths)
 values(1,'00000000-0000-0000-0000-000000000001','casa-viva','ai','waiting_ai',repeat('a',40),false,'["docs/"]');
do $$
declare p jsonb; bad jsonb; patch jsonb; before_state jsonb;
begin
 p := jsonb_build_object('version','opencode-result-v1','run_id','1','task_id','00000000-0000-0000-0000-000000000001',
 'task_public_id',42,'repo','casa-viva','status','completed','error',null,'base_sha',repeat('a',40),
 'changed_paths',jsonb_build_array('docs/a.md'),'diff','+a','logs','[]'::jsonb,
 'finished_at',clock_timestamp()-interval '10 seconds','expires_at',clock_timestamp()+interval '60 seconds','ttl_seconds',100);
 for patch in select value from jsonb_array_elements(jsonb_build_array(
  '{"task_id":"00000000-0000-0000-0000-000000000002"}'::jsonb,
  '{"run_id":"2"}'::jsonb,'{"repo":"other"}'::jsonb,'{"task_public_id":43}'::jsonb,
  '{"status":"approved"}'::jsonb,'{"commands":["id"]}'::jsonb,
  jsonb_build_object('base_sha',repeat('b',40)),jsonb_build_object('diff',repeat('x',400001)),
  '{"changed_paths":["src/a"]}'::jsonb,'{"changed_paths":["docs/../a"]}'::jsonb,
  '{"changed_paths":["docs/a","docs/a"]}'::jsonb,'{"ttl_seconds":0}'::jsonb)) loop
  bad:=p||patch;
  begin
   perform public.ops_accept_opencode_result(bad);
   raise exception 'fixture accepted invalid input' using errcode='ZX001';
  exception when sqlstate 'ZX001' then raise; when others then null;
  end;
  if exists(select 1 from public.ops_ai_result_receipts) or exists(select 1 from public.ops_approvals)
  or exists(select 1 from public.ops_events) or exists(select 1 from public.ops_tasks where status<>'waiting_ai')
  or exists(select 1 from public.ops_dev_runs where status<>'waiting_ai') then raise exception 'partial mutation'; end if;
 end loop;
 update public.ops_tasks set status='cancelled';
 begin
  perform public.ops_accept_opencode_result(p);
  raise exception 'accepted cancelled task' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 update public.ops_tasks set status='waiting_ai';
 -- Fallo tardío: obliga rollback de recibo, aprobación y updates anteriores al evento.
 alter table public.ops_events add constraint fixture_fail check(false);
 begin
  perform public.ops_accept_opencode_result(p);
  raise exception 'expected event failure' using errcode='ZX001';
 exception when check_violation then null; end;
 if exists(select 1 from public.ops_ai_result_receipts) or exists(select 1 from public.ops_approvals)
 or exists(select 1 from public.ops_dev_runs where status<>'waiting_ai')
 or exists(select 1 from public.ops_tasks where status<>'waiting_ai') then raise exception 'atomicity broken'; end if;
 alter table public.ops_events drop constraint fixture_fail;
 perform public.ops_accept_opencode_result(p);
 if public.ops_accept_opencode_result(p)->>'status'<>'duplicate' then raise exception 'duplicate not recognized'; end if;
 begin
  perform public.ops_accept_opencode_result(p||'{"diff":"different"}'::jsonb);
  raise exception 'accepted conflicting duplicate' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 if (select count(*) from public.ops_approvals)<>1 or (select count(*) from public.ops_events)<>1
 or (select count(*) from public.ops_ai_result_receipts)<>1 then raise exception 'duplicate side effects'; end if;
 if exists(select 1 from public.ops_tasks where status<>'waiting_approval' or approval_required is distinct from true)
 or exists(select 1 from public.ops_dev_runs where status<>'waiting_approval') then raise exception 'approval bypass'; end if;
end $$;
rollback;
