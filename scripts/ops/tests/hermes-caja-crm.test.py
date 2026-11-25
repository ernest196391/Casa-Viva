import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import urllib.error
from unittest.mock import MagicMock, patch

spec=importlib.util.spec_from_file_location('contingency',Path(__file__).resolve().parents[1]/'github_contingency.py')
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
NOW=1795626000
ACTIONS=('hermes_caja_probe','hermes_crm_writer_audit','hermes_crm_sync_status')
def stamp(t):
    return dt.datetime.fromtimestamp(t,dt.timezone.utc).isoformat().replace('+00:00','Z')
def comment(action,ttl=300,**extra):
    return dict(id=123,user=dict(login=m.OWNER,type='User'),author_association='OWNER',created_at=stamp(NOW),updated_at=stamp(NOW),body=m.PREFIX+json.dumps(dict(action=action,nonce='cv-diagnostic-12345',expires_at=stamp(NOW+ttl),**extra)))
class DiagnosticsTests(unittest.TestCase):
    def test_allowlist(self):
        for action in ACTIONS:
            self.assertEqual(m.command(comment(action),NOW)['action'],action)
    def test_no_parameters(self):
        for action in ACTIONS:
            for field in ('url','token','sql','path','cmd'):
                self.assertIsNone(m.command(comment(action,**{field:'fixture'}),NOW))
    def test_ttl_and_owner(self):
        for action in ACTIONS:
            self.assertIsNone(m.command(comment(action,601),NOW))
            c=comment(action);c['author_association']='MEMBER'
            self.assertIsNone(m.command(c,NOW))
    def test_default_denied(self):
        self.assertEqual(m.Contingency({},lambda:None).diagnostics_enabled,set())
    def test_independent_optin(self):
        self.assertEqual(m.Contingency({'CV_GITHUB_HERMES_CAJA_PROBE_ENABLED':'1'},lambda:None).diagnostics_enabled,{'hermes_caja_probe'})
    @patch.object(m.urllib.request,'build_opener')
    def test_caja_fixed_no_body_read(self,build):
        opener=build.return_value
        opener.open.side_effect=[urllib.error.HTTPError('https://example.invalid',401,'secret',{},None),urllib.error.HTTPError('https://example.invalid',403,'secret',{},None)]
        r=m.hermes_caja_probe()
        self.assertTrue(r['ok'])
        self.assertEqual(r['scope'],'unauthenticated_only')
        self.assertNotIn('secret',str(r))
        for call,(_,url,method) in zip(opener.open.call_args_list,m.CAJA_PROBES):
            req=call.args[0]
            self.assertEqual(req.full_url,url)
            self.assertEqual(req.get_method(),method)
            self.assertNotIn('authorization',str(req.headers).lower())
            self.assertNotIn('token',str(req.headers).lower())
        self.assertEqual(build.call_args.args[0].proxies,{})
        self.assertIsInstance(build.call_args.args[1],m.NoRedirect)
    @patch.object(m.urllib.request,'build_opener')
    def test_public_success_is_alert(self,build):
        response=build.return_value.open.return_value.__enter__.return_value
        response.status=200
        r=m.hermes_caja_probe()
        self.assertFalse(r['ok'])
        self.assertEqual(r['core']['category'],'unexpected_public_success')
        response.read.assert_not_called()
    @patch.object(m.urllib.request,'build_opener')
    def test_quota_classification(self,build):
        build.return_value.open.side_effect=[urllib.error.HTTPError('https://example.invalid',402,'secret',{},None),urllib.error.HTTPError('https://example.invalid',429,'secret',{},None)]
        r=m.hermes_caja_probe()
        self.assertEqual(r['render']['category'],'payment_required')
        self.assertEqual(r['core']['category'],'rate_limited')
        self.assertFalse(r['ok'])
    @patch.object(m.urllib.request,'build_opener')
    def test_transport_redacted(self,build):
        build.return_value.open.side_effect=OSError('secret')
        r=m.hermes_caja_probe()
        self.assertEqual(r['render']['category'],'transport_error')
        self.assertNotIn('secret',str(r))
    @unittest.skipUnless(os.geteuid()==0,'requires root-owned fixtures')
    def test_writer_bounded_redacted(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d);(p/'receiver.mjs').write_text('crm_wa_messages SUPABASE upsert SECRET_FIXTURE')
            (p/'.env').write_text('crm_wa_messages')
            (p/'linked.js').symlink_to(p/'receiver.mjs')
            with patch.object(m,'CRM_RECEIVER',p),patch.object(m,'CRM_ORIGINAL',p):
                r=m.hermes_crm_writer_audit()
                self.assertTrue(r['ok'])
                self.assertEqual(r['receiver']['files'],2)
                self.assertEqual(r['receiver']['skipped'],1)
                self.assertEqual(r['receiver']['marker_counts']['wa_table'],1)
                self.assertEqual(r['receiver']['writer_candidates'],1)
                self.assertTrue(r['receiver']['known_sources']['receiver.mjs']['writer_marker'])
                self.assertNotIn('SECRET_FIXTURE',str(r))
    def test_writer_missing_directory(self):
        with tempfile.TemporaryDirectory() as d,patch.object(m,'CRM_RECEIVER',Path(d)/'missing'):
            r=m.hermes_crm_writer_audit()
            self.assertFalse(r['ok'])
            self.assertEqual(r['receiver']['reason'],'directory_missing')
            self.assertIn('original',r)
    @patch.object(m.subprocess,'run')
    def test_sync_fixed_read_only(self,run):
        run.return_value=subprocess.CompletedProcess([],0,b'{"tenant_matches":1,"messages":10,"since_cut":2,"latest":"2026-11-25T12:00:00Z"}',b'private')
        r=m.hermes_crm_sync_status()
        self.assertEqual(r,dict(ok=True,scope='local_recovery_db',messages=10,since_cut=2,latest='2026-11-25T12:00:00Z'))
        self.assertIn('BEGIN READ ONLY',run.call_args.args[0][-1])
        self.assertNotIn('body',run.call_args.args[0][-1])
    @patch.object(m.subprocess,'run')
    def test_sync_rejects_ambiguous_tenant_and_extra_fields(self,run):
        for d in (dict(tenant_matches=2,messages=10,since_cut=2,latest='2026-11-25T12:00:00Z'),dict(tenant_matches=1,messages=10,since_cut=11,latest='2026-11-25T12:00:00Z'),dict(tenant_matches=1,messages=10,since_cut=2,latest='2026-11-25T12:00:00Z',body='secret'),dict(tenant_matches=1,messages=10,since_cut=0,latest='2026-11-25T12:00:00Z')):
            run.return_value=subprocess.CompletedProcess([],0,json.dumps(d).encode(),b'')
            self.assertEqual(m.hermes_crm_sync_status(),dict(ok=False,reason='local_sync_unavailable'))
if __name__=='__main__':
    unittest.main()
