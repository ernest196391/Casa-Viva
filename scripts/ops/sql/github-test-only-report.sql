-- Candidate only; apply after lifecycle in a disposable database.
create function public.ops_ack_github_test_only(p_task_id uuid,p_comment_id bigint)
returns boolean language plpgsql security invoker set search_path=pg_catalog,public as $$
declare t public.ops_tasks%rowtype;
begin
 if p_comment_id is null or p_comment_id<=0 then raise exception 'invalid report id'; end if;
 select * into strict t from public.ops_tasks where id=p_task_id for update;
 if t.source is distinct from 'github-test-only' or t.status not in ('done','failed')
 or coalesce(t.result->>'outcome','') not in ('passed','failed','timeout','blocked','expired')
 then raise exception 'not a terminal test'; end if;
 if t.result ? 'github_report_id' then
  if t.result->>'github_report_id'=p_comment_id::text then return true; end if;
  raise exception 'conflicting report acknowledgement';
 end if;
 update public.ops_tasks set result=result||jsonb_build_object('github_report_id',p_comment_id),updated_at=clock_timestamp() where id=t.id;
 insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
 values(t.id,t.thread_id,'github_test_reported','worker','github-test-only',jsonb_build_object('comment_id',p_comment_id));
 return true;
end $$;
revoke all on function public.ops_ack_github_test_only(uuid,bigint) from public,anon,authenticated;
grant execute on function public.ops_ack_github_test_only(uuid,bigint) to service_role;
