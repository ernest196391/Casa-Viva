-- Casa Viva Control OS — Phase H: intake risk gate + planner claim.
-- Service-role only RPCs. Apply after code/CI review.

create or replace function public.ops_classify_control_text(p_text text)
returns jsonb
language plpgsql
immutable
set search_path = public
as $$
declare
  t text := coalesce(p_text,'');
  v_risk text;
  v_warning text;
  v_areas jsonb := '[]'::jsonb;
  v_infra boolean;
  v_destructive boolean;
  v_money boolean;
  v_operational boolean;
  v_presentation boolean;
begin
  v_infra := t ~* '\m(dns|dominio|dominios|ssh|servidor|vps|root|credencial|credenciales|contraseña|contrasena|password|api[ _-]*key|token|secreto|secret|base de datos|schema|supabase|github|firewall|proxy|vpn|permisos? de administrador)\M';
  v_destructive := t ~* '\m(borrar|eliminar|destruir|vaciar|purgar|resetear|restablecer todo|drop|truncate)\M';
  v_money := t ~* '\m(precio|precios|tarifa|tarifas|comisión|comision|comisiones|pago|pagos|pagar|cobro|cobros|dinero|caja|descuento|descuentos|costo|coste|margen|reembolso|devolución|devolucion|payout|usd|cup|mlc|zelle|usdt)\M';
  v_operational := t ~* '\m(regla|política|politica|flujo|pedido|pedidos|mensajer|gestor|gestora|dependient|cliente|clientes|stock|existencia|catálogo|catalogo|producto|productos|sincroniz|whatsapp|notific|asignación|asignacion|reparto|entrega|recogida|grupo|automatiz|supervisor|comando|bot)\w*';
  v_presentation := t ~* '\m(panel|pantalla|botón|boton|texto|etiqueta|título|titulo|descripción|descripcion|diseño|diseno|visual|mostrar|ocultar|ordenar|filtro|buscador|icono|menú|menu|color|tamaño|tamano)\M';

  if v_infra or v_destructive then
    v_risk := 'critical';
    v_warning := 'Puede afectar infraestructura, permisos o datos. Se puede analizar y probar, pero necesita aprobación explícita antes de programar.';
    v_areas := jsonb_build_array('infraestructura/seguridad');
    if v_destructive then v_areas := v_areas || jsonb_build_array('datos o acciones difíciles de revertir'); end if;
  elsif v_money then
    v_risk := 'sensitive';
    v_warning := 'Puede afectar dinero, precios, tarifas o comisiones. Necesita aprobación explícita antes de programar.';
    v_areas := jsonb_build_array('dinero/precios/comisiones');
  elsif v_presentation and not v_operational then
    v_risk := 'safe';
    v_warning := 'No se detecta impacto en dinero, pedidos, stock ni reglas operativas. Se puede preparar y probar de forma reversible.';
    v_areas := jsonb_build_array('presentación/interfaz');
  else
    v_risk := 'guarded';
    v_warning := 'Puede cambiar cómo trabaja Casa Viva. Necesita aprobación explícita antes de programar.';
    v_areas := jsonb_build_array('reglas o comportamiento del sistema');
  end if;

  return jsonb_build_object(
    'risk', v_risk,
    'approval_required', (v_risk <> 'safe'),
    'warning', v_warning,
    'areas', v_areas
  );
end
$$;

revoke all on function public.ops_classify_control_text(text) from public, anon, authenticated;
grant execute on function public.ops_classify_control_text(text) to service_role;

create or replace function public.ops_ingest_control_request(
  p_request_id text,
  p_text text,
  p_at timestamp with time zone default now()
)
returns table(task_public_id bigint, created boolean)
language plpgsql
security definer
set search_path = public
as $$
declare
  v_thread uuid;
  v_task uuid;
  v_public bigint;
  v_class jsonb;
  v_risk text;
  v_approval boolean;
  v_warning text;
  v_priority text;
  v_status text;
