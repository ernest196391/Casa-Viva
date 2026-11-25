import hashlib
import itertools
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_contingency as c
import github_release_health_projection as p
import github_release_candidate_boundary as b

class Tests(unittest.TestCase):
    def test_all_original_state_combinations(self):
        names=sorted(c.PROCESSES)
        states=sorted(c.STATES)
        code=compile(b.SYNTHETIC_SOURCE,'<fixed-fixture>','exec')
        import contextlib,io
        count=0
        for values in itertools.product(states,repeat=4):
            rows=[dict(name=n,status=v,secret='not-for-candidate') for n,v in zip(names,values)]
            summary=c.health_summary({'returncode':0,'output':json.dumps(rows)},values[3])
            self.assertEqual(summary,dict(pm2={n:dict(status=v) for n,v in zip(names,values)},bridge=values[3]))
            facts=p.project_summary(summary)
            payload=b.request_bytes('health_summary',facts)
            self.assertNotIn(b'not-for-candidate',payload)
            original=sys.argv
            try:
                sys.argv=['candidate.py',payload.decode()]
                out=io.StringIO()
                with contextlib.redirect_stdout(out):
                    exec(code,{'__name__':'__main__'})
            finally:
                sys.argv=original
            self.assertEqual(b.parse_response(out.getvalue())['status'],p.advisory_reference(facts))
            count+=1
        self.assertEqual(count,6561)
    def test_invalid_summary_rejected(self):
        for summary in (None,{},dict(pm2={},bridge='active'),
                        dict(pm2={n:dict(status=True) for n in c.PROCESSES},bridge='active'),
                        dict(pm2={n:dict(status='online',secret='x') for n in c.PROCESSES},bridge='active'),
                        dict(pm2={n:dict(status='online') for n in c.PROCESSES},bridge=[])):
            with self.subTest(summary=summary),self.assertRaises(ValueError):
                p.project_summary(summary)
    def test_normalization_errors_and_unknowns(self):
        for raw in ('not json','null','[null]','[]','{}'):
            summary=c.health_summary(dict(returncode=0,output=raw),'active')
            self.assertEqual(p.advisory_reference(p.project_summary(summary)),'unknown')
        summary=c.health_summary(dict(returncode=1,output='[]'),'active')
        self.assertEqual(p.advisory_reference(p.project_summary(summary)),'unknown')
    def test_observation_requires_host(self):
        box=Mock()
        result=b.run_synthetic_pilot(sandbox=box,uid=1000,gid=1001,observation={})
        self.assertFalse(result['ok'])
        box.run_release_pilot.assert_not_called()

if __name__=='__main__':
    unittest.main()
