-- Candidate only. Do not apply to production before isolated certification.
-- Live schema verified 2026-11-25 by SELECT on pg_catalog.
-- Keep test-only invisible to legacy claim RPC: no payload.dev.ready.
create unique index ops_tasks_github_test_comment_uidx on public.ops_tasks(source_ref)
 where source='github-test-only';
create unique index ops_tasks_github_test_nonce_uidx on public.ops_tasks(dedupe_key)
 where source='github-test-only';

create function public.ops_enqueue_github_test_only(
 p_comment_id bigint,p_nonce text,p_repo text,p_sha text,p_profile text,
 p_expires_at timestamptz,p_fingerprint text
) returns jsonb language plpgsql security invoker set search_path=pg_catalog,public as $$
declare t public.ops_tasks%rowtype; packet jsonb; moment timestamptz:=clock_timestamp();
begin
 if p_comment_id is null or p_comment_id<=0 or p_nonce is null or p_nonce !~ '^[A-Za-z0-9_-]{16,64}$'
 or p_repo is distinct from 'casa-viva' or p_sha is null or p_sha !~ '^[0-9a-f]{40}$'
 or p_profile is distinct from 'ops-python-contract' or p_fingerprint is null
 or p_fingerprint is distinct from encode(sha256(convert_to(p_comment_id::text||':'||p_nonce,'UTF8')),'hex')
 or p_expires_at is null or p_expires_at<=moment or p_expires_at>moment+interval '15 minutes'
 then raise exception 'invalid test-only request'; end if;
 packet:=jsonb_build_object('test_only',jsonb_build_object('repo',p_repo,'sha',p_sha,
 'profile',p_profile,'fingerprint',p_fingerprint,'expires_at',p_expires_at));
 insert into public.ops_tasks(business_id,source,source_ref,task_type,title,payload,dedupe_key,
 priority,risk,status,executor,requested_by_role,requested_by_ref,approval_required,deadline_at)
 values('casa-viva','github-test-only',p_comment_id::text,'test_only','GitHub isolated test',
 packet,p_nonce,'p2','safe','triaged','dev','owner','ernest196391',false,p_expires_at)
 on conflict do nothing returning * into t;
 if found then
  insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
  values(t.id,t.thread_id,'github_test_enqueued','worker','github-test-only',jsonb_build_object('fingerprint',p_fingerprint));
  return jsonb_build_object('status','queued','task_public_id',t.public_id);
 end if;
 select * into strict t from public.ops_tasks where source='github-test-only' and source_ref=p_comment_id::text;
 if t.dedupe_key is distinct from p_nonce or t.payload is distinct from packet then
  raise exception 'conflicting test-only replay';
 end if;
 return jsonb_build_object('status','duplicate','task_public_id',t.public_id);
end $$;
revoke all on function public.ops_enqueue_github_test_only(bigint,text,text,text,text,timestamptz,text) from public,anon,authenticated;
grant execute on function public.ops_enqueue_github_test_only(bigint,text,text,text,text,timestamptz,text) to service_role;
