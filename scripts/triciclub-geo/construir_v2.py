#!/usr/bin/env python3
"""Triciclub · calles y lugares de La Habana desde el mapa de OpenStreetMap (.osm.pbf), sin Overpass.

  1) Bajar Cuba y recortar la provincia (una vez al mes basta):
       curl -sSfLo cuba.osm.pbf https://download.geofabrik.de/central-america/cuba-latest.osm.pbf
       osmium getid -r -t cuba.osm.pbf r1854615 -o habana-borde.osm      # relación «La Habana» (admin_level 4)
       osmium extract -p habana-borde.osm -s smart cuba.osm.pbf -o habana.osm.pbf
  2) python3 construir_v2.py habana.osm.pbf habana-calles-v2.json habana-lugares.json [habana-calles-v1.json]
     (el v1 es opcional: si se da, los números de calle de v1 se mantienen igual en v2)

habana-calles-v2.json: el MISMO formato de v1 (n, x, s, p) + campos nuevos:
  d[i]  = el nombre como se escribe («San Lázaro», «Calle 23»), para mostrarlo.
  g[i]  = la forma de la calle i: lista de tramos seguidos, cada uno en «encoded polyline» (precisión 5, como Google).
  c     = rejilla de 0.005° (~500 m): «fila:col» → [i, …] calles que pasan por esa celda (para buscar rápido).
  xs    = como x, pero solo los puntos donde una calle sigue recta con otro nombre (no es un cruce: donde() no los usa).
  par   = [«i,j»…]: i es el nombre entre paréntesis de j en el mismo tramo («Línea (9)»: 9 → Línea); se muestra j.
  alt   = calles cuyo nombre es solo alternativo (alt_name, old_name…): no se muestran si hay otro nombre en el mismo sitio.
habana-lugares.json: l = [[nombre, tipo, lat, lon, dirección, barrio, municipio, clave, importancia 0–3], …]
La forma de escribir los nombres (norm) es la misma que CVD_Geo_Direccion::norm() en PHP.
"""
import json
import math
import re
import sys
import unicodedata
from collections import defaultdict, Counter
from datetime import date

import osmium
from shapely.geometry import Point, shape
from shapely.prepared import prep
from shapely import wkb as shp_wkb

# ---------------------------------------------------------------- nombres (igual que norm() de JS y PHP)
STOP = {'calle', 'c', 'ave', 'av', 'avda', 'avenida', 'calzada', 'calz', 'cz', 'de', 'del', 'la', 'las', 'los', 'el'}
WORDS = {'primer': '1', 'primera': '1', 'primero': '1', 'segunda': '2', 'segundo': '2', 'tercera': '3', 'tercero': '3',
         'cuarta': '4', 'cuarto': '4', 'quinta': '5', 'quinto': '5', 'sexta': '6', 'sexto': '6', 'septima': '7',
         'septimo': '7', 'setima': '7', 'octava': '8', 'octavo': '8', 'novena': '9', 'noveno': '9', 'decima': '10',
         'decimo': '10', 'gral': 'general', 'sto': 'santo', 'sta': 'santa', 'sn': 'san'}
ORD = re.compile(r'\b(\d+)(?:ra|ro|era|ero|da|do|ta|to|ma|mo|va|vo|na|no)?(?:\s?([a-z])\b)?(?![a-z])')


def ascii_low(s):
    t = unicodedata.normalize('NFD', str(s or '').lower())
    return ''.join(ch for ch in t if not unicodedata.combining(ch))


def norm(s):
    t = ascii_low(s)
    t = re.sub(r"['’´`‘]", '', t)
    t = re.sub(r'[^a-z0-9]+', ' ', t)
    t = ORD.sub(lambda m: m.group(1) + ' ' + m.group(2) if m.group(2) else m.group(1), t)
    tok = [WORDS.get(w, w) for w in t.split(' ') if w]
    keep = [w for w in tok if w not in STOP]
    return ' '.join(keep if keep else tok[-1:])


def clave(s):
    """Para buscar lugares mientras se escribe: minúsculas, sin tildes, solo letras y números."""
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', ascii_low(s)).split())


NAME_KEYS = ('name', 'alt_name', 'old_name', 'official_name', 'loc_name', 'short_name')


def ws_name(ns):
    return ns.raw_name if hasattr(ns, 'raw_name') else ''


class Nombres(list):
    raw_name = ''


def names(tags):
    """→ [(norm, crudo, es_principal)] como names() de construir-calles.mjs."""
    out, seen = Nombres(), set()
    out.raw_name = str(tags.get('name') or '')
    for k in NAME_KEYS:
        for raw in str(tags.get(k) or '').split(';'):
            v = raw.strip()
            if not v:
                continue
            m = re.match(r'^(.*?)\s*\((.*)\)\s*$', v)  # «Padre Varela (Belascoaín)» → los dos
            for q, n in enumerate([m.group(1), m.group(2)] if m else [v]):
                k2 = norm(n)
                if k2 and k2 not in seen:
                    seen.add(k2)
                    out.append((k2, n.strip(), k == 'name' and q == 0))  # «Línea (9)»: el principal es «Línea»
    return out


