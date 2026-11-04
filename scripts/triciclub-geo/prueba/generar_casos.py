#!/usr/bin/env python3
"""Casos de prueba para Triciclub geo → casos.json
  python3 -I generar_casos.py ../datos/habana.osm.pbf ../habana-calles-v2.json casos.json
  python3 -I generar_casos.py ../datos/habana.osm.pbf ../habana-calles-v2.json casos-control.json control
  python3 -I generar_casos.py ../datos/habana.osm.pbf ../habana-calles-v2.json casos-lejos.json lejos   (GPS a 20 m)

A) A mano: cuadras y esquinas conocidas de La Habana («23 e/ L y M»). El punto del GPS se pone dentro de la cuadra (al 35 %
   y al 65 %, 8 m hacia un lado, como un GPS real) con las esquinas que da el mapa; la respuesta correcta la puse yo.
B) Automáticos: calles de Nuevo Vedado, Playa, Cerro (y algunas de Vedado, Centro y Habana Vieja) recorridas tal como
   están en OpenStreetMap: entre dos cruces seguidos de la misma vía, la respuesta correcta es «X e/ cruce1 y cruce2».
"""
import json
import os
import math
import random
import sys
import importlib.util

spec = importlib.util.spec_from_file_location('cv2', os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'construir_v2.py'))
cv2 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cv2)
norm, meters, xy = cv2.norm, cv2.meters, cv2.xy

