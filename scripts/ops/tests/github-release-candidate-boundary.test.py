import sys
import os
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_candidate_boundary as b

class Tests(unittest.TestCase):
    def setUp(self):
        self.facts={'pm2':{'vivabot':'stopped','vivabot-recovery-receiver':'online','nexofirma':'online'},'bridge':'active'}
        self.box=Mock()
        self.box.run_in_box.return_value=(0,'{"status":"degraded"}')
    def evaluate(self,**kw):
        return b._evaluate('/var/lib/control-os-dev/work/run-pilot',self.facts,uid=kw.get('uid',1000),gid=kw.get('gid',1001),sandbox=self.box)
    def test_fixed_command_and_advisory(self):
        self.assertEqual(self.evaluate(),{'ok':True,'result':{'status':'degraded','advisory_only':True}})
        args,kw=self.box.run_in_box.call_args
        self.assertEqual(args[0][:4],['/usr/bin/python3','-I','-B','candidate.py'])
        self.assertEqual(args[2],10)
        self.assertEqual(kw,{'uid':1000,'gid':1001})
    def test_root_refused(self):
        self.assertFalse(self.evaluate(uid=0)['ok'])
        self.box.run_in_box.assert_not_called()
    def test_arbitrary_operation_refused(self):
        with self.assertRaises(ValueError):
            b.request_bytes('restart',self.facts)
    def test_secret_field_refused(self):
        self.facts['token']='synthetic'
        self.assertFalse(self.evaluate()['ok'])
        self.box.run_in_box.assert_not_called()
    def test_arbitrary_output_refused(self):
        self.box.run_in_box.return_value=(0,'{"status":"healthy","command":"restart"}')
        self.assertFalse(self.evaluate()['ok'])
    def test_failure_cannot_approve(self):
        self.box.run_in_box.return_value=(124,'{"status":"healthy"}')
        self.assertFalse(self.evaluate()['ok'])
    def test_oversized_output_refused(self):
        self.box.run_in_box.return_value=(0,'x'*2049)
        self.assertFalse(self.evaluate()['ok'])
    def test_invalid_status_refused(self):
        self.facts['pm2']['vivabot']='arbitrary'
        self.assertFalse(self.evaluate()['ok'])
    def test_stage_refuses_wrong_digest(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            source=root/'source.py'
            source.write_bytes(b'x=1\n')
            with self.assertRaises(ValueError):
                with b.stage_candidate(source,'0'*64,uid=1000,gid=1001,work_root=root):
                    self.fail('unexpected stage')
    def test_stage_exact_bytes_readonly_and_cleanup(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            source=root/'source.py'
            data=b'x=1\n'
            source.write_bytes(data)
            with b.stage_candidate(source,hashlib.sha256(data).hexdigest(),uid=1000,gid=1001,work_root=root) as work:
                self.assertEqual((work/'candidate.py').read_bytes(),data)
                self.assertEqual(work.stat().st_mode & 0o777,0o555)
                self.assertEqual((work/'candidate.py').stat().st_mode & 0o777,0o444)
            self.assertFalse(work.exists())
    def test_internal_entry_requires_verified_staging(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            source=root/'source.py'
            data=b'x=1\n'
            source.write_bytes(data)
            uid=1001 if os.geteuid()==1000 else 1000
            self.assertFalse(b._evaluate_staged(source,self.facts,expected_digest='0'*64,uid=uid,gid=1001,sandbox=self.box,work_root=root)['ok'])
            self.box.run_in_box.assert_not_called()
            self.assertTrue(b._evaluate_staged(source,self.facts,expected_digest=hashlib.sha256(data).hexdigest(),uid=uid,gid=1001,sandbox=self.box,work_root=root)['ok'])
    def test_public_entry_stays_closed_even_with_successful_adapter(self):
        self.assertEqual(b.evaluate('/not-used',self.facts,expected_digest='a'*64,uid=1000,gid=1001,sandbox=self.box),
                         {'ok':False,'reason':'candidate_execution_boundary_not_certified'})
        self.box.run_in_box.assert_not_called()
    def test_public_entry_refuses_same_identity(self):
        self.assertFalse(b.evaluate('/not-used',self.facts,expected_digest='0'*64,uid=os.geteuid(),gid=1001,sandbox=self.box)['ok'])
        self.box.run_in_box.assert_not_called()
    def test_confined_command_fixed_and_compiles(self):
        cmd=b.confined_command('a'*64,self.facts)
        self.assertEqual(cmd[:5],['/usr/bin/python3.12','-I','-S','-B','-c'])
        compile(cmd[5],'<trusted-prefix>','exec')
        self.assertEqual(cmd[6],'a'*64)
        self.assertLess(cmd[5].index('stack cv-release-pilot-candidate'),cmd[5].index('open("candidate.py"'))
        self.assertLess(cmd[5].index('CapBnd'),cmd[5].index('open("candidate.py"'))
    def test_confined_command_refuses_bad_digest_and_facts(self):
        with self.assertRaises(ValueError):
            b.confined_command('bad',self.facts)
        with self.assertRaises(ValueError):
            b.confined_command('a'*64,{'token':'synthetic'})

    def transcript(self,status='healthy',**changes):
        measurement=dict(digest=hashlib.sha256(b.SYNTHETIC_SOURCE).hexdigest(),
            uid=1000,pid=2,label='cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)',
            nnp=True,caps_zero=True)
        measurement.update(changes)
        return json.dumps({'pilot_measurement':measurement})+'\n'+json.dumps({'status':status})
    def test_measured_fixture_rejects_bad_evidence(self):
        digest=hashlib.sha256(b.SYNTHETIC_SOURCE).hexdigest()
        result, measurement=b.parse_measured_fixture(self.transcript(),digest,1000)
        self.assertEqual(result['status'],'healthy')
        self.assertEqual(measurement['uid'],1000)
        for changes in (dict(uid=0),dict(pid=True),dict(nnp=False),dict(caps_zero=False),dict(label='unconfined'),dict(digest='0'*64)):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                b.parse_measured_fixture(self.transcript(**changes),digest,1000)
        with self.assertRaises(ValueError):
            b.parse_measured_fixture('{"status":"healthy"}',digest,1000)
        with self.assertRaises(ValueError):
            b.parse_measured_fixture(self.transcript(), '0'*64,1000)
    def test_synthetic_pilot_observer_and_cleanup(self):
        from unittest.mock import patch
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            self.box.run_release_pilot.side_effect=[(0,self.transcript(status)) for status in ('healthy','degraded','unknown')]
            real_uid=os.geteuid()
            # stage owner validation still uses actual uid in non-root CI.
            original=b.stage_candidate
            from contextlib import contextmanager
            @contextmanager
            def stage(*args,**kwargs):
                with patch.object(b.os,'geteuid',return_value=real_uid):
                    with original(*args,**kwargs) as work:
                        yield work
            with patch.object(b.os,'geteuid',return_value=0), patch.object(b,'stage_candidate',stage):
                result=b.run_synthetic_pilot(sandbox=self.box,uid=1000,gid=1001,work_root=root)
            self.assertTrue(result['ok'])
            self.assertFalse(result['production_authorized'])
            self.assertEqual(self.box.run_release_pilot.call_count,3)
            self.assertEqual(list(root.iterdir()),[])
    def test_host_observed_command_requires_ack(self):
        cmd=b.confined_command(hashlib.sha256(b.SYNTHETIC_SOURCE).hexdigest(),self.facts,host_observed=True)
        self.assertEqual(cmd[-1],'--host-observed')
        self.assertLess(cmd[5].index('sys.stdin.buffer.read(1)'),cmd[5].index('exec(compile(body'))
    def test_host_observed_pilot_rejects_missing_and_mismatched_identity(self):
        from unittest.mock import patch
        from contextlib import contextmanager
        real_uid=os.geteuid()
        original=b.stage_candidate
        @contextmanager
        def stage(*args,**kwargs):
            with patch.object(b.os,'geteuid',return_value=real_uid):
                with original(*args,**kwargs) as work:
                    yield work
        for mode in ('valid','wrong_pid','missing','changed_invocation'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
                statuses=iter(('healthy','degraded','unknown'))
                count=[0]
                def launch(*args,**kwargs):
                    transcript=self.transcript(next(statuses))
                    accepted=kwargs['preflight'](transcript.splitlines()[0].encode())
                    return (0,transcript) if accepted else (125,'rejected')
                def sample(unit,uid):
                    count[0]+=1
                    if mode=='missing':
                        return None
                    return dict(host_pid=10+count[0],namespace_pid=3 if mode=='wrong_pid' else 2,
                                start_ticks=123+count[0],uid=uid,invocation=('b' if mode=='changed_invocation' and count[0]>1 else 'a')*32,
                                scope='synthetic_only',production_authorized=False)
                self.box.run_release_pilot.side_effect=launch
                with patch.object(b.os,'geteuid',return_value=0), patch.object(b,'stage_candidate',stage):
                    result=b.run_synthetic_pilot(sandbox=self.box,uid=1000,gid=1001,
                        work_root=Path(tmp),unit='cv-release-pilot-1-2.service',host_sampler=sample)
                self.assertEqual(result['ok'],mode=='valid')
                self.assertFalse(result['production_authorized'])
                self.assertEqual(list(Path(tmp).iterdir()),[])
                if mode=='valid':
                    self.assertEqual(len(result['host_measurements']),3)
                    self.assertEqual(result['host_measurements'][0]['host_pid'],11)

    def test_projected_observation_uses_host_handshake(self):
        from unittest.mock import patch
        from contextlib import contextmanager
        real_uid=os.geteuid()
        original=b.stage_candidate
        @contextmanager
        def stage(*args,**kwargs):
            with patch.object(b.os,'geteuid',return_value=real_uid):
                with original(*args,**kwargs) as work:
                    yield work
        summary={'pm2':{n:{'status':'errored' if n=='vivabot' else 'online'} for n in b.PROCESSES},
                 'bridge':'active'}
        for status in ('degraded','healthy'):
            with self.subTest(status=status), tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
                def launch(work,facts,digest,**kwargs):
                    self.assertEqual(facts['pm2']['vivabot'],'stopped')
                    transcript=self.transcript(status)
                    self.assertTrue(kwargs['preflight'](transcript.splitlines()[0].encode()))
                    return 0,transcript
                self.box.run_release_pilot.side_effect=launch
                def sample(unit,uid):
                    return dict(host_pid=11,namespace_pid=2,start_ticks=123,uid=uid,
                                invocation='a'*32,scope='synthetic_only',production_authorized=False)
                with patch.object(b.os,'geteuid',return_value=0),patch.object(b,'stage_candidate',stage):
                    result=b.run_synthetic_pilot(sandbox=self.box,uid=1000,gid=1001,
                        work_root=Path(tmp),unit='cv-release-pilot-1-2.service',host_sampler=sample,
                        observation=summary)
                self.assertEqual(result['ok'],status=='degraded')
                self.assertEqual(result['scope'],'observation_only')
                self.assertEqual(result['status'],'degraded' if status=='degraded' else 'unknown')
                self.assertFalse(result['production_authorized'])
                self.assertEqual(list(Path(tmp).iterdir()),[])

    def test_synthetic_pilot_refuses_root_child(self):
        self.assertFalse(b.run_synthetic_pilot(sandbox=self.box,uid=0,gid=1001)['ok'])
        self.box.run_release_pilot.assert_not_called()

if __name__=='__main__': unittest.main()
