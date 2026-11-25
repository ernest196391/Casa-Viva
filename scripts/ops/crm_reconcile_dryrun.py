"""Pure dry-run reconciliation. No I/O, credentials, database writes or message output."""
import uuid
import datetime as dt
import hashlib
import json

MAX_ROWS = 200000
FIELDS = ('chat_jid','is_group','sender_jid','sender_phone','sender_name','from_me','body','sent_at')
ROW_KEYS = set(FIELDS) | {'business_id','wa_id'}

def tenant_id(value):
    if not isinstance(value,str) or str(uuid.UUID(value)) != value:
        raise ValueError('tenant_identity')
    return value

def fingerprint(row):
    values = []
    for key in FIELDS:
        value = row[key]
        if key in ('is_group','from_me'):
            if type(value) is not bool:
                raise ValueError('invalid_direction')
        elif key == 'sent_at':
            if not isinstance(value,str):
                raise ValueError('invalid_timestamp')
            t = dt.datetime.fromisoformat(value.replace('Z','+00:00'))
            if t.tzinfo is None or t.utcoffset() is None:
                raise ValueError('invalid_timestamp')
            value = t.astimezone(dt.timezone.utc).isoformat()
        elif value is not None and (not isinstance(value,str) or len(value)>262144):
            raise ValueError('invalid_payload')
        if key == 'chat_jid' and (not isinstance(value,str) or not value.strip()):
            raise ValueError('invalid_chat')
        values.append(value)
    return hashlib.sha256(json.dumps(values,ensure_ascii=True,separators=(',',':')).encode()).digest()

def index_rows(rows, tenant):
    index = {}
    duplicates = invalid = foreign = 0
    for i,row in enumerate(rows):
        if i >= MAX_ROWS:
            raise ValueError('snapshot_limit')
        if not isinstance(row,dict) or set(row)!=ROW_KEYS:
            invalid += 1
            continue
        if row['business_id'] != tenant:
            foreign += 1
            continue
        key = row['wa_id']
        if not isinstance(key,str) or not key or key!=key.strip() or len(key)>512:
            invalid += 1
            continue
        try:
            fp = fingerprint(row)
        except (ValueError,TypeError,OverflowError):
            invalid += 1
            continue
        if key in index:
            duplicates += 1
        else:
            index[key] = fp
    return index,duplicates,invalid,foreign

def reconcile(local_rows,remote_rows,local_tenant,remote_tenant,*,identity_verified=False,snapshot_complete=False):
    """Only aggregate evidence; eligible never means approved for insertion."""
    try:
        local_tenant=tenant_id(local_tenant)
        remote_tenant=tenant_id(remote_tenant)
        if identity_verified is not True or snapshot_complete is not True:
            return {'ok':False,'reason':'unverified_snapshot','eligible':0}
        a,ad,ai,af=index_rows(local_rows,local_tenant)
        b,bd,bi,bf=index_rows(remote_rows,remote_tenant)
        shared=a.keys() & b.keys()
        conflicts=sum(a[k]!=b[k] for k in shared)
        missing=len(a.keys()-b.keys())
        remote_only=len(b.keys()-a.keys())
        blocked=bool(ad or ai or af or bd or bi or bf or conflicts)
        return {'ok':not blocked,'scope':'simulation_only','local_unique':len(a),
                'remote_unique':len(b),'matching':len(shared)-conflicts,
                'conflicts':conflicts,'local_only':missing,'remote_only':remote_only,
                'duplicates':ad+bd,'invalid':ai+bi,'foreign':af+bf,
                'eligible':0 if blocked else missing}
    except (ValueError,TypeError,OverflowError,AttributeError):
        return {'ok':False,'reason':'invalid_snapshot','eligible':0}