ZONAS = {
    'Vedado': (23.137, -82.392), 'Centro Habana': (23.138, -82.370), 'Habana Vieja': (23.138, -82.352),
    'Nuevo Vedado': (23.115, -82.397), 'Playa': (23.115, -82.425), 'Cerro': (23.110, -82.375),
}
# (zona, calle, entrecalle 1, entrecalle 2, cómo lo escribiría alguien). «a|b» = vale cualquiera de los dos nombres.
CUADRAS = [
    ('Vedado', '23|rampa', 'l', 'm', '23 e/ L y M'), ('Vedado', '23', 'j', 'k', '23 e/ J y K'),
    ('Vedado', '17', '10', '12', '17 e/ 10 y 12'), ('Vedado', '17', '12', '14', '17 entre 12 y 14'),
    ('Vedado', 'linea', 'paseo', '2', 'Línea e/ Paseo y 2'), ('Vedado', 'l', '21', '23', 'L e/ 21 y 23'),
    ('Vedado', 'paseo', '23', '25', 'Paseo e/ 23 y 25'), ('Vedado', '23', '10', '12', 'Calle 23 e/ 10 y 12'),
    ('Vedado', '21', 'k', 'l', '21 e/ K y L'), ('Vedado', 'calzada', 'd', 'e', 'Calzada e/ D y E'),
    ('Vedado', '13', 'd', 'e', '13 e/ D y E'), ('Vedado', '19', '4', '6', '19 e/ 4 y 6'),
    ('Vedado', '25', 'h', 'i', '25 e/ H e I'), ('Vedado', 'presidentes|g', '23', '25', 'G e/ 23 y 25'),
    # En OpenStreetMap ese tramo de San Rafael se llama «Boulevard de San Rafael»: vale también.
    ('Centro Habana', 'galiano|italia', 'san rafael|boulevard san rafael', 'san miguel', 'Galiano e/ San Rafael y San Miguel'),
    ('Centro Habana', 'san rafael|boulevard san rafael', 'galiano|italia', 'aguila', 'San Rafael e/ Galiano y Águila'),
    ('Centro Habana', 'neptuno', 'escobar', 'gervasio', 'Neptuno e/ Escobar y Gervasio'),
    ('Centro Habana', 'san lazaro', 'escobar', 'gervasio', 'San Lázaro e/ Escobar y Gervasio'),
    ('Centro Habana', 'padre varela|belascoain', 'neptuno', 'san miguel', 'Belascoaín e/ Neptuno y San Miguel'),
    ('Centro Habana', 'concordia', 'lealtad', 'escobar', 'Concordia e/ Lealtad y Escobar'),
    ('Centro Habana', 'san miguel', 'lealtad', 'escobar', 'San Miguel e/ Lealtad y Escobar'),
    ('Habana Vieja', 'obispo', 'habana', 'compostela', 'Obispo e/ Habana y Compostela'),
    ('Habana Vieja', 'obispo', 'bernaza', 'villegas', 'Obispo e/ Bernaza y Villegas'),
    ('Habana Vieja', 'obispo', 'compostela', 'aguacate', 'Obispo e/ Compostela y Aguacate'),
    ('Habana Vieja', 'oreilly', 'habana', 'compostela', "O'Reilly e/ Habana y Compostela"),
    ('Habana Vieja', 'obrapia', 'compostela', 'habana', 'Obrapía e/ Compostela y Habana'),
    ('Habana Vieja', 'mercaderes', 'obispo', 'obrapia', 'Mercaderes e/ Obispo y Obrapía'),
    ('Habana Vieja', 'cuba', 'empedrado', 'tejadillo', 'Cuba e/ Empedrado y Tejadillo'),
    ('Habana Vieja', 'muralla', 'cuba', 'san ignacio', 'Muralla e/ Cuba y San Ignacio'),
    ('Habana Vieja', 'compostela', 'obispo', 'obrapia', 'Compostela e/ Obispo y Obrapía'),
    ('Playa', '5', '42', '44', '5ta Avenida e/ 42 y 44'), ('Playa', '7', '60', '62', '7ma e/ 60 y 62'),
    ('Playa', '3', '76', '78', '3ra e/ 76 y 78'),
]
ESQUINAS = [
    ('Vedado', '23|rampa', 'l', '23 y L'), ('Habana Vieja', 'obispo', 'bernaza', 'Obispo y Bernaza'),
    ('Centro Habana', 'galiano|italia', 'san rafael|boulevard san rafael', 'Galiano y San Rafael'), ('Vedado', 'linea', 'paseo', 'Línea y Paseo'),
    ('Playa', '5', '42', '5ta y 42'), ('Nuevo Vedado', 'norte', '35', 'Norte y 35'),
]
LUGARES = [  # (lo que escribe, nombre que tiene que salir, desde dónde escribe o None)
    ('Vari', 'Variedades Obispo', None), ('Vari', 'Variedades Obispo', ZONAS['Habana Vieja']),
    ('Copp', 'Coppelia', None), ('Copp', 'Coppelia', ZONAS['Vedado']),
    ('Hosp', 'Hospital Universitario General Calixto García', None), ('Hosp', 'Hospital Universitario General Calixto García', ZONAS['Vedado']),
    ('Cali', 'Hospital Universitario General Calixto García', ZONAS['Vedado']),
    ('Hotel Nac', 'Hotel Nacional de Cuba', None), ('Capi', 'Capitolio Nacional', ZONAS['Centro Habana']), ('Capi', 'Capitolio Nacional', None),
    ('Obis', 'Obispo', ZONAS['Habana Vieja']),
]


def alts(s):
    return s.split('|')


def esquina(cal, a, b, zona):
    """Los puntos de la esquina a∩b más cerca de la zona (de la lista x del archivo)."""
    best = None
    for na in alts(a):
        for nb in alts(b):
            if na not in cal['_ix'] or nb not in cal['_ix']:
                continue
            i, j = cal['_ix'][na], cal['_ix'][nb]
            v = cal['x'].get('%d,%d' % (min(i, j), max(i, j)), [])
            for k in range(0, len(v), 2):
                d = meters(zona[0], zona[1], v[k], v[k + 1])
                if best is None or d < best[0]:
                    best = (d, (v[k], v[k + 1]))
    return best