# ---------------------------------------------------------------- geometría
R = 6371000.0
KX = math.cos(math.radians(23.1))


def meters(a, b, c, d):
    x, y = math.radians(c - a), math.radians(d - b)
    h = math.sin(x / 2) ** 2 + math.cos(math.radians(a)) * math.cos(math.radians(c)) * math.sin(y / 2) ** 2
    return 2 * R * math.asin(min(1, math.sqrt(h)))


def xy(p, o):
    """lat/lon → metros respecto a o (plano local, vale para pocos km)."""
    return ((p[1] - o[1]) * 111320.0 * KX, (p[0] - o[0]) * 110574.0)


def r5(v):
    return round(v * 1e5) / 1e5


def dp(pts, tol=3.0):
    """Douglas-Peucker en metros."""
    if len(pts) < 3:
        return pts
    o = pts[0]
    P = [xy(p, o) for p in pts]
    keep = [False] * len(pts)
    keep[0] = keep[-1] = True
    st = [(0, len(pts) - 1)]
    while st:
        i, j = st.pop()
        ax, ay = P[i]; bx, by = P[j]
        dx, dy = bx - ax, by - ay
        L = dx * dx + dy * dy
        best, bi = -1, -1
        for k in range(i + 1, j):
            px, py = P[k]
            if L:
                t = max(0, min(1, ((px - ax) * dx + (py - ay) * dy) / L))
                qx, qy = ax + t * dx, ay + t * dy
            else:
                qx, qy = ax, ay
            d = math.hypot(px - qx, py - qy)
            if d > best:
                best, bi = d, k
        if best > tol:
            keep[bi] = True
            st += [(i, bi), (bi, j)]
    return [p for p, k in zip(pts, keep) if k]


def enc_val(v):
    v = ~(v << 1) if v < 0 else v << 1
    out = []
    while v >= 0x20:
        out.append(chr((0x20 | (v & 0x1f)) + 63))
        v >>= 5
    out.append(chr(v + 63))
    return ''.join(out)


def encode(pts):
    out, pla, plo = [], 0, 0
    for la, lo in pts:
        a, b = int(round(la * 1e5)), int(round(lo * 1e5))
        out.append(enc_val(a - pla) + enc_val(b - plo))
        pla, plo = a, b
    return ''.join(out)


def angle(p, q):
    x, y = xy(q, p)
    return math.atan2(y, x)


# ---------------------------------------------------------------- lectura del .pbf
SKIP_HW = {'proposed', 'construction', 'abandoned', 'platform', 'bus_stop', 'corridor', 'elevator', 'razed',
           'disused', 'services', 'rest_area', 'emergency_bay', 'bus_guideway', 'raceway'}
BARRIO_PLACES = {'suburb', 'neighbourhood', 'quarter', 'town', 'village', 'hamlet', 'locality'}

