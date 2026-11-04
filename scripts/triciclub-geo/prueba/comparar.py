#!/usr/bin/env python3
"""Corre la prueba con el código y los datos de antes (main + habana-calles v1) y los de después (rama + v2 + lugares),
y escribe resultados-antes.json, resultados-despues.json y tabla.md.
  python3 -I comparar.py                  (casos.json → tabla.md)
  python3 -I comparar.py casos-control    (casos-control.json → tabla-casos-control.md)
"""
import json
import os
import subprocess
import sys
import importlib.util
from statistics import median

spec = importlib.util.spec_from_file_location('cv2', os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'construir_v2.py'))
cv2 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cv2)
norm, meters = cv2.norm, cv2.meters

AQUI = os.path.dirname(os.path.abspath(__file__))
CORE = os.environ.get('CVD_CORE', '/root/Casa-Viva/wordpress/casa-viva-dropship-core') + '/includes'
VERSIONES = {
    'antes': (AQUI + '/antes', AQUI + '/geo-antes.php', AQUI + '/direccion-antes.php', AQUI + '/api-antes.php'),
    'despues': (AQUI + '/despues', CORE + '/class-cvd-triciclub-geo.php', CORE + '/class-cvd-geo-direccion.php', CORE + '/class-cvd-triciclub-api.php'),
}


def ok_donde(c, texto):
    if not texto:
        return False
    A = lambda s: set(s.split('|')) if s else set()
    X, Y, Z = A(c['calle']), A(c['e1']), A(c['e2'])
    if ' esq. ' in texto:
        m, a = texto.split(' esq. ', 1)
        par = {norm(m), norm(a)}
        return c['e2'] is None and bool(par & X) and bool(par & Y) and norm(m) != norm(a)
    if ' e/ ' not in texto or c['e2'] is None:
        return False
    m, resto = texto.split(' e/ ', 1)
    if norm(m) not in X:
        return False
    partes = resto.split(' y ')
    for k in range(1, len(partes)):
        a, b = norm(' y '.join(partes[:k])), norm(' y '.join(partes[k:]))
        if (a in Y and b in Z) or (a in Z and b in Y):
            return True
    return False


