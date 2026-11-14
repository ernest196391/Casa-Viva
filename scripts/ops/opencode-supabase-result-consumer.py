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
        if not isinstance(data, dict) or data.get("status") not in ("awaiting_approval", "failed", "duplicate"):
            raise RuntimeError("respuesta RPC inválida; conservar resultado para reintento")
        return data


def consume_results(store, home: Path, now=None):
    """Conserva el archivo ante error RPC, incluso si se perdió la respuesta al COMMIT.

    El siguiente intento concilia el recibo persistido y devuelve duplicate.
    No expira runs: esa política sigue siendo responsabilidad del worker existente.
    """
    import fcntl
    dirs = contract.ensure_mailbox_dirs(home)
    summary = {"consumed": [], "dead": [], "retry": []}
    with open(home / "supabase-consumer.lock", "a") as guard:
        fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        for path in sorted(dirs["results"].iterdir()):
            try:
                if not contract.RESULT_NAME_RE.fullmatch(path.name):
                    raise contract.ConsumerError("nombre inválido")
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
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
                if result.get("run_id") != contract.RESULT_NAME_RE.fullmatch(path.name).group(1):
                    raise contract.ConsumerError("nombre no concilia con run_id")
                contract.validate_result(result, time.time() if now is None else now)
            except (ValueError, OSError) as exc:
                contract.move_to_dead(dirs, path, str(exc))
                summary["dead"].append(path.name)
                continue
            try:
                receipt = store.accept(result, now)
            except Exception:
                summary["retry"].append(path.name)
                continue
            contract.move_to_dead(dirs, path, "persistido: " + receipt["status"])
            summary["consumed"].append(receipt)
    return summary


if __name__ == "__main__":
    raise SystemExit("Sin activación CLI: requiere cliente de servicio inyectado y migración revisada.")
