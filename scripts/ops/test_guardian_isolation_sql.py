"""Safe static checks for the constrained, non-production isolation SQL upgrade."""
import unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent
UP=(HERE/"sql/allow-guardian-isolation-probe.sql").read_text()
DOWN=(HERE/"sql/rollback-guardian-isolation-probe.sql").read_text()
class IsolationSqlTests(unittest.TestCase):
    def test_exact_input_and_cas(self):
        for sql in (UP,DOWN):
            self.assertIn("3e08e16a5a93e1313a96caa31229745a",sql)
            self.assertNotIn("DISABLE ROW LEVEL SECURITY",sql)
            self.assertNotIn("GRANT ",sql)
            self.assertNotIn("DROP POLICY",sql)
        self.assertIn("IS NOT DISTINCT FROM ''{}''::jsonb",UP)
        self.assertIn("IS NOT DISTINCT FROM ''chatgpt''::text",UP)
        self.assertIn("guardian_isolation_baseline_drift",UP)
    def test_rollback_protects_history(self):
        self.assertIn("rollback_history_must_be_preserved",DOWN)
        self.assertIn("md5(expected_old)",DOWN)
        self.assertIn("relrowsecurity",DOWN)
if __name__=="__main__":
    unittest.main()
