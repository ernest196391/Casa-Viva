import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch, Mock

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
        with patch("github_contingency.private_bytes", return_value=b"raise RuntimeError('untrusted')") as read, patch.object(bridge.urllib.request, "build_opener") as network:
            result = bridge.guardian_gate_attestation()
        self.assertFalse(result["gate_matches_pin"])
        self.assertFalse(result["token_read_verified"])
        self.assertEqual(read.call_count, 1)
        network.assert_not_called()

    def test_missing_gate_fails_closed(self):
        with patch("github_contingency.private_bytes", side_effect=OSError("private")):
            result = bridge.guardian_gate_attestation()
        self.assertFalse(any(result.values()))

    def test_verified_gate_read_only_probe_and_redirect_denial(self):
        raw = Path(__file__).with_name("github_release_gate.py").read_bytes()
        sha = "a" * 40
        rows = [{"workflow_id": wid, "head_sha": sha, "head_branch": "main",
                 "event": "push", "status": "completed", "conclusion": "success"}
                for wid in (316960256, 336358124, 336470374)]
        replies = [{"protected": True, "commit": {"sha": sha}},
                   {"total_count": 3, "workflow_runs": rows}]
        opener = Mock()
        def open_response(req, timeout):
            self.assertEqual(req.get_method(), "GET")
            self.assertTrue(req.full_url.startswith("https://api.github.com/repos/ernest196391/Casa-Viva/"))
            self.assertEqual(req.get_header("Authorization"), "Bearer FIXTURE_SECRET")
            response = Mock()
            response.__enter__ = Mock(return_value=response)
            response.__exit__ = Mock(return_value=False)
            response.read.return_value = json.dumps(replies.pop(0)).encode()
            return response
        opener.open.side_effect = open_response
        def build(handler):
            with self.assertRaises(ValueError):
                handler.redirect_request(None, None, 302, "", {}, "https://example.org")
            return opener
        with patch("github_contingency.private_bytes", return_value=raw), \
             patch("os.open", return_value=123), \
             patch("os.fdopen") as fdopen, \
             patch("os.fstat", return_value=Mock(st_mode=0o100600, st_uid=0)), \
             patch("os.geteuid", return_value=0), \
             patch.object(bridge.urllib.request, "build_opener", side_effect=build):
            fdopen.return_value.__enter__.return_value.read.return_value = b"CV_GITHUB_RELEASE_TOKEN=FIXTURE_SECRET"
            result = bridge.guardian_gate_attestation()
        self.assertTrue(result["gate_matches_pin"])
        self.assertTrue(result["token_read_verified"])
        self.assertTrue(result["protected_main"])
        self.assertTrue(result["push_checks_verified"])
        self.assertEqual(result["main_sha"], sha)
        self.assertEqual(opener.open.call_count, 2)
        self.assertNotIn("FIXTURE_SECRET", json.dumps(result))

    def test_effective_unit_metadata_is_sanitized(self):
        raw = b"LoadState=loaded\nUnitFileState=enabled\nType=oneshot\nFragmentPath=/private/unit\nDropInPaths=/private/dropin\nNeedDaemonReload=yes\nNoNewPrivileges=no\nAppArmorProfile=-profile\n"
        with patch.object(bridge.subprocess, "run", return_value=Mock(returncode=0, stdout=raw)):
            result = bridge.guardian_effective_isolation_unit()
        self.assertTrue(result["available"])
        self.assertTrue(result["daemon_reload_needed"])
        self.assertTrue(result["dropins_present"])
        self.assertFalse(result["no_new_privileges"])
        self.assertFalse(result["apparmor_enforced_config"])
        self.assertFalse(result["functional_isolation_certified"])
        self.assertNotIn("/private", json.dumps(result))

    def test_gate_identity_never_executes(self):
        raw = b"raise RuntimeError('PRIVATE_SENTINEL')"
        with patch("github_contingency.private_bytes", return_value=raw):
            result = bridge.guardian_gate_identity()
        self.assertTrue(result["available"])
        self.assertEqual(result["git_blob"], bridge.guardian_blob(raw))
        self.assertFalse(result["matches_pin"])
        self.assertNotIn("PRIVATE_SENTINEL", json.dumps(result))

    def test_inventory_filters_and_deduplicates(self):
        raw = b"cv-receiver-isolation.service enabled\ncv-guardian.service loaded\nother-private.service enabled\ncv-unrelated.service enabled\n"
        with patch.object(bridge.subprocess, "run", return_value=Mock(returncode=0, stdout=raw)) as run:
            result = bridge.guardian_isolation_inventory()
        self.assertEqual(result["units"], ["cv-guardian.service", "cv-receiver-isolation.service"])
        self.assertFalse(result["functional_isolation_certified"])
        self.assertEqual(run.call_count, 2)

    def test_inventory_failure(self):
        with patch.object(bridge.subprocess, "run", return_value=Mock(returncode=1, stdout=b"private")):
            self.assertEqual(bridge.guardian_isolation_inventory(), {"available": False})

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
