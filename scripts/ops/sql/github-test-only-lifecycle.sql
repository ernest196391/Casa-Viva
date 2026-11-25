-- Candidate migration; never apply before disposable fixture and host certification.
-- Legacy claim remains unchanged: test_only payload has no dev.ready.
alter table public.ops_dev_runs drop constraint ops_dev_runs_mode_check;
alter table public.ops_dev_runs add constraint ops_dev_runs_mode_check check(mode in ('deterministic','ai','test-only'));

create function public.ops_claim_github_test_only(p_worker text)
returns jsonb language plpgsql security invoker set search_path=pg_catalog,public as $$
declare t public.ops_tasks%rowtype; rid bigint; moment timestamptz:=clock_timestamp(); lease timestamptz;
begin
 if p_worker is distinct from 'cv-dev-worker-v1' then raise exception 'invalid worker'; end if;
 -- Expire abandoned work; never replay an interrupted run automatically.
 for t in select * from public.ops_tasks where source='github-test-only'
 and status in ('triaged','running') and (deadline_at<=moment or
 (status='running' and claim_expires_at<=moment)) for update skip locked
 loop
 update public.ops_dev_runs set status='failed',error='expired',finished_at=moment,updated_at=moment
 where task_id=t.id and mode='test-only' and status='testing';
 update public.ops_tasks set status='failed',error='expired',finished_at=moment,updated_at=moment,
 claimed_by=null,claimed_at=null,claim_expires_at=null,
 result=jsonb_build_object('outcome','expired') where id=t.id;
 insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
 values(t.id,t.thread_id,'github_test_expired','worker',p_worker,'{}');
 end loop;
 select * into t from public.ops_tasks where source='github-test-only' and business_id='casa-viva'
 and executor='dev' and task_type='test_only' and status='triaged' and risk='safe'
 and not approval_required and attempts=0 and deadline_at>moment+interval '10 seconds'
 and payload->'test_only'->>'repo'='casa-viva'
 and payload->'test_only'->>'profile'='ops-python-contract'
 and payload->'test_only'->>'sha' ~ '^[0-9a-f]{40}$' and not (payload ? 'dev')
 and not exists(select 1 from public.ops_dev_runs r where r.task_id=ops_tasks.id)
 order by created_at,id for update skip locked limit 1;
 if not found then return null; end if;
 lease:=least(t.deadline_at,moment+interval '180 seconds');
 insert into public.ops_dev_runs(task_id,attempt,repo,mode,provider,status,base_sha,auto_merge,allowed_paths,test_profile)
 values(t.id,1,'casa-viva','test-only','github-test-only','testing',t.payload->'test_only'->>'sha',false,'[]','ops-python-contract')
 returning id into rid;
 update public.ops_tasks set status='running',attempts=1,claimed_by=p_worker,claimed_at=moment,
 claim_expires_at=lease,started_at=moment,updated_at=moment where id=t.id;
 insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
 values(t.id,t.thread_id,'github_test_claimed','worker',p_worker,jsonb_build_object('run_id',rid,'lease',lease));
 return jsonb_build_object('run_id',rid,'task_id',t.id,'sha',t.payload->'test_only'->>'sha',
 'profile','ops-python-contract','lease',lease);
end $$;

create function public.ops_finish_github_test_only(p_task_id uuid,p_run_id bigint,p_worker text,p_outcome text)
returns jsonb language plpgsql security invoker set search_path=pg_catalog,public as $$
declare t public.ops_tasks%rowtype; r public.ops_dev_runs%rowtype; moment timestamptz; terminal text; packet jsonb;
begin
 if p_worker is distinct from 'cv-dev-worker-v1' or p_outcome is null or p_outcome not in
 ('passed','failed','timeout','blocked') then raise exception 'invalid result'; end if;
 select * into strict t from public.ops_tasks where id=p_task_id for update;
 select * into strict r from public.ops_dev_runs where id=p_run_id and task_id=t.id for update;
 moment:=clock_timestamp();
 if t.source is distinct from 'github-test-only' or r.mode is distinct from 'test-only'
 or r.auto_merge or r.base_sha is distinct from t.payload->'test_only'->>'sha'
 then raise exception 'invalid fence'; end if;
 packet:=jsonb_build_object('outcome',p_outcome,'run_id',r.id,'sha',r.base_sha);
 if t.status in ('done','failed') and t.result=packet then return packet; end if;
 if t.status is distinct from 'running' or r.status is distinct from 'testing'
 or t.claimed_by is distinct from p_worker or t.claim_expires_at is null
 or t.claim_expires_at<=moment or t.deadline_at<=moment then raise exception 'stale fence'; end if;
 terminal:=case when p_outcome='passed' then 'done' else 'failed' end;
 update public.ops_dev_runs set status=terminal,finished_at=moment,updated_at=moment,
 summary=p_outcome,error=case when terminal='failed' then p_outcome else null end where id=r.id;
 update public.ops_tasks set status=terminal,result=packet,finished_at=moment,updated_at=moment,
 error=case when terminal='failed' then p_outcome else null end,
 claimed_by=null,claimed_at=null,claim_expires_at=null where id=t.id;
 insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
 values(t.id,t.thread_id,'github_test_finished','worker',p_worker,packet);
 return packet;
end $$;
revoke all on function public.ops_claim_github_test_only(text) from public,anon,authenticated;
revoke all on function public.ops_finish_github_test_only(uuid,bigint,text,text) from public,anon,authenticated;
grant execute on function public.ops_claim_github_test_only(text) to service_role;
grant execute on function public.ops_finish_github_test_only(uuid,bigint,text,text) to service_role;
