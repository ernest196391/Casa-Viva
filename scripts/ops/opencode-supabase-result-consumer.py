#!/usr/bin/env python3
"""Consumidor separado: cliente Supabase inyectado; sin credenciales ni activación CLI."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import time
from uuid import UUID

_spec = importlib.util.spec_from_file_location(
    "opencode_result_contract", Path(__file__).with_name("opencode-result-consumer.py"))
contract = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(contract)


class SupabaseResultStore:
    """Una llamada RPC por resultado; nunca PATCH parciales ni decisiones humanas."""

    def __init__(self, client):
        self.client = client

    def accept(self, result, now=None):
        moment = time.time() if now is None else now
        contract.validate_result(result, moment)
        if not re.fullmatch(r"[1-9][0-9]{0,18}", result["run_id"]) or int(result["run_id"]) > 9223372036854775807:
            raise contract.ConsumerError("run_id debe ser bigint positivo canónico")
        try:
            if str(UUID(result["task_id"])) != result["task_id"]:
                raise ValueError()
        except (ValueError, AttributeError):
            raise contract.ConsumerError("task_id debe ser UUID canónico") from None
        finished = contract.parse_ts(result["finished_at"])
        expires = contract.parse_ts(result["expires_at"])
        if finished > moment or expires - finished > result["ttl_seconds"]:
            raise contract.ConsumerError("ventana temporal inválida")
        raw = json.dumps(result, ensure_ascii=False, allow_nan=False).encode("utf-8")
        if len(raw) > contract.MAX_RESULT_BYTES:
            raise contract.ConsumerError("resultado demasiado grande")
        for path in result["changed_paths"]:
            if path != contract.safe_rel_path(path) or any(c in path for c in ("\\", "\x00", "~")):
                raise contract.ConsumerError("ruta no canónica")
        response = self.client.rpc("ops_accept_opencode_result", {"p_result": result}).execute()
        data = response.data
        if not isinstance(data, dict) or data.get("status") not in ("waiting_approval", "failed", "duplicate"):
            raise RuntimeError("respuesta RPC inválida; conservar resultado para reintento")
        return data


def consume_results(store, home: Path, now=None):
    """Pin mailbox directories and never follow producer-controlled links.

    RPC errors keep results available for retry. Archive names are unique and
    reason files use exclusive, no-follow creation; no privileged path writes.
    """
    import fcntl
    from contextlib import ExitStack
    from uuid import uuid4
    summary = {"consumed": [], "dead": [], "retry": []}
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    with ExitStack() as stack:
        def opened(name, flags, mode=0o600, parent=None):
            fd = os.open(name, flags, mode, dir_fd=parent)
            stack.callback(os.close, fd)
            return fd
        home_fd = opened(home, directory_flags)
        if os.fstat(home_fd).st_mode & 0o022:
            raise contract.ConsumerError("buzón alterable por grupo/otros")
        results_fd = opened("results", directory_flags, parent=home_fd)
        dead_fd = opened("dead", directory_flags, parent=home_fd)
        if any(os.fstat(fd).st_mode & 0o022 for fd in (results_fd, dead_fd)):
            raise contract.ConsumerError("directorio de buzón inseguro")
        lock_fd = opened("supabase-consumer.lock",
                         os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK,
                         parent=home_fd)
        lock_info = os.fstat(lock_fd)
        if not stat.S_ISREG(lock_info.st_mode) or lock_info.st_nlink != 1 or lock_info.st_mode & 0o077:
            raise contract.ConsumerError("lock de consumidor inseguro")
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)

        def archive(name, reason):
            destination = "receipt-" + uuid4().hex
            os.rename(name, destination, src_dir_fd=results_fd, dst_dir_fd=dead_fd)
            note_fd = os.open(destination + ".motivo.txt",
                             os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                             0o600, dir_fd=dead_fd)
            with os.fdopen(note_fd, "w") as note:
                note.write((name + ": " + reason)[:2000])

        for name in sorted(os.listdir(results_fd)):
            try:
                match = contract.RESULT_NAME_RE.fullmatch(name)
                if not match:
                    raise contract.ConsumerError("nombre inválido")
                fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=results_fd)
                with os.fdopen(fd, "rb") as stream:
                    info = os.fstat(stream.fileno())
                    if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o022:
                        raise contract.ConsumerError("archivo inseguro")
                    raw = stream.read(contract.MAX_RESULT_BYTES + 1)
                if len(raw) > contract.MAX_RESULT_BYTES:
                    raise contract.ConsumerError("resultado demasiado grande")
                result = json.loads(raw)
                if not isinstance(result, dict):
                    raise contract.ConsumerError("resultado no es un objeto")
                if result.get("run_id") != match.group(1):
                    raise contract.ConsumerError("nombre no concilia con run_id")
                contract.validate_result(result, time.time() if now is None else now)
            except (ValueError, OSError) as exc:
                archive(name, str(exc))
                summary["dead"].append(name)
                continue
            try:
                receipt = store.accept(result, now)
            except contract.ConsumerError as exc:
                archive(name, str(exc))
                summary["dead"].append(name)
                continue
            except Exception:
                summary["retry"].append(name)
                continue
            archive(name, "persistido: " + receipt["status"])
            summary["consumed"].append(receipt)
    return summary


if __name__ == "__main__":
    raise SystemExit("Sin activación CLI: requiere cliente de servicio inyectado y migración revisada.")