# tipo en español: (clave, valor o '*') → tipo. El orden importa: lo primero que case.
TIPOS = [
    (('amenity', 'ice_cream'), 'heladería'), (('shop', 'ice_cream'), 'heladería'),
    (('amenity', 'cafe'), 'cafetería'), (('amenity', 'fast_food'), 'cafetería'), (('shop', 'bakery'), 'panadería'),
    (('shop', 'pastry'), 'dulcería'), (('shop', 'confectionery'), 'dulcería'),
    (('amenity', 'restaurant'), 'restaurante'), (('amenity', 'bar'), 'bar'), (('amenity', 'pub'), 'bar'),
    (('amenity', 'nightclub'), 'discoteca'), (('amenity', 'hospital'), 'hospital'), (('healthcare', 'hospital'), 'hospital'),
    (('amenity', 'clinic'), 'policlínico'), (('healthcare', 'clinic'), 'policlínico'), (('amenity', 'doctors'), 'consultorio'),
    (('healthcare', 'doctor'), 'consultorio'), (('amenity', 'dentist'), 'dentista'), (('amenity', 'pharmacy'), 'farmacia'),
    (('healthcare', 'pharmacy'), 'farmacia'), (('amenity', 'school'), 'escuela'), (('amenity', 'kindergarten'), 'círculo infantil'),
    (('amenity', 'university'), 'universidad'), (('amenity', 'college'), 'escuela'), (('amenity', 'bank'), 'banco'),
    (('amenity', 'bureau_de_change'), 'cadeca'), (('amenity', 'atm'), 'cajero'), (('amenity', 'fuel'), 'gasolinera'),
    (('amenity', 'place_of_worship'), 'iglesia'), (('amenity', 'theatre'), 'teatro'), (('amenity', 'cinema'), 'cine'),
    (('amenity', 'arts_centre'), 'centro cultural'), (('amenity', 'community_centre'), 'centro cultural'),
    (('amenity', 'library'), 'biblioteca'), (('amenity', 'police'), 'policía'), (('amenity', 'fire_station'), 'bomberos'),
    (('amenity', 'post_office'), 'correos'), (('amenity', 'marketplace'), 'agromercado'), (('amenity', 'bus_station'), 'terminal'),
    (('amenity', 'ferry_terminal'), 'terminal'), (('amenity', 'townhall'), 'gobierno'), (('amenity', 'courthouse'), 'tribunal'),
    (('amenity', 'embassy'), 'embajada'), (('office', 'diplomatic'), 'embajada'), (('amenity', 'veterinary'), 'veterinaria'),
    (('amenity', 'car_rental'), 'renta de autos'), (('amenity', 'parking'), 'parqueo'), (('amenity', 'internet_cafe'), 'internet'),
    (('amenity', 'telephone'), None), (('amenity', 'toilets'), None), (('amenity', 'bench'), None), (('amenity', 'waste_basket'), None),
    (('amenity', 'drinking_water'), None), (('amenity', 'shelter'), None), (('amenity', 'vending_machine'), None),
    (('shop', 'supermarket'), 'mercado'), (('shop', 'convenience'), 'bodega'), (('shop', 'greengrocer'), 'agromercado'),
    (('shop', 'butcher'), 'carnicería'), (('shop', 'hairdresser'), 'peluquería'), (('shop', 'beauty'), 'peluquería'),
    (('shop', 'mobile_phone'), 'telefonía'), (('shop', 'telecommunication'), 'telefonía'), (('shop', 'car_repair'), 'taller'),
    (('shop', 'hardware'), 'ferretería'), (('shop', 'doityourself'), 'ferretería'), (('shop', 'clothes'), 'ropa'),
    (('shop', 'shoes'), 'zapatería'), (('shop', 'books'), 'librería'), (('shop', 'department_store'), 'tienda'),
    (('shop', 'mall'), 'centro comercial'), (('shop', '*'), 'tienda'), (('craft', '*'), 'taller'),
    (('tourism', 'hotel'), 'hotel'), (('tourism', 'hostel'), 'hostal'), (('tourism', 'guest_house'), 'casa particular'),
    (('tourism', 'apartment'), 'casa particular'), (('tourism', 'motel'), 'hotel'), (('tourism', 'museum'), 'museo'),
    (('tourism', 'gallery'), 'galería'), (('tourism', 'attraction'), 'lugar'), (('tourism', 'viewpoint'), 'mirador'),
    (('tourism', 'zoo'), 'zoológico'), (('tourism', 'theme_park'), 'parque de diversiones'), (('tourism', 'information'), None),
    (('tourism', 'artwork'), 'monumento'), (('historic', 'monument'), 'monumento'), (('historic', 'memorial'), 'monumento'),
    (('historic', 'castle'), 'fortaleza'), (('historic', 'fort'), 'fortaleza'), (('historic', '*'), 'lugar histórico'),
    (('leisure', 'park'), 'parque'), (('leisure', 'garden'), 'parque'), (('leisure', 'playground'), 'parque infantil'),
    (('leisure', 'stadium'), 'estadio'), (('leisure', 'sports_centre'), 'deportes'), (('leisure', 'pitch'), 'cancha'),
    (('leisure', 'swimming_pool'), 'piscina'), (('leisure', 'fitness_centre'), 'gimnasio'), (('leisure', 'marina'), 'marina'),
    (('leisure', 'beach_resort'), 'playa'), (('natural', 'beach'), 'playa'), (('place', 'square'), 'plaza'),
    (('highway', 'pedestrian'), None), (('office', '*'), 'oficina'), (('aeroway', 'aerodrome'), 'aeropuerto'),
    (('aeroway', 'terminal'), 'aeropuerto'), (('railway', 'station'), 'estación'), (('building', 'hospital'), 'hospital'),
    (('building', 'government'), 'edificio'), (('building', 'public'), 'edificio'),
    (('building', 'school'), 'escuela'), (('building', 'church'), 'iglesia'), (('building', 'cathedral'), 'iglesia'),
    (('building', 'hotel'), 'hotel'), (('building', 'university'), 'universidad'), (('building', 'stadium'), 'estadio'),
    (('building', '*'), 'edificio'),  # con nombre: «Capitolio Nacional» (building=government), «Edificio FOCSA»
]