begin
  if p_request_id is null or p_request_id !~ '^[a-f0-9]{32}$' then
    raise exception 'request_id inválido';
  end if;
  if p_text is null or length(btrim(p_text)) < 8 or length(p_text) > 6000 then
    raise exception 'texto inválido';
  end if;

  perform pg_advisory_xact_lock(hashtext('control-private:'||p_request_id));

  select id,public_id into v_task,v_public
  from public.ops_tasks
  where source='control-private' and dedupe_key='portal-'||p_request_id
  limit 1;

  if v_task is not null then
    return query select v_public,false;
    return;
  end if;

  v_class := public.ops_classify_control_text(p_text);
  v_risk := v_class->>'risk';
  v_approval := coalesce((v_class->>'approval_required')::boolean,true);
  v_warning := v_class->>'warning';
  v_priority := case when v_risk='critical' then 'p0'
                     when v_risk='sensitive' then 'p1'
                     else 'p2' end;
  v_status := case when v_approval then 'waiting_approval' else 'triaged' end;

  insert into public.ops_threads
    (business_id,source,source_ref,subject,summary,status,priority,owner_role,owner_ref,last_activity_at,metadata)
  values
    ('casa-viva','control-private',p_request_id,
     left(regexp_replace(btrim(p_text),'\s+',' ','g'),140),
     left(btrim(p_text),1200),
     'active',v_priority,'owner','ernesto',coalesce(p_at,now()),
     jsonb_build_object('portal_request_id',p_request_id,'risk',v_risk))
  returning id into v_thread;

  insert into public.ops_tasks
    (thread_id,business_id,source,source_ref,task_type,title,payload,dedupe_key,
     priority,risk,status,executor,requested_by_role,requested_by_ref,requested_by_name,approval_required)
  values
    (v_thread,'casa-viva','control-private',p_request_id,'owner_request',
     left(regexp_replace(btrim(p_text),'\s+',' ','g'),180),
     jsonb_build_object('text',p_text,'portal_request_id',p_request_id,'requested_at',coalesce(p_at,now())),
     'portal-'||p_request_id,v_priority,v_risk,v_status,'ai',
     'owner','ernesto','Ernesto',v_approval)
  returning id,public_id into v_task,v_public;

  insert into public.ops_thread_items
    (thread_id,task_id,source,source_id,item_type,body,payload,source_created_at)
  values
    (v_thread,v_task,'control-private',p_request_id,'message',p_text,
     jsonb_build_object('actor_wp_id',1,'kind','task','risk',v_risk),coalesce(p_at,now()));

  if v_approval then
    insert into public.ops_approvals
      (task_id,requested_for_role,requested_for_ref,warning,impact,proposed_change,status)
    values
      (v_task,'owners',null,v_warning,
       jsonb_build_object('stage','initial','risk',v_risk,'areas',v_class->'areas','source','panel'),
       jsonb_build_object('stage','plan_and_test','request',left(p_text,1200)),
       'pending');
  end if;

  insert into public.ops_events(task_id,thread_id,event_type,actor_type,actor_ref,detail)
  values
    (v_task,v_thread,
     case when v_approval then 'portal_request_waiting_approval' else 'portal_request_triaged' end,
     'owner','ernesto',
     jsonb_build_object('request_id',p_request_id,'risk',v_risk,'approval_required',v_approval));

  return query select v_public,true;
end
$$;

revoke all on function public.ops_ingest_control_request(text,text,timestamp with time zone) from public, anon, authenticated;
grant execute on function public.ops_ingest_control_request(text,text,timestamp with time zone) to service_role;

create or replace function public.ops_claim_planner_task(p_worker text)
returns setof public.ops_tasks
language plpgsql
security definer
set search_path = public
as $$
declare
  v_id uuid;
begin
  if p_worker is null or length(btrim(p_worker)) < 3 or length(p_worker) > 100 then
    raise exception 'worker inválido';
  end if;

  select t.id into v_id
  from public.ops_tasks t
  where t.task_type in ('owner_request','owner_change')
    and t.status in ('triaged','failed')
    and t.approval_required = false
    and coalesce(t.payload->'dev'->>'ready','false') <> 'true'
    and t.attempts < 3
    and (t.next_attempt_at is null or t.next_attempt_at <= now())
    and (t.claimed_by is null or t.claim_expires_at is null or t.claim_expires_at < now())
    and coalesce(t.executor,'ai') in ('ai','dev','ops')
  order by
    case t.priority when 'p0' then 0 when 'p1' then 1 when 'p2' then 2 else 3 end,
    t.created_at
  for update skip locked
  limit 1;

  if v_id is null then
    return;
  end if;

  update public.ops_tasks
  set status='running',
      executor='ai',
      claimed_by=left(p_worker,100),
      claimed_at=now(),
      claim_expires_at=now()+interval '15 minutes',
      started_at=coalesce(started_at,now()),
      attempts=attempts+1,
      error=null,
      updated_at=now()
  where id=v_id;

  return query select * from public.ops_tasks where id=v_id;
end
$$;

revoke all on function public.ops_claim_planner_task(text) from public, anon, authenticated;
grant execute on function public.ops_claim_planner_task(text) to service_role;
