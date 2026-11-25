import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_host_observer as o

class Tests(unittest.TestCase):
    def test_unique_and_stable(self):
        unit="cv-release-pilot-123-456.service"
        group="/system.slice/"+unit
        state=dict(ControlGroup=group,InvocationID="a"*32,MainPID="10",ActiveState="active")
        m=dict(host_pid=11,namespace_pid=2,start_ticks=123,uid=1000)
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            folder=root/group.lstrip("/")
            folder.mkdir(parents=True)
            (folder/"cgroup.procs").write_text("10\n11\n")
            result=o.sample_unit(unit,1000,snapshot=lambda _:dict(state),cgroot=root,
                sampler=lambda pid,*_:m if pid==11 else None)
            self.assertEqual(result["host_pid"],11)
            self.assertEqual(result["invocation"],"a"*32)
            self.assertFalse(result["production_authorized"])
            self.assertIsNone(o.sample_unit(unit,1000,snapshot=lambda _:dict(state),cgroot=root,
                sampler=lambda pid,*_:dict(m,host_pid=pid)))
            states=iter([state,dict(state,InvocationID="b"*32)])
            self.assertIsNone(o.sample_unit(unit,1000,snapshot=lambda _:next(states),cgroot=root,
                sampler=lambda pid,*_:m if pid==11 else None))
    def test_reject_production_unit(self):
        self.assertIsNone(o.sample_unit("cv-ops-bridge.service",1000))
    def test_kernel_metadata(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            p=root/"11"
            (p/"attr").mkdir(parents=True)
            (p/"stat").write_text("11 (python) "+" ".join(["S"]+["0"]*18+["123"]))
            status="Uid: 1000 1000 1000 1000\nNoNewPrivs: 1\nNSpid: 11 2\n"+"".join(k+": 0000\n" for k in ("CapEff","CapPrm","CapInh","CapAmb","CapBnd"))
            (p/"status").write_text(status)
            (p/"attr/current").write_text(o.LABEL)
            (p/"cgroup").write_text("0::/test\n")
            self.assertEqual(o.sample_process(11,1000,"/test",proc=root)["namespace_pid"],2)
            (p/"status").write_text(status.replace("CapEff: 0000","CapEff: 0001"))
            self.assertIsNone(o.sample_process(11,1000,"/test",proc=root))
            self.assertIsNone(o.sample_process(11,0,"/test",proc=root))

if __name__=="__main__":
    unittest.main()
