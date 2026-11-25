import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
P = Path(__file__).resolve().parents[1] / 'github_contingency.py'
spec = importlib.util.spec_from_file_location('contingency', P)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
NOW = m.timestamp('2026-11-25T16:00:00Z')
ENV = {'CV_GITHUB_CONTINGENCY_ENABLED':'1','CV_GITHUB_CONTINGENCY_ISSUE':'3','CV_GITHUB_CONTINGENCY_TOKEN':'synthetic'}
D = {'action':'hermes_probe_card','nonce':'synthetic_nonce_123','expires_at':'2026-11-25T16:05:00Z'}
CARD = {'id':'t_e00830ea','title':'CV bridge connectivity probe','body':'Inert connectivity test. Keep blocked. Do not dispatch.','assignee':'default','status':'blocked','workspace_kind':'scratch','max_runtime_seconds':90,'max_retries':1,'completion_contract':'local-only','started_at':None,'session_id':None,'result':None}
def row(data=None, **kw):
    r = dict(id=1,user={'login':m.OWNER,'type':'User'},author_association='OWNER',created_at='2026-11-25T15:59:00Z',updated_at='2026-11-25T15:59:00Z',body=m.PREFIX+json.dumps(D if data is None else data))
    r.update(kw)
    return r
class Tests(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(m.command(row(),NOW),D)
    def test_wrong_author(self):
        self.assertIsNone(m.command(row(user={'login':'other','type':'User'}),NOW))
        self.assertIsNone(m.command(row(author_association='MEMBER'),NOW))
    def test_edited(self):
        self.assertIsNone(m.command(row(updated_at='2026-11-25T16:00:00Z'),NOW))
    def test_bad_fields(self):
        for data in [dict(D,body='private'),dict(D,nonce='bad;command'),dict(D,expires_at='2026-11-25T16:15:00Z'),dict(D,expires_at='2026-11-25T16:00:00Z')]:
            self.assertIsNone(m.command(row(data),NOW))
    def test_fixed_cli(self):
        with patch.object(m.subprocess,'run',return_value=m.subprocess.CompletedProcess([],0,json.dumps(dict(CARD,secret='not exported')).encode(),b'')) as run:
            self.assertEqual(m.hermes_probe_card(D,NOW),{'ok':True,'card_id':'t_e00830ea','status':'blocked'})
            argv=run.call_args.args[0]
            self.assertEqual(argv[:6],['/usr/bin/sudo','-n','-u','hermes','-H','--'])
            self.assertNotIn('dispatch',argv)
            self.assertEqual(argv[argv.index('--initial-status')+1],'blocked')
            self.assertEqual(set(run.call_args.kwargs['env']),{'PATH','HOME'})
    def test_bad_output(self):
        for card in [dict(CARD,status='running'),dict(CARD,id='private'),dict(CARD,session_id='private'),[]]:
            with patch.object(m.subprocess,'run',return_value=m.subprocess.CompletedProcess([],0,json.dumps(card).encode(),b'private')):
                self.assertEqual(m.hermes_probe_card(D,NOW),{'ok':False,'reason':'hermes_probe_unavailable'})
    def test_timeout(self):
        with patch.object(m.subprocess,'run',side_effect=m.subprocess.TimeoutExpired('synthetic',20)):
            self.assertFalse(m.hermes_probe_card(D,NOW)['ok'])
    def test_expiry_at_dispatch(self):
        with patch.object(m.subprocess,'run') as run:
            self.assertFalse(m.hermes_probe_card(D,NOW+301)['ok'])
            run.assert_not_called()
    def poll_case(self, enabled, rows, private=True, health_only=True):
        posts=[]; clock=[NOW]
        def api(token,method,path,body=None):
            if method=='POST':
                posts.append(json.loads(body['body'].split(' ',1)[1]));return {}
            if '/comments?' in path:return rows
            if path.endswith('/issues/3'):return {'title':'CV contingency — diagnostic mailbox','state':'open'}
            return {'private':private,'full_name':m.REPO}
        env=dict(ENV)
        if enabled:env['CV_GITHUB_HERMES_PROBE_ENABLED']='1'
        with tempfile.TemporaryDirectory() as d, patch.object(m,'hermes_probe_card',return_value={'ok':True,'card_id':'t_e00830ea','status':'blocked'}) as probe:
            c=m.Contingency(env,lambda:({'returncode':1,'output':''},'active'),Path(d),api,lambda:clock[0])
            first=c.poll(health_only=health_only);clock[0]+=61
            second=c.poll(health_only=health_only)
            return first,second,posts,probe.call_count,len(list(Path(d).glob('nonce-*')))
    def test_disabled_both_modes(self):
        for mode in [True,False]:
            self.assertEqual(self.poll_case(False,[row()],health_only=mode),('idle','idle',[],0,0))
    def test_enabled_and_replay(self):
        first,second,posts,calls,reserved=self.poll_case(True,[row()])
        self.assertEqual((first,second,calls,reserved),('reported','idle',1,1))
        self.assertEqual(posts,[{'action':'hermes_probe_card','request_id':1,'result':{'ok':True,'card_id':'t_e00830ea','status':'blocked'}}])
    def test_public_repo(self):
        self.assertEqual(self.poll_case(True,[row()],private=False),('blocked','blocked',[],0,0))
    def test_unrelated_action_still_denied_online(self):
        self.assertEqual(self.poll_case(True,[row(dict(D,action='db_snapshot'))]),('idle','idle',[],0,0))
if __name__=='__main__':unittest.main()