def mover(p, q, f, lado_m):
    """Punto al f de p→q, corrido lado_m metros a la izquierda."""
    la, lo = p[0] + f * (q[0] - p[0]), p[1] + f * (q[1] - p[1])
    dx, dy = xy(q, p)
    L = math.hypot(dx, dy) or 1
    nx, ny = -dy / L * lado_m, dx / L * lado_m
    return (round(la + ny / 110574.0, 6), round(lo + nx / (111320.0 * cv2.KX), 6))


CONTROL = {'Víbora': (23.090, -82.365), 'Lawton': (23.093, -82.352), 'Marianao': (23.080, -82.430),
           'Alamar': (23.165, -82.275), 'Santos Suárez': (23.103, -82.367), 'Vedado': ZONAS['Vedado'],
           'Centro Habana': ZONAS['Centro Habana'], 'Habana Vieja': ZONAS['Habana Vieja']}


def main(pbf, calles, out, modo='normal'):
    cal = json.load(open(calles))
    cal['_ix'] = {n: i for i, n in enumerate(cal['n'])}
    casos = {'donde': [], 'texto': [], 'sugerir': [], 'faltan': []}
    if modo == 'control':  # otra semilla, otros barrios, sin casos a mano: para ver si lo ajustado vale fuera de la prueba
        CUADRAS.clear(); ESQUINAS.clear(); LUGARES.clear()
        ZONAS.update(CONTROL)
    for zona, X, Y, Z, txt in CUADRAS:
        zc = ZONAS[zona]
        ey, ez = esquina(cal, X, Y, zc), esquina(cal, X, Z, zc)
        if not ey or not ez or ey[0] > 2500 or ez[0] > 2500 or meters(*ey[1], *ez[1]) > 300:
            casos['faltan'].append(txt); continue
        for k, f in enumerate((0.35, 0.65)):
            lejos = 20 if modo == 'lejos' else 8  # «lejos»: el GPS a 20 m de la calle, dentro de la manzana
            p = mover(ey[1], ez[1], f, lejos if k else -lejos)
            casos['donde'].append({'id': 'm-%s-%d' % (txt, k), 'grupo': 'a mano', 'zona': zona, 'lat': p[0], 'lng': p[1],
                                   'calle': X, 'e1': Y, 'e2': Z, 'esperado': txt})
        mid = ((ey[1][0] + ez[1][0]) / 2, (ey[1][1] + ez[1][1]) / 2)
        casos['texto'].append({'id': 't-' + txt, 'texto': txt, 'zona_txt': zona, 'lat': mid[0], 'lng': mid[1], 'tol': 70})
        casos['texto'].append({'id': 'tc-' + txt, 'texto': txt, 'zona_txt': zona, 'lat': mid[0], 'lng': mid[1], 'tol': 70, 'cerca': list(zc)})
    for zona, X, Y, txt in ESQUINAS:
        zc = ZONAS[zona]
        e = esquina(cal, X, Y, zc)
        if not e or e[0] > 2500:
            casos['faltan'].append(txt); continue
        p = (round(e[1][0] + 4 / 110574.0, 6), round(e[1][1] + 4 / (111320.0 * cv2.KX), 6))
        casos['donde'].append({'id': 'e-' + txt, 'grupo': 'esquina', 'zona': zona, 'lat': p[0], 'lng': p[1], 'calle': X, 'e1': Y, 'e2': None, 'esperado': X.split('|')[0] + ' esq. ' + Y})
        casos['texto'].append({'id': 't-' + txt, 'texto': txt, 'zona_txt': zona, 'lat': e[1][0], 'lng': e[1][1], 'tol': 35})
        casos['texto'].append({'id': 'tc-' + txt, 'texto': txt, 'zona_txt': zona, 'lat': e[1][0], 'lng': e[1][1], 'tol': 35, 'cerca': list(zc)})
    for txt, quiere, zc in LUGARES:
        c = {'id': 's-%s-%s' % (txt, 'gps' if zc else 'sin'), 'texto': txt, 'quiere': quiere}
        if zc:
            c['lat'], c['lng'] = zc
        casos['sugerir'].append(c)

    # B) automáticos, del orden real de los nodos en OpenStreetMap
    L = cv2.Lector()
    L.apply_file(pbf, locations=True, idx='flex_mem')
    en_nodo = {}
    for wi, (ns, ids, ll, hw) in enumerate(L.ways):
        for k, nd in enumerate(ids):
            en_nodo.setdefault(nd, []).append((wi, k))
    rnd = random.Random(4112026 if modo != 'control' else 20261105)
    cuota = {'Nuevo Vedado': 12, 'Playa': 12, 'Cerro': 12, 'Vedado': 6, 'Centro Habana': 6, 'Habana Vieja': 6}
    if modo == 'control':
        cuota = {z: 8 for z in CONTROL}
    if modo == 'lejos':
        cuota = {}
    BUENAS = {'residential', 'tertiary', 'secondary', 'primary', 'unclassified', 'living_street', 'pedestrian', 'trunk'}
    for zona, n in cuota.items():
        zc = ZONAS[zona]
        pool = []
        for wi, (ns, ids, ll, hw) in enumerate(L.ways):
            if hw not in BUENAS or meters(zc[0], zc[1], ll[len(ll) // 2][0], ll[len(ll) // 2][1]) > 900:
                continue
            mios = {k for k, _, _ in ns}
            cruces, acc = [], 0.0
            for k, nd in enumerate(ids):
                if k:
                    acc += meters(ll[k - 1][0], ll[k - 1][1], ll[k][0], ll[k][1])
                otros = [w for w, _ in en_nodo[nd] if w != wi]
                nombres = set()
                for w in otros:
                    on = {x for x, _, _ in L.ways[w][0]}
                    if on & mios:
                        continue
                    nombres |= on
                if not nombres:
                    continue
                if len(en_nodo[nd]) == 2 and k in (0, len(ids) - 1):  # punta con punta: solo cambia el nombre
                    w2, k2 = [e for e in en_nodo[nd] if e[0] != wi][0]
                    if k2 in (0, len(L.ways[w2][1]) - 1):
                        continue
                cruces.append((acc, k, nombres))
            for c1, c2 in zip(cruces, cruces[1:]):
                if 70 <= c2[0] - c1[0] <= 220:
                    pool.append((wi, c1, c2))
        rnd.shuffle(pool)
        vistos = set()
        for wi, c1, c2 in pool:
            ns, ids, ll, hw = L.ways[wi]
            X = '|'.join(k for k, _, _ in ns)
            key = (X, frozenset(c1[2]), frozenset(c2[2]))
            if key in vistos or n <= 0:
                continue
            vistos.add(key); n -= 1
            # el punto a mitad de la cuadra, a lo largo de la vía de verdad
            objetivo, acc = (c1[0] + c2[0]) / 2, 0.0
            for k in range(c1[1], c2[1]):
                seg = meters(ll[k][0], ll[k][1], ll[k + 1][0], ll[k + 1][1])
                if acc + seg >= objetivo - c1[0]:
                    f = (objetivo - c1[0] - acc) / seg if seg else 0
                    p = mover(ll[k], ll[k + 1], f, 8 if n % 2 else -8)
                    break
                acc += seg
            nombre = next(c for _, c, pr in ns)
            esperado = '%s e/ %s y %s' % (nombre, '/'.join(sorted(c1[2])), '/'.join(sorted(c2[2])))
            casos['donde'].append({'id': 'a-%s-%d' % (zona, n), 'grupo': 'automático', 'zona': zona, 'lat': p[0], 'lng': p[1],
                                   'calle': X, 'e1': '|'.join(sorted(c1[2])), 'e2': '|'.join(sorted(c2[2])), 'esperado': esperado})
    json.dump(casos, open(out, 'w'), ensure_ascii=False, indent=1)
    print('donde %d · texto %d · sugerir %d · no están en el mapa: %s' % (len(casos['donde']), len(casos['texto']), len(casos['sugerir']), casos['faltan']), file=sys.stderr)


if __name__ == '__main__':
    main(*sys.argv[1:5])
