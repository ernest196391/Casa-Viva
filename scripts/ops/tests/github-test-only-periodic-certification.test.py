import subprocess
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Certification(unittest.TestCase):
    def test_shell_syntax(self):
        subprocess.run(["bash","-n",str(ROOT/"certify-isolated-periodic.sh")],check=True)
    def test_explicit_scope_and_no_boot_enablement(self):
        s=(ROOT/"certify-isolated-periodic.sh").read_text()
        self.assertIn('"certification_ids":[int(a),int(b)]',s)
        self.assertIn('"certification_ids":[int(sys.argv[3])]',s)
        self.assertNotIn("systemctl enable",s)
        self.assertLess(s.index("rx.remote_deadline"),s.index('rm -- "$STATE/STOP"'))
    def test_rollback_and_observation(self):
        s=(ROOT/"certify-isolated-periodic.sh").read_text()
        self.assertLess(s.index("trap rollback EXIT"),s.index('systemctl start "$TIMER"'))
        self.assertIn('if state=="active":',s)
        self.assertIn('c:"interrupted"',s)
        self.assertIn('PERIODIC_ROLLBACK_EXIT',s)
        self.assertIn('cv-ops-bridge.service cv-dev-worker.service',s)
    def test_credential_path_consistent(self):
        expected="/var/lib/cv-test-only-periodic/mailbox.token"
        for name in ("install-isolated-periodic.sh","certify-isolated-periodic.sh","sandbox/cv-test-only-periodic.service"):
            self.assertIn(expected,(ROOT/name).read_text())
        self.assertIn('mkdir -m 700 "$STATE"',(ROOT/"install-isolated-periodic.sh").read_text())
if __name__=="__main__":unittest.main()
