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
commit;
