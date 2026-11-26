#!/usr/bin/env python3
"""Offline staging only; never install to system directories or start a service."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import tempfile

HERE=Path(__file__).resolve().parent
FILES=("dev-worker.py","dev-sandbox.py","github_contingency.py","github-test-only-bridge.py",
       "github_test_only_executor.py","github_test_only_launcher.py","github_test_only_reporter.py",
       "github_test_only_host_preflight.py",
       "github_release_candidate_limits.py","github_release_candidate_boundary.py",
       "github_release_host_observer.py","github_release_health_emitter.py",
       "github_test_only_synthetic_pilot.py","github_test_only_launcher_certification.py","sandbox/opencode-sandbox-spec.py",
       "sandbox/dev-worker-sandbox.profile","sandbox/cv-test-only-candidate.service",
       "sandbox/cv-test-only-candidate.timer")
def stage(destination):
    destination=Path(destination).absolute()
    if destination.exists() or destination.is_symlink():
        raise ValueError("destination must not exist")
    if destination.parent.resolve()!=destination.parent:
        raise ValueError("symlink parent")
    if not destination.parent.is_dir():
        raise ValueError("missing parent")
    # Only disposable staging locations. Never copy into live install paths.
    if not destination.is_relative_to(Path("/tmp")):
        raise ValueError("stage under /tmp only")
    root=Path(tempfile.mkdtemp(prefix=".cv-test-only-",dir=destination.parent))
    try:
        manifest={}
        for name in FILES:
            source=HERE/name
            if source.is_symlink() or not source.is_file():
                raise ValueError("invalid source")
            data=source.read_bytes()
            target=root/name
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(data)
            target.chmod(0o600)
            manifest[name]=hashlib.sha256(data).hexdigest()
        (root/"MANIFEST.json").write_text(json.dumps({"activation":False,"sha256":manifest},sort_keys=True,indent=2))
        # Atomic publication without replacing an existing staged directory.
        if destination.exists() or destination.is_symlink(): raise ValueError("destination occupied")
        os.rename(root,destination)
    finally:
        if root.exists():shutil.rmtree(root)
    return destination

if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("destination")
    args=p.parse_args()
    print(stage(args.destination))