def main(nombre='casos'):
    casos = json.load(open('%s/%s.json' % (AQUI, nombre)))
    res = {}
    for v, (up, geo, dirc, api) in VERSIONES.items():
        out = subprocess.run(['php', AQUI + '/correr.php', up, geo, dirc, api, '%s/%s.json' % (AQUI, nombre)], capture_output=True, text=True, check=True).stdout
        res[v] = json.loads(out)
        open('%s/resultados-%s%s.json' % (AQUI, v, '' if nombre == 'casos' else '-' + nombre), 'w').write(out)
    md = []
    # --- dónde estoy
    grupos = {}
    filas = []
    for k, c in enumerate(casos['donde']):
        a, d = res['antes']['donde'][k], res['despues']['donde'][k]
        oa, od = ok_donde(c, a['texto']), ok_donde(c, d['texto'])
        g = grupos.setdefault(c['grupo'], [0, 0, 0])
        g[0] += 1; g[1] += oa; g[2] += od
        z = grupos.setdefault('zona ' + c['zona'], [0, 0, 0])
        z[0] += 1; z[1] += oa; z[2] += od
        filas.append('| %s | %s | %s | %s %s | %s %s |' % (c['grupo'], c['zona'], c['esperado'], '✅' if oa else '❌', a['texto'] or '—', '✅' if od else '❌', d['texto'] or '—'))
    tot = [len(casos['donde']), sum(ok_donde(c, res['antes']['donde'][k]['texto']) for k, c in enumerate(casos['donde'])), sum(ok_donde(c, res['despues']['donde'][k]['texto']) for k, c in enumerate(casos['donde']))]
    pct = lambda a, n: '%d/%d (%.0f %%)' % (a, n, 100 * a / n if n else 0)
    md.append('### «Dónde estoy» (punto del GPS → «X e/ Y y Z»)\n')
    md.append('| grupo | puntos | antes | después |\n|---|---|---|---|')
    for k in ['a mano', 'esquina', 'automático'] + sorted(x for x in grupos if x.startswith('zona ')):
        if k in grupos:
            n, a, d = grupos[k]
            md.append('| %s | %d | %s | %s |' % (k, n, pct(a, n), pct(d, n)))
    md.append('| **total** | %d | **%s** | **%s** |' % (tot[0], pct(tot[1], tot[0]), pct(tot[2], tot[0])))
    # --- texto → punto
    md.append('\n### Dirección escrita → punto (`localizar`, la que sale primera en las sugerencias)\n')
    md.append('| caso | antes | después |\n|---|---|---|')
    tf = []
    cnt = {'sin GPS': [0, 0, 0], 'con GPS de la zona': [0, 0, 0]}
    for k, c in enumerate(casos['texto']):
        r = []
        for v in ('antes', 'despues'):
            x = res[v]['texto'][k]
            if x['lat'] is None:
                r.append((False, 'no lo encuentra'))
            else:
                d = meters(c['lat'], c['lng'], x['lat'], x['lng'])
                r.append((d <= c['tol'], '%s a %s' % (x['how'], ('%.0f m' % d) if d < 2000 else ('%.1f km' % (d / 1000)))))
        g = cnt['con GPS de la zona' if 'cerca' in c else 'sin GPS']
        g[0] += 1; g[1] += r[0][0]; g[2] += r[1][0]
        tf.append('| %s%s | %s %s | %s %s |' % (c['texto'], ' (GPS en %s)' % c['zona_txt'] if 'cerca' in c else '', '✅' if r[0][0] else '❌', r[0][1], '✅' if r[1][0] else '❌', r[1][1]))
    for k, (n, a, d) in cnt.items():
        md.append('| %s | %s | %s |' % (k, pct(a, n), pct(d, n)))
    # --- lugares
    md.append('\n### Lugares al escribir (¿sale en las 8 sugerencias?)\n')
    md.append('| escribe | tiene que salir | antes | después (puesto) |\n|---|---|---|---|')
    for k, c in enumerate(casos['sugerir']):
        r = []
        for v in ('antes', 'despues'):
            lst = res[v]['sugerir'][k]['lista']
            pos = next((i + 1 for i, s in enumerate(lst) if c['quiere'].lower() in s[0].lower()), None)
            r.append(('✅ %d.º' % pos) if pos else '❌')
        top = res['despues']['sugerir'][k]['lista'][:1]
        md.append('| «%s»%s | %s | %s | %s |' % (c['texto'], ' (GPS)' if 'lat' in c else '', c['quiere'], r[0], r[1] + (' — 1.ª: %s · %s' % (top[0][0], top[0][1]) if top else '')))
    # --- tiempos
    md.append('\n### Tiempo en PHP (este VPS, PHP 8.3, sin caché; incluye cargar los JSON en cada búsqueda)\n')
    md.append('| | antes mediana / máx. | después mediana / máx. |\n|---|---|---|')
    for key, nom in (('donde', 'dónde estoy (sin contar la llamada a OSRM)'), ('texto', 'dirección escrita'), ('sugerir', 'sugerencias')):
        a = [x['ms'] for x in res['antes'][key]]; d = [x['ms'] for x in res['despues'][key]]
        if not a:
            continue
        md.append('| %s | %.0f / %.0f ms | %.0f / %.0f ms |' % (nom, median(a), max(a), median(d), max(d)))
    md.append('| cargar habana-calles.json | %.0f ms | %.0f ms |' % (res['antes']['carga']['calles_ms'], res['despues']['carga']['calles_ms']))
    md.append('| memoria pico | %.0f MB | %.0f MB |' % (res['antes']['carga']['memoria_pico_mb'], res['despues']['carga']['memoria_pico_mb']))
    if casos['faltan']:
        md.append('\nCasos a mano que no se pudieron montar (las esquinas no están en el mapa): ' + ', '.join(casos['faltan']))
    md.append('\n### Detalle «dónde estoy»\n\n| grupo | zona | esperado | antes | después |\n|---|---|---|---|---|')
    md += filas
    md.append('\n### Detalle dirección escrita\n\n| caso | antes | después |\n|---|---|---|')
    md += tf
    open('%s/tabla%s.md' % (AQUI, '' if nombre == 'casos' else '-' + nombre), 'w').write('\n'.join(md) + '\n')
    print('\n'.join(md[:40]))


if __name__ == '__main__':
    main(*sys.argv[1:2])
