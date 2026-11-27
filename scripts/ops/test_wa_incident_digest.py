import json
from pathlib import Path
import tempfile
import unittest
from github_contingency import wa_incident_digest


class DigestTests(unittest.TestCase):
    def test_private_aggregate(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            row = {'key': {'remoteJid': 'private-contact', 'id': 'private-id'},
                   'message': {'conversation': 'pago Zelle 123456789 pedido error'}}
            path = root / 'fixture.json'
            path.write_text(json.dumps({'messages': [row, row]}))
            path.chmod(0o600)
            result = wa_incident_digest(root)
            self.assertTrue(result['ok'])
            self.assertEqual(result['messages'], 1)
            self.assertEqual(result['duplicates'], 1)
            self.assertEqual(result['signals'], {'payment': 1, 'delivery': 1, 'bot': 1})
            self.assertFalse(result['live_coverage'])
            for secret in ('private-contact', 'private-id', '123456789', 'fixture.json'):
                self.assertNotIn(secret, json.dumps(result))

    def test_missing_source(self):
        with tempfile.TemporaryDirectory() as tmp:
            self.assertFalse(wa_incident_digest(Path(tmp) / 'missing')['ok'])

    def test_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / 'target'
            target.mkdir()
            link = root / 'link'
            link.symlink_to(target, target_is_directory=True)
            self.assertFalse(wa_incident_digest(link)['ok'])


if __name__ == '__main__':
    unittest.main()
