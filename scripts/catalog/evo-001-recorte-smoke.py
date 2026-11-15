#!/usr/bin/env python3
"""Controlled VPS cutout test. No generation, QA API, publication or service changes.

Run on VPS: IMAGEN_PANEL_KEY=<existing environment> python3 SCRIPT /path/to/real.jpg
Keep the existing secret in the environment; never put it on the command line.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import struct
import sys
import tempfile
import time
import urllib.error
import urllib.request


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Expected one existing real JPG/PNG/WebP photo path')
    key = os.environ.get('IMAGEN_PANEL_KEY', '')
    if len(key) < 32:
        raise SystemExit('IMAGEN_PANEL_KEY must be provided in the environment; no secret is printed')
    photo = Path(sys.argv[1]).read_bytes()
    if not photo or len(photo) > 8 * 1024 * 1024:
        raise SystemExit('Photo must be nonempty and at most 8 MB')
    mime = 'image/png' if photo.startswith(b'\x89PNG') else 'image/jpeg' if photo.startswith(b'\xff\xd8') else 'image/webp' if photo[:4] == b'RIFF' and photo[8:12] == b'WEBP' else None
    if not mime:
        raise SystemExit('Unsupported image signature')
    # Fixed local recorte endpoint. It cannot be redirected to paid /panel.
    url = 'http://127.0.0.1:5020/imagen/recorte'
    data = json.dumps({'mime': mime, 'foto_b64': base64.b64encode(photo).decode(), 'quien': 'EVO-001 controlled smoke'}).encode()
    request = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json', 'X-Imagen-Key': key}, method='POST')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args):
            return None
    start = time.monotonic()
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=180) as response:
        result = json.loads(response.read(24 * 1024 * 1024))
    if not result.get('ok') or not result.get('png_b64'):
        raise SystemExit('Recorte did not return a successful PNG')
    png = base64.b64decode(result['png_b64'], validate=True)
    if not png.startswith(b'\x89PNG\r\n\x1a\n') or len(png) < 33:
        raise SystemExit('Invalid PNG signature')
    width, height = struct.unpack('>II', png[16:24])
    if not width or not height:
        raise SystemExit('Empty image dimensions')
    with tempfile.NamedTemporaryFile(prefix='evo-001-recorte-', suffix='.png', delete=False) as output:
        output.write(png)
    print(json.dumps({'ok': True, 'endpoint': '/imagen/recorte', 'input_sha256': hashlib.sha256(photo).hexdigest(), 'output_sha256': hashlib.sha256(png).hexdigest(), 'width': width, 'height': height, 'bytes': len(png), 'seconds': round(time.monotonic() - start, 2), 'fiable': bool(result.get('fiable')), 'motivos': result.get('motivos', []), 'output': output.name, 'billable_endpoint_called': False, 'catalog_changed': False, 'visual_fidelity': 'requires_human_review'}, ensure_ascii=False))


if __name__ == '__main__':
    main()
