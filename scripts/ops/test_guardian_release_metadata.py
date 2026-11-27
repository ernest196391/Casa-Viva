import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("cv_bridge", Path(__file__).with_name("cv-ops-bridge.py"))
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class GuardianMetadataTests(unittest.TestCase):
    def test_missing_flags_are_disabled_with_provenance(self):
        flags, sources = bridge.guardian_effective_flags({})
        self.assertEqual(len(flags), 3)
        self.assertFalse(any(flags.values()))
        self.assertEqual(set(sources.values()), {"default_disabled"})

    def test_explicit_flags_and_invalid_values(self):
        name = "CV_GITHUB_RELEASE_ENABLED"
        for value in ("0", "1"):
            flags, sources = bridge.guardian_effective_flags({name: value})
            self.assertEqual(flags[name], value == "1")
            self.assertEqual(sources[name], "explicit")
        for value in ("", "true", "2", None):
            with self.subTest(value=value), self.assertRaises(ValueError):
                bridge.guardian_effective_flags({name: value})

    def test_untrusted_gate_never_executes_or_contacts_github(self):
        with patch("github_contingency.private_bytes", return_value=b"raise RuntimeError('untrusted')") as read, patch.object(bridge.urllib.request, "urlopen") as network:
            result = bridge.guardian_gate_attestation()
        self.assertFalse(result["gate_matches_pin"])
        self.assertFalse(result["token_read_verified"])
        self.assertEqual(read.call_count, 1)
        network.assert_not_called()

    def test_missing_gate_fails_closed(self):
        with patch("github_contingency.private_bytes", side_effect=OSError("private")):
            result = bridge.guardian_gate_attestation()
        self.assertFalse(any(result.values()))

    def test_metadata_does_not_read_token(self):
        with patch.object(Path, "lstat", side_effect=FileNotFoundError), patch.object(Path, "read_bytes", side_effect=AssertionError):
            self.assertEqual(bridge.guardian_release_metadata(), {"token": {"present": False}, "gate": {"present": False}})

    def test_history_structure_only(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            f = directory / "sample.json"
            f.write_text(json.dumps({"data": {"messages": [{"text": "PRIVATE_SENTINEL"}]}}))
            f.chmod(0o600)
            result = bridge.guardian_history_schema(directory)
            self.assertEqual(result["sampled"], 1)
            self.assertEqual(result["messages_list"], 1)
            self.assertNotIn("PRIVATE_SENTINEL", json.dumps(result))

    def test_symlink_directory_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            link = directory / "link"
            link.symlink_to(directory, target_is_directory=True)
            self.assertEqual(bridge.guardian_history_schema(link)["sampled"], 0)


if __name__ == "__main__":
    unittest.main()
