-- Disposable PostgreSQL only. No production connection.
begin;
create table public.ops_tasks(
 id uuid primary key default gen_random_uuid(), public_id bigint generated always as identity,
 thread_id uuid, business_id text not null, source text not null, source_ref text,
 task_type text not null,title text not null,payload jsonb not null,dedupe_key text,
 priority text,risk text,status text,executor text,requested_by_role text,requested_by_ref text,
 approval_required boolean not null,deadline_at timestamptz);
create table public.ops_events(task_id uuid,thread_id uuid,event_type text,actor_type text,actor_ref text,detail jsonb);
\ir github-test-only-enqueue.sql
do $$
declare expiry timestamptz:=clock_timestamp()+interval '10 minutes'; nonce text:=repeat('A',20); fingerprint text; first jsonb; second jsonb;
begin
 fingerprint:=encode(sha256(convert_to('123:'||nonce,'UTF8')),'hex');
 if has_function_privilege('anon','public.ops_enqueue_github_test_only(bigint,text,text,text,text,timestamptz,text)','execute')
 or has_function_privilege('authenticated','public.ops_enqueue_github_test_only(bigint,text,text,text,text,timestamptz,text)','execute')
 then raise exception 'public execution'; end if;
 first:=public.ops_enqueue_github_test_only(123,nonce,'casa-viva',repeat('a',40),'ops-python-contract',expiry,fingerprint);
 second:=public.ops_enqueue_github_test_only(123,nonce,'casa-viva',repeat('a',40),'ops-python-contract',expiry,fingerprint);
 if first->>'status'<>'queued' or second->>'status'<>'duplicate' or first->>'task_public_id'<>second->>'task_public_id' then raise exception 'idempotency failure'; end if;
 if (select count(*) from public.ops_tasks)<>1 or (select count(*) from public.ops_events)<>1 or exists(select 1 from public.ops_tasks where payload ? 'dev') then raise exception 'unsafe side effects'; end if;
 begin
 perform public.ops_enqueue_github_test_only(123,nonce,'casa-viva',repeat('b',40),'ops-python-contract',expiry,fingerprint);
 raise exception 'accepted conflicting replay' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 begin
 perform public.ops_enqueue_github_test_only(124,nonce,'casa-viva',repeat('a',40),'ops-python-contract',expiry,encode(sha256(convert_to('124:'||nonce,'UTF8')),'hex'));
 raise exception 'accepted reused nonce' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 begin
 perform public.ops_enqueue_github_test_only(123,nonce,'casa-viva',repeat('a',40),'ops-python-contract',clock_timestamp()-interval '1 second',fingerprint);
 raise exception 'accepted expired request' using errcode='ZX001';
 exception when sqlstate 'ZX001' then raise; when others then null; end;
 if (select count(*) from public.ops_tasks)<>1 or (select count(*) from public.ops_events)<>1 then raise exception 'rejected request mutated state'; end if;
end $$;
rollback;