def tipo_de(tags):
    for (k, v), t in TIPOS:
        val = tags.get(k)
        if val and (v == '*' or val == v):
            return t, True
    return None, False


GRANDES = {'edificio', 'hospital', 'universidad', 'museo', 'hotel', 'teatro', 'estadio', 'parque', 'heladería', 'terminal',
           'aeropuerto', 'estación', 'centro comercial', 'mercado', 'iglesia', 'fortaleza', 'plaza', 'policlínico', 'cine'}
POCOS = {'casa particular', 'oficina', 'taller', 'consultorio'}


def importancia(t, tp, area=False):
    """0–4: con Wikidata/Wikipedia +2, tipos grandes (hospital, heladería…) +1, dibujado como área (edificio, recinto:
    Coppelia, Calixto García) +1; casas particulares, oficinas y talleres 0."""
    if tp in POCOS:
        return 0
    return (2 if t.get('wikidata') or t.get('wikipedia') else 0) + (1 if tp in GRANDES else 0) + (1 if area else 0)


class Lector(osmium.SimpleHandler):
    def __init__(self):
        super().__init__()
        self.ways = []          # [names, [node ids], [coords], highway]
        self.barrios = []       # [norm, lat, lon]
        self.lugares = []       # [nombre, tipo, lat, lon, osm id]
        self.municipios = []    # [nombre, geom preparada, geom]
        self.adm = []           # [nombre, admin_level, lat, lon] (centros para «p», como v1)
        self.wkb = osmium.geom.WKBFactory()

    def node(self, n):
        t = n.tags
        if not t.get('name') and not t.get('alt_name'):
            return
        if t.get('place') in BARRIO_PLACES:
            for k2, _, _ in names({'name': t.get('name'), 'alt_name': t.get('alt_name')}):
                self.barrios.append([k2, r5(n.location.lat), r5(n.location.lon)])
        if t.get('name'):
            tp, hit = tipo_de(t)
            if tp:
                self.lugares.append([t.get('name'), tp, n.location.lat, n.location.lon, 'n%d' % n.id, importancia(t, tp)])

    def way(self, w):
        t = w.tags
        if t.get('name') and not t.get('highway'):
            tp, hit = tipo_de(t)
            if tp:  # el centro de sus puntos (el armado de áreas salta algunos: «Calixto García» tiene barrier=fence)
                ll = [(nd.location.lat, nd.location.lon) for nd in w.nodes if nd.location.valid()]
                if ll:
                    la = sum(p[0] for p in ll) / len(ll); lo = sum(p[1] for p in ll) / len(ll)
                    self.lugares.append([t.get('name'), tp, la, lo, 'w%d' % w.id, importancia(t, tp, True)])
        hw = t.get('highway')
        if not hw or hw in SKIP_HW or t.get('area') == 'yes':
            return
        ns = names(t)
        if not ns:
            return
        ids, ll = [], []
        for nd in w.nodes:
            if not nd.location.valid():
                continue
            ids.append(nd.ref)
            ll.append((nd.location.lat, nd.location.lon))
        if len(ids) >= 2:
            self.ways.append([ns, ids, ll, hw])

    def area(self, a):
        t = a.tags
        if not t.get('name'):
            return
        lvl = t.get('admin_level')
        if t.get('boundary') == 'administrative' and lvl in ('6', '7', '8', '9', '10'):
            try:
                g = shp_wkb.loads(self.wkb.create_multipolygon(a), hex=True)
            except Exception:
                return
            c = g.representative_point()
            self.adm.append([t.get('name'), lvl, c.y, c.x])
            if lvl == '6':
                self.municipios.append([t.get('name'), prep(g), g])
            return
        tp, hit = tipo_de(t)
        if not tp or a.from_way():  # las vías ya salen en way()
            return
        try:
            g = shp_wkb.loads(self.wkb.create_multipolygon(a), hex=True)
        except Exception:
            return
        c = g.representative_point()
        oid = ('w%d' if a.from_way() else 'r%d') % a.orig_id()
        self.lugares.append([t.get('name'), tp, c.y, c.x, oid, importancia(t, tp, True)])


