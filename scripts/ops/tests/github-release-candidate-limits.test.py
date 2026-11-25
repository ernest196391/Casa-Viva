import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_candidate_limits as c

class Tests(unittest.TestCase):
    def setUp(self):
        self.values={'memory.max':'268435456','pids.max':'32','cpu.max':'50000 100000'}
    def test_approved_budget(self):
        self.assertTrue(c.assess(self.values))
    def test_unlimited_rejected(self):
        for key in self.values:
            self.assertFalse(c.assess(dict(self.values,**{key:'max'})))
    def test_over_budget_rejected(self):
        for key,value in [('memory.max','268435457'),('pids.max','33'),('cpu.max','50001 100000')]:
            self.assertFalse(c.assess(dict(self.values,**{key:value})))
    def test_missing_or_wrong_unit_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            self.assertFalse(c.read_candidate_limits('cv-dev-worker.service',root=root))
            self.assertFalse(c.read_candidate_limits('cv-release-candidate.service',root=root))
            p=root/'system.slice'/'cv-release-candidate.service'
            p.mkdir(parents=True)
            for key,value in self.values.items():
                (p/key).write_text(value)
            self.assertTrue(c.read_candidate_limits('cv-release-candidate.service',root=root))
            (p/'memory.max').unlink()
            self.assertFalse(c.read_candidate_limits('cv-release-candidate.service',root=root))
if __name__=='__main__': unittest.main()
