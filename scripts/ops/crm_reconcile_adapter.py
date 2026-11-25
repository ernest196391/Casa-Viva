"""Fixed Casa Viva snapshot adapter. No writes, raw output or remote command entrypoint."""
import json
import os
import subprocess
import urllib.request
import urllib.parse
from pathlib import Path
from crm_reconcile_dryrun import reconcile, tenant_id, ROW_KEYS, MAX_ROWS

HOST = 'https://viwwlriwlwodrfukbgbj.supabase.co'
CONFIG = Path('/root/supervisor/.dispatch-preflight.env')
PAGE = 200
COLUMNS = ','.join(sorted(ROW_KEYS))

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):
        return None

def read_config():
    import stat
    fd=os.open(CONFIG,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as f:
        st=os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077:
            raise ValueError('config_permissions')
        raw=f.read(65537)
    if len(raw)>65536:
        raise ValueError('config_size')
    found={}
    for line in raw.decode().splitlines():
        k,sep,v=line.partition('=')
        if sep and k in ('SUPABASE_URL','SUPABASE_SERVICE_KEY','BUSINESS_SLUG'):
            if k in found:
                raise ValueError('duplicate_config')
            found[k]=v.strip().strip('"').strip("'")
    if found.get('SUPABASE_URL','').rstrip('/')!=HOST or found.get('BUSINESS_SLUG')!='casa-viva':
        raise ValueError('wrong_project')
    key=found.get('SUPABASE_SERVICE_KEY','')
    if not key or len(key)>4096 or any(c.isspace() for c in key):
        raise ValueError('missing_key')
    return key

def remote_get(key,table,params):
    if table not in ('crm_businesses','crm_wa_messages'):
        raise ValueError('invalid_table')
    req=urllib.request.Request(HOST+'/rest/v1/'+table+'?'+urllib.parse.urlencode(params),
        method='GET',headers={'apikey':key,'Authorization':'Bearer '+key,'Accept':'application/json','Prefer':'count=exact'})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect()).open(req,timeout=15) as r:
        raw=r.read(8*1024*1024+1)
        if len(raw)>8*1024*1024:
            raise ValueError('page_size')
        data=json.loads(raw)
        count=r.headers.get('Content-Range','').rsplit('/',1)[-1]
        if not isinstance(data,list) or not count.isdigit():
            raise ValueError('incomplete_page')
        return data,int(count)

def remote_snapshot(key,get=remote_get):
    tenants,total=get(key,'crm_businesses',{'select':'id','slug':'eq.casa-viva','limit':'2'})
    if total!=1 or len(tenants)!=1 or set(tenants[0])!={'id'}:
        raise ValueError('tenant_ambiguous')
    tenant=tenant_id(tenants[0]['id'])
    rows=[]
    expected=None
    last=None
    while True:
        params={'select':COLUMNS,'business_id':'eq.'+tenant,'order':'id.asc','limit':str(PAGE),'offset':str(len(rows))}
        page,count=get(key,'crm_wa_messages',params)
        if expected is None:
            expected=count
        if count!=expected or count>MAX_ROWS or len(page)>PAGE or len(rows)+len(page)>count:
            raise ValueError('snapshot_changed')
        if not page and len(rows)!=count:
            raise ValueError('truncated_snapshot')
        rows.extend(page)
        if len(rows)==count:
            break
    return tenant,rows

# One read-only transaction, fixed columns; CSV/JSON never goes to stdout of the caller.
LOCAL_SQL="""BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SET LOCAL statement_timeout='30s';
SET LOCAL TIME ZONE 'UTC';
SELECT json_build_object('tenants',(SELECT coalesce(json_agg(id),'[]'::json) FROM public.crm_businesses WHERE slug='casa-viva'),
'count',(SELECT count(*) FROM public.crm_wa_messages w JOIN public.crm_businesses b ON b.id=w.business_id WHERE b.slug='casa-viva'),
'rows',(SELECT coalesce(json_agg(r),'[]'::json) FROM (SELECT w.business_id,w.wa_id,w.chat_jid,w.is_group,w.sender_jid,w.sender_phone,w.sender_name,w.from_me,w.body,w.sent_at FROM public.crm_wa_messages w JOIN public.crm_businesses b ON b.id=w.business_id WHERE b.slug='casa-viva' ORDER BY w.id LIMIT 200001) r));
COMMIT;"""

def local_snapshot():
    p=subprocess.run(['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db','psql','-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-d','postgres','-c',LOCAL_SQL],
        env={'PATH':'/usr/bin:/bin','HOME':'/root'},stdin=subprocess.DEVNULL,capture_output=True,timeout=45)
    if p.returncode or len(p.stdout)>64*1024*1024:
        raise ValueError('local_unavailable')
    d=json.loads(p.stdout)
    if set(d)!={'tenants','count','rows'} or len(d['tenants'])!=1 or type(d['count']) is not int or not isinstance(d['rows'],list) or d['count']!=len(d['rows']) or d['count']>MAX_ROWS:
        raise ValueError('local_incomplete')
    return tenant_id(d['tenants'][0]),d['rows']

def simulate(local=local_snapshot,remote=remote_snapshot,config=read_config):
    # REST pagination is not transactional: require two identical remote observations.
    # Never label a changing or incomplete observation as certified.
    try:
        key=config()
        a,local_rows=local()
        b,remote_rows=remote(key)
        c,second=remote(key)
        if b!=c or remote_rows!=second:
            return {'ok':False,'reason':'remote_changed','eligible':0}
        if a!=b:
            return {'ok':False,'reason':'tenant_mapping_unverified','eligible':0}
        return reconcile(local_rows,remote_rows,a,b,identity_verified=True,snapshot_complete=True)
    except Exception:
        return {'ok':False,'reason':'snapshot_unavailable','eligible':0}

if __name__=='__main__':
    print(json.dumps(simulate(),separators=(',',':')))