# ---------------------------------------------------------------- construcción
def build(pbf, v1=None):
    L = Lector()
    L.apply_file(pbf, locations=True, idx='flex_mem')
    print('vías con nombre %d · barrios %d · lugares crudos %d · municipios %d' % (len(L.ways), len(L.barrios), len(L.lugares), len(L.municipios)), file=sys.stderr)

    # Índice de nombres: los de v1 conservan su número (el Core guarda índices solo dentro de la petición, pero así se compara mejor).
    lista, idx = [], {}
    if v1:
        for n in v1['n']:
            idx[n] = len(lista); lista.append(n)

    def nid(n):
        if n not in idx:
            idx[n] = len(lista); lista.append(n)
        return idx[n]

    raw = defaultdict(Counter)   # i → nombres crudos
    pri = set()
    par = set()                  # «Línea (9)»: (9, línea) → donde() dice «Línea», no «9»
    W = []                       # [set ids, node ids, coords]
    for ns, ids, ll, hw in L.ways:
        s = []
        for k2, crudo, principal in ns:
            i = nid(k2); s.append(i); raw[i][crudo] += len(ll)
            if principal:
                pri.add(i)
        W.append([set(s), ids, ll])
        m = re.match(r'^(.*?)\s*\((.*)\)\s*$', str(ws_name(ns)))
        if m and norm(m.group(1)) in idx and norm(m.group(2)) in idx and norm(m.group(1)) != norm(m.group(2)):
            par.add('%d,%d' % (idx[norm(m.group(2))], idx[norm(m.group(1))]))

    # Cuántos «brazos» tiene cada calle en cada punto: 1 si el tramo termina ahí, 2 si pasa por él.
    en_nodo = defaultdict(list)  # nodo → [(way, posición)]
    coord = {}
    for wi, (s, ids, ll) in enumerate(W):
        for k, nd in enumerate(ids):
            en_nodo[nd].append((wi, k))
            coord[nd] = ll[k]

    x = defaultdict(list)
    xs = defaultdict(list)

    def push(arr, p, mn):
        for k in range(0, len(arr), 2):
            if meters(arr[k], arr[k + 1], p[0], p[1]) < mn:
                return
        arr += [r5(p[0]), r5(p[1])]

    def corner(a, b, p):
        if a == b:
            return
        push(x['%d,%d' % (min(a, b), max(a, b))], p, 40)

    seguidas = 0
    for nd, lst in en_nodo.items():
        if len(lst) < 2:
            continue
        p = coord[nd]
        # Una calle que solo cambia de nombre («23» sigue como «Rampa»): dos tramos que terminan ahí, casi en línea recta.
        if len(lst) == 2:
            (w1, k1), (w2, k2) = lst
            e1 = k1 in (0, len(W[w1][1]) - 1); e2 = k2 in (0, len(W[w2][1]) - 1)
            if e1 and e2 and not (W[w1][0] & W[w2][0]):
                q1 = W[w1][2][1 if k1 == 0 else -2]; q2 = W[w2][2][1 if k2 == 0 else -2]
                giro = abs((angle(p, q1) - angle(p, q2) + math.pi) % (2 * math.pi) - math.pi)  # π = recto
                if giro > math.radians(140):  # sigue en x (para «23 e/ Rampa y …» como v1) y en xs (donde() no la usa)
                    seguidas += 1
                    for a in W[w1][0]:
                        for b in W[w2][0]:
                            corner(a, b, p)
                            push(xs['%d,%d' % (min(a, b), max(a, b))], p, 40)
                    continue
        for u in range(len(lst)):
            for v in range(u + 1, len(lst)):
                sa, sb = W[lst[u][0]][0], W[lst[v][0]][0]
                if sa & sb:  # el mismo nombre en los dos tramos: es la misma calle
                    continue
                for a in sa:
                    for b in sb:
                        corner(a, b, p)

    # Calles que en el mapa llegan a la otra sin compartir el punto (pasa: «160A» con «57»): la punta suelta de una a
    # ≤ 20 m de un tramo de la otra también es esquina.
    G = 0.0005
    grid = defaultdict(list)
    for wi, (s, ids, ll) in enumerate(W):
        for k in range(len(ll) - 1):
            a, b = ll[k], ll[k + 1]
            for la in range(math.floor(min(a[0], b[0]) / G), math.floor(max(a[0], b[0]) / G) + 1):
                for lo in range(math.floor(min(a[1], b[1]) / G), math.floor(max(a[1], b[1]) / G) + 1):
                    grid[(la, lo)].append((a, b, wi))
    sueltas = 0
    for wi, (s, ids, ll) in enumerate(W):
        for end in (0, len(ids) - 1):
            if len(en_nodo[ids[end]]) > 1:
                continue
            p = ll[end]
            cla, clo = math.floor(p[0] / G), math.floor(p[1] / G)
            for la in range(cla - 1, cla + 2):
                for lo in range(clo - 1, clo + 2):
                    for a, b, wj in grid.get((la, lo), ()):
                        if wj == wi or (W[wj][0] & s):
                            continue
                        ax, ay = xy(a, p); bx, by = xy(b, p)
                        dx, dy = bx - ax, by - ay
                        LL = dx * dx + dy * dy
                        t = max(0, min(1, (-ax * dx - ay * dy) / LL)) if LL else 0
                        if math.hypot(ax + t * dx, ay + t * dy) > 20:
                            continue
                        sueltas += 1
                        for n1 in s:
                            for n2 in W[wj][0]:
                                corner(n1, n2, p)
    print('esquinas: %d pares · %d cambios de nombre no cuentan · %d puntas sueltas unidas' % (len(x), seguidas, sueltas), file=sys.stderr)

    # Tramos seguidos de cada calle (unir los tramos que se tocan en la punta) → g; puntos cada ~120 m → s.
    por_calle = defaultdict(list)
    for wi, (s, ids, ll) in enumerate(W):
        for i in s:
            por_calle[i].append(wi)
    g, sp, cel = {}, {}, defaultdict(set)
    GC = 0.005
    cadenas = {}  # i → [[(lat, lon)…]…] sin simplificar (para las direcciones de los lugares)
    for i, wis in por_calle.items():
        segs = [list(zip(W[w][1], W[w][2])) for w in wis]
        chains = []
        usado = [False] * len(segs)
        ends = defaultdict(list)
        for k, sg in enumerate(segs):
            ends[sg[0][0]].append(k); ends[sg[-1][0]].append(k)
        for k in range(len(segs)):
            if usado[k]:
                continue
            usado[k] = True
            ch = list(segs[k])
            for lado in (1, 0):  # alargar por la cola y luego por la cabeza
                while True:
                    tip = ch[-1][0] if lado else ch[0][0]
                    nxt = next((m for m in ends[tip] if not usado[m]), None)
                    if nxt is None:
                        break
                    usado[nxt] = True
                    sg = segs[nxt]
                    if lado:
                        ch += (sg if sg[0][0] == tip else sg[::-1])[1:]
                    else:
                        ch = (sg if sg[-1][0] == tip else sg[::-1])[:-1] + ch
            chains.append([p for _, p in ch])
        cadenas[i] = chains
        g[str(i)] = [encode([(r5(a), r5(b)) for a, b in dp(c)]) for c in chains]
        pts = []
        for c in chains:
            acc = 1e9
            for k, p in enumerate(c):
                if k:
                    acc += meters(c[k - 1][0], c[k - 1][1], p[0], p[1])
                if acc >= 100 or k == len(c) - 1:
                    push(pts, p, 120); acc = 0
                cel['%d:%d' % (math.floor(p[0] / GC), math.floor(p[1] / GC))].add(i)
            for k in range(len(c) - 1):  # tramos largos sin puntos en medio también cuentan en las celdas que cruzan
                a, b = c[k], c[k + 1]
                n = int(meters(a[0], a[1], b[0], b[1]) // 200)
                for m in range(1, n + 1):
                    f = m / (n + 1)
                    cel['%d:%d' % (math.floor((a[0] + f * (b[0] - a[0])) / GC), math.floor((a[1] + f * (b[1] - a[1])) / GC))].add(i)
        sp[str(i)] = pts

    # p: barrios (nodos place) + centros de municipios y consejos, como v1.
    p = []
    vistos = set()
    for b in L.barrios:
        key = (b[0], round(b[1], 3), round(b[2], 3))
        if key not in vistos:
            vistos.add(key); p.append(b)
    for nombre, lvl, la, lo in L.adm:
        for k2, _, _ in names({'name': nombre}):
            p.append([k2, r5(la), r5(lo)])

    d = [''] * len(lista)
    for i, cnt in raw.items():
        d[i] = cnt.most_common(1)[0][0]
    alt = sorted(i for i in raw if i not in pri)
    calles = {'v': 2, 'built': date.today().isoformat(), 'fuente': '© OpenStreetMap (ODbL)', 'n': lista, 'd': d,
              'x': dict(x), 'xs': dict(xs), 's': sp, 'p': p, 'g': g, 'c': {k: sorted(v) for k, v in cel.items()}, 'alt': alt,
              'par': sorted(par)}
    return calles, L, cadenas


# ---------------------------------------------------------------- dirección cubana de un punto (igual que el PHP nuevo)
CODIGO = re.compile(r'^(\d+(?:ra|da|ta|ma|na|va|ro|do|to|mo|no|vo|era|ero)?|[A-Za-zÑñ]{1,2})(?:\s?[A-Za-z])?$')


def corto(nm):
    for pat in (r'^(?:calle|avenida|ave\.?|av\.?)\s+(.+)$', r'^(.+?)\s+(?:calle|avenida)$'):
        m = re.match(pat, nm, re.I)
        if m and CODIGO.match(m.group(1)):
            return m.group(1)
    return nm


def orden(a, b):
    """«e/ M y L» → «e/ L y M», «e/ 12 y 10» → «e/ 10 y 12» (como se dice); con nombres, el más cercano primero."""
    if CODIGO.match(a) and CODIGO.match(b):
        ka = (0, int(re.match(r'\d+', a).group()), a) if a[0].isdigit() else (1, 0, a.lower())
        kb = (0, int(re.match(r'\d+', b).group()), b) if b[0].isdigit() else (1, 0, b.lower())
        if kb < ka:
            return b, a
    return a, b


def proyectar_k(P, chain):
    """Como proyectar(), y además el número del tramo donde cae."""
    best = (1e18, 0.0, 0)
    acc = 0.0
    for k in range(len(chain) - 1):
        a, b = xy(chain[k], P), xy(chain[k + 1], P)
        dx, dy = b[0] - a[0], b[1] - a[1]
        LL = dx * dx + dy * dy
        t = max(0, min(1, (-a[0] * dx - a[1] * dy) / LL)) if LL else 0
        dd = math.hypot(a[0] + t * dx, a[1] + t * dy)
        seg = math.sqrt(LL)
        if dd < best[0]:
            best = (dd, acc + t * seg, k)
        acc += seg
    return best


def largo(chain):
    return sum(meters(chain[k][0], chain[k][1], chain[k + 1][0], chain[k + 1][1]) for k in range(len(chain) - 1))


def punto_en(chain, s):
    """El punto a s metros del principio del tramo."""
    acc = 0.0
    for k in range(len(chain) - 1):
        seg = meters(chain[k][0], chain[k][1], chain[k + 1][0], chain[k + 1][1])
        if acc + seg >= s and seg:
            f = (s - acc) / seg
            return (chain[k][0] + f * (chain[k + 1][0] - chain[k][0]), chain[k][1] + f * (chain[k + 1][1] - chain[k][1]))
        acc += seg
    return chain[-1]


def rumbo_en(chain, pos, P):
    """Hacia dónde va la calle en pos: de 30 m antes a 30 m después (un segmento corto engaña)."""
    a, b = xy(punto_en(chain, max(0.0, pos - 30)), P), xy(punto_en(chain, pos + 30), P)
    L = math.hypot(b[0] - a[0], b[1] - a[1]) or 1
    return ((b[0] - a[0]) / L, (b[1] - a[1]) / L)


def tangente(chain, k, P):
    a, b = xy(chain[k], P), xy(chain[min(k + 1, len(chain) - 1)], P)
    L = math.hypot(b[0] - a[0], b[1] - a[1]) or 1
    return ((b[0] - a[0]) / L, (b[1] - a[1]) / L)


def proyectar(P, chain):
    """→ (distancia en m, posición a lo largo del tramo en m) del punto P sobre la línea chain."""
    best = (1e18, 0.0)
    acc = 0.0
    for k in range(len(chain) - 1):
        a, b = xy(chain[k], P), xy(chain[k + 1], P)
        dx, dy = b[0] - a[0], b[1] - a[1]
        LL = dx * dx + dy * dy
        t = max(0, min(1, (-a[0] * dx - a[1] * dy) / LL)) if LL else 0
        dd = math.hypot(a[0] + t * dx, a[1] + t * dy)
        seg = math.sqrt(LL)
        if dd < best[0]:
            best = (dd, acc + t * seg)
        acc += seg
    return best


class Direcciones:
    def __init__(self, calles, cadenas):
        self.c = calles
        self.cad = cadenas
        self.pc = defaultdict(list)  # i → [(j, lat, lon)]
        for k, v in calles['x'].items():
            a, b = map(int, k.split(','))
            for m in range(0, len(v), 2):
                self.pc[a].append((b, v[m], v[m + 1])); self.pc[b].append((a, v[m], v[m + 1]))
        self.alt = set(calles['alt'])
        self.par = set(calles.get('par', []))
        self.xs = {(r5(v[m]), r5(v[m + 1])) for v in calles['xs'].values() for m in range(0, len(v), 2)}

    def nombre(self, i):
        return self.c['d'][i] or self.c['n'][i]

    def corto(self, i):
        """La entrecalle como se dice en Cuba: «Calle L» → «L», «Avenida 25» → «25», «5ta Avenida» → «5ta»."""
        return corto(self.nombre(i))

    def cerca(self, P, radio=150):
        GC = 0.005
        cla, clo = math.floor(P[0] / GC), math.floor(P[1] / GC)
        cand = set()
        for la in (cla - 1, cla, cla + 1):
            for lo in (clo - 1, clo, clo + 1):
                cand.update(self.c['c'].get('%d:%d' % (la, lo), ()))
        out = []
        for i in cand:
            best = None
            for ch in self.cad[i]:
                dd, pos = proyectar(P, ch)
                if best is None or dd < best[0]:
                    best = (dd, pos, ch)
            if best and best[0] <= radio:
                out.append((best[0], i, best[1], best[2]))
        out.sort(key=lambda r: (r[0], r[1] in self.alt))
        return out

    def donde(self, P):
        cs = self.cerca(P)
        if not cs:
            return None
        dist, main, pos, ch = cs[0]
        for dd, i, ps, c in cs[1:]:  # el mismo tramo con dos nombres: «Línea (9)» → Línea
            if dd > dist + 1.5:
                break
            if '%d,%d' % (main, i) in self.par:
                dist, main, pos, ch = dd, i, ps, c
                break
        # Referencia: el trozo largo (≥ 80 m) más cercano, no un pasito que cruza el parque de en medio (Presidentes).
        largos = [(proyectar(P, c), c) for c in self.cad[main] if largo(c) >= 80]
        largos = [(pr, c) for pr, c in largos if pr[0] <= dist + 25]
        if largos:
            (dist2, pos), ch = min(largos, key=lambda r: r[0][0])
        u0 = rumbo_en(ch, pos, P)
        otros = [c for c in self.cad[main] if c is not ch and proyectar(P, c)[0] <= dist + 60]  # otro carril (Paseo)
        lados = {-1: None, 1: None}
        for j, la, lo in self.pc[main]:
            if (la, lo) in self.xs or meters(P[0], P[1], la, lo) > 900:
                continue
            d0, pc = proyectar((la, lo), ch)
            if d0 <= 30:
                t = pc - pos  # a lo largo de la calle
            elif any(proyectar((la, lo), c)[0] <= 30 for c in otros):
                ex, ey = xy((la, lo), P)
                t = ex * u0[0] + ey * u0[1]  # en el otro carril: según hacia dónde va la calle aquí
            else:
                continue
            lado = -1 if t < 0 else 1
            cur = lados[lado]
            peor = j in self.alt or (cur is not None and '%d,%d' % (j, cur[0]) in self.par)
            mejor_que_cur = cur is not None and (cur[2] or '%d,%d' % (cur[0], j) in self.par)
            if cur is None or abs(t) < cur[1] - 8 or (abs(t) < cur[1] + 8 and mejor_que_cur and not peor):
                lados[lado] = (j, abs(t), peor)
        a, b = lados[-1], lados[1]
        if a and b and b[1] < a[1]:
            a, b = b, a
        if not a and b:
            a, b = b, None
        nm = self.nombre(main)
        if a and a[1] < 15:
            return nm + ' esq. ' + self.corto(a[0])
        if a and b:
            x, y = orden(self.corto(a[0]), self.corto(b[0]))
            return nm + ' e/ ' + x + ' y ' + y
        if a:
            return nm + ' cerca de ' + self.corto(a[0])
        return nm


def lugares(L, calles, cadenas):
    D = Direcciones(calles, cadenas)
    barrios = [b for b in L.barrios]
    out, vistos = [], set()
    for nombre, tipo, la, lo, oid, imp in L.lugares:
        nombre = nombre.strip()
        if len(nombre) < 3 or len(clave(nombre)) < 3:  # sin letras latinas («요반나») no se puede buscar
            continue
        key = (clave(nombre), round(la, 3), round(lo, 3))
        if key in vistos:
            continue
        vistos.add(key)
        pt = Point(lo, la)
        muni = next((m[0] for m in L.municipios if m[1].contains(pt)), '')
        if not muni:
            continue  # fuera de la provincia
        dirc = D.donde((la, lo)) or ''
        bb, bd = '', 1e18
        for b in barrios:
            dd = (b[1] - la) ** 2 + ((b[2] - lo) * KX) ** 2
            if dd < bd:
                bd, bb = dd, b[0]
        out.append([nombre, tipo, r5(la), r5(lo), dirc, bb.title() if bb else '', muni, clave(nombre), imp])
    out.sort(key=lambda r: r[7])
    return out


if __name__ == '__main__':
    if len(sys.argv) < 4:
        sys.exit('uso: construir_v2.py habana.osm.pbf habana-calles-v2.json habana-lugares.json [habana-calles-v1.json]')
    v1 = json.load(open(sys.argv[4])) if len(sys.argv) > 4 else None
    calles, L, cadenas = build(sys.argv[1], v1)
    with open(sys.argv[2], 'w') as f:
        json.dump(calles, f, ensure_ascii=False, separators=(',', ':'))
    lg = lugares(L, calles, cadenas)
    with open(sys.argv[3], 'w') as f:
        json.dump({'v': 1, 'built': calles['built'], 'fuente': '© OpenStreetMap (ODbL)',
                   'campos': ['nombre', 'tipo', 'lat', 'lon', 'direccion', 'barrio', 'municipio', 'clave', 'importancia'], 'l': lg},
                  f, ensure_ascii=False, separators=(',', ':'))
    print('calles %d · esquinas %d · barrios %d · lugares %d' % (len(calles['n']), len(calles['x']), len(calles['p']), len(lg)), file=sys.stderr)
