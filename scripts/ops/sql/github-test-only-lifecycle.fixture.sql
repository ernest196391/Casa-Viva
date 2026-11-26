-- Disposable PostgreSQL only; never production.
begin;
create table public.ops_tasks(
 id uuid primary key default gen_random_uuid(),public_id bigint generated always as identity,
 thread_id uuid,business_id text not null,source text not null,source_ref text,
 task_type text not null,title text not null,payload jsonb not null,dedupe_key text,
 priority text,risk text,status text,executor text,requested_by_role text,requested_by_ref text,
 approval_required boolean not null,deadline_at timestamptz,attempts integer default 0,
 created_at timestamptz default now(),updated_at timestamptz,started_at timestamptz,
 finished_at timestamptz,claimed_by text,claimed_at timestamptz,claim_expires_at timestamptz,
 error text,result jsonb);
create table public.ops_dev_runs(id bigint generated always as identity primary key,
 task_id uuid references public.ops_tasks(id),attempt integer,repo text,
 mode text constraint ops_dev_runs_mode_check check(mode in ('deterministic','ai')),
 provider text,status text,base_sha text,auto_merge boolean,allowed_paths jsonb,test_profile text,
 finished_at timestamptz,updated_at timestamptz,summary text,error text,unique(task_id,attempt));
create table public.ops_events(task_id uuid,thread_id uuid,event_type text,actor_type text,actor_ref text,detail jsonb);
\ir github-test-only-enqueue.sql
\ir github-test-only-lifecycle.sql
\ir github-test-only-report.sql
do $$
declare a jsonb; b jsonb; c jsonb; t uuid; rid bigint; nonce text:=repeat('A',20);
begin
 if has_function_privilege('anon','public.ops_claim_github_test_only(text)','execute')
 or has_function_privilege('authenticated','public.ops_finish_github_test_only(uuid,bigint,text,text)','execute')
 then raise exception 'public access'; end if;
 perform public.ops_enqueue_github_test_only(123,nonce,'casa-viva',repeat('a',40),'ops-python-contract',
 clock_timestamp()+interval '10 minutes',encode(sha256(convert_to('123:'||nonce,'UTF8')),'hex'));
 a:=public.ops_claim_github_test_only('cv-dev-worker-v1');
 if a is null or a->>'sha'<>repeat('a',40) then raise exception 'claim missing'; end if;
 t:=(a->>'task_id')::uuid; rid:=(a->>'run_id')::bigint;
 if public.ops_claim_github_test_only('cv-dev-worker-v1') is not null then raise exception 'double claim'; end if;
 begin
 perform public.ops_finish_github_test_only(t,rid+1,'cv-dev-worker-v1','passed');
 raise exception 'accepted wrong fence' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 b:=public.ops_finish_github_test_only(t,rid,'cv-dev-worker-v1','passed');
 c:=public.ops_finish_github_test_only(t,rid,'cv-dev-worker-v1','passed');
 if b is distinct from c or b->>'outcome'<>'passed' or (select count(*) from public.ops_events)<>3 then raise exception 'terminal replay'; end if;
 begin
 perform public.ops_finish_github_test_only(t,rid,'cv-dev-worker-v1','failed');
 raise exception 'accepted conflicting terminal outcome' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 if not public.ops_ack_github_test_only(t,901) or not public.ops_ack_github_test_only(t,901) then raise exception 'ack failed'; end if;
 if public.ops_finish_github_test_only(t,rid,'cv-dev-worker-v1','passed') is distinct from b then raise exception 'finish after ack'; end if;
 if (select count(*) from public.ops_events where event_type='github_test_reported')<>1 then raise exception 'duplicate ack event'; end if;
 begin
 perform public.ops_ack_github_test_only(t,902);
 raise exception 'accepted conflicting ack' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 if has_function_privilege('anon','public.ops_ack_github_test_only(uuid,bigint)','execute') then raise exception 'public ack'; end if;
 nonce:=repeat('B',20);
 perform public.ops_enqueue_github_test_only(124,nonce,'casa-viva',repeat('b',40),'ops-python-contract',
 clock_timestamp()+interval '10 minutes',encode(sha256(convert_to('124:'||nonce,'UTF8')),'hex'));
 a:=public.ops_claim_github_test_only('cv-dev-worker-v1');
 t:=(a->>'task_id')::uuid; rid:=(a->>'run_id')::bigint;
 update public.ops_tasks set claim_expires_at=clock_timestamp()-interval '1 second' where id=t;
 begin
 perform public.ops_finish_github_test_only(t,rid,'cv-dev-worker-v1','passed');
 raise exception 'accepted stale lease' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 if public.ops_claim_github_test_only('cv-dev-worker-v1') is not null then raise exception 'replayed expired job'; end if;
 if not exists(select 1 from public.ops_tasks where id=t and status='failed' and result->>'outcome'='expired') then raise exception 'expiry not persisted'; end if;
 if not exists(select 1 from public.ops_dev_runs where id=rid and status='failed' and not auto_merge) then raise exception 'run not expired'; end if;
end $$;
rollback;
