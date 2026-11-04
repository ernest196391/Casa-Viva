#!/usr/bin/env python3
"""
Triciclub · verificador de choferes (Core 3.14.132, Ernesto 4-nov: «la selfie puede ser de cualquiera y aceptó fotos que
no eran de triciclo: hay que pedir el carné y verificar solo, de forma inteligente»). Como QvaPay o los bancos.

Cada minuto (cron + flock, ver LEEME.md):
  1. GET  /bot/triciclub/verificar      → los choferes que mandaron su alta (carné, selfie con el carné, triciclo).
  2. Caras (gratis, en este VPS, OpenCV YuNet + SFace): la cara de la selfie contra la del carné.
  3. IA de visión (gpt-4.1, ~1–2 centavos USD por chofer, queda en /root/archivos/gastos): ¿es un carné cubano legible?
     ¿la selfie es una persona de verdad con el carné en la mano, no una foto de pantalla? ¿el triciclo es un triciclo
     del tipo que eligió? Lee el nombre y el número del carné (de ahí sale la edad).
  4. POST /bot/triciclub/verificar/{id} → aprobado (con la foto de perfil recortada de la selfie) · corregir (el chofer
     ve qué arreglar) · revision (decide Ernesto en /triciclub/admin: caras dudosas, menor de edad, foto de pantalla…).
Las fotos no se guardan aquí: se bajan a memoria. El registro (registro.log) no lleva nombres ni números.
"""
import base64
import datetime
import json
import os
import re
import sys
import time
import unicodedata
import urllib.request
from zoneinfo import ZoneInfo

cv2 = np = None  # OpenCV y numpy se importan solo si hay alguien que comprobar (cargar_modelos)

AQUI = os.path.dirname(os.path.abspath(__file__))
MODELO = os.environ.get('TC_VERIF_MODELO', 'gpt-4.1')
PRECIOS = {'gpt-4.1': (2.0, 8.0), 'gpt-4.1-mini': (0.4, 1.6)}  # USD por millón de tokens (vivabot/src/ai-prices.js)
CARA_OK = 0.363  # SFace, coseno: misma persona a partir de aquí (valor de OpenCV)
CARA_MIN = 0.20  # por debajo, ni con el sí de la IA: la foto del carné es vieja y pequeña, pero tanto no
INTENTOS = os.path.join(AQUI, 'intentos.json')
HABANA = ZoneInfo('America/Havana')


def leer_env(path='/root/vivabot/.env'):
    e = {}
    for linea in open(path, encoding='utf-8'):
        m = re.match(r'^\s*([A-Z_]+)\s*=\s*(.*)\s*$', linea)
        if m:
            e[m.group(1)] = m.group(2).strip().strip('"').strip("'")
    return e


ENV = leer_env()
WEB = ENV['WOO_URL'].rstrip('/')


def registro(texto):
    print(datetime.datetime.now(HABANA).strftime('%d/%m %H:%M:%S'), texto, flush=True)


def core(path, body=None):
    # La CDN de Hostinger guarda los GET aunque Core diga no-store: cada consulta lleva una marca única.
    url = WEB + '/wp-json/casa-viva/v1' + path + ('' if body is not None else ('&' if '?' in path else '?') + '_=' + str(int(time.time() * 1000)))
    req = urllib.request.Request(url, data=None if body is None else json.dumps(body).encode(), method='GET' if body is None else 'POST',
                                 headers={'Content-Type': 'application/json', 'X-VivaBot-Key': ENV['CURRU_KEY'], 'User-Agent': 'triciclub-verifica'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())


def bajar(url):
    if not url:
        return None
    req = urllib.request.Request(url + '&_=' + str(int(time.time() * 1000)), headers={'User-Agent': 'triciclub-verifica'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read()


# ------------------------------------------------------------------------------------------------ caras (OpenCV, gratis)
DET = REC = None  # se cargan solo si hay alguien que comprobar: cada minuto sin choferes no gasta nada


def cargar_modelos():
    global DET, REC, cv2, np
    if DET is None:
        import cv2 as _cv2
        import numpy as _np
        cv2, np = _cv2, _np
        DET = cv2.FaceDetectorYN.create(os.path.join(AQUI, 'modelos/yunet.onnx'), '', (320, 320), 0.75, 0.3, 5000)
        REC = cv2.FaceRecognizerSF.create(os.path.join(AQUI, 'modelos/sface.onnx'), '')


def imagen(datos):
    return cv2.imdecode(np.frombuffer(datos, np.uint8), cv2.IMREAD_COLOR) if datos else None


def caras(img):
    """Las caras de la foto, la más grande primero, con la foto en el giro donde la cara sale más clara (el carné o la
    selfie a menudo van de lado, y de lado YuNet ve «caras» falsas con poca seguridad)."""
    mejor = (0.0, img, [])
    for giro in (None, cv2.ROTATE_90_CLOCKWISE, cv2.ROTATE_90_COUNTERCLOCKWISE, cv2.ROTATE_180):
        im = img if giro is None else cv2.rotate(img, giro)
        h, w = im.shape[:2]
        DET.setInputSize((w, h))
        _, f = DET.detect(im)
        if f is None or not len(f):
            continue
        f = sorted(list(f), key=lambda x: -x[2] * x[3])
        if float(f[0][14]) > mejor[0]:
            mejor = (float(f[0][14]), im, f)
    return mejor[1], mejor[2]


def rasgo(img, cara):
    return REC.feature(REC.alignCrop(img, cara))


def parecido(a, b):
    return float(REC.match(a, b, cv2.FaceRecognizerSF_FR_COSINE))


def recorte_perfil(img, cara):
    """La cara de la selfie con algo de margen, cuadrada, 480 px → JPEG en base64 (su foto para los clientes)."""
    x, y, w, h = [float(v) for v in cara[:4]]
    lado = max(w, h) * 1.9
    cx, cy = x + w / 2, y + h / 2.2
    H, W = img.shape[:2]
    x0, y0 = int(max(0, cx - lado / 2)), int(max(0, cy - lado / 2))
    x1, y1 = int(min(W, cx + lado / 2)), int(min(H, cy + lado / 2))
    rec = cv2.resize(img[y0:y1, x0:x1], (480, 480), interpolation=cv2.INTER_AREA)
    ok, buf = cv2.imencode('.jpg', rec, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return base64.b64encode(buf.tobytes()).decode() if ok else ''


# ------------------------------------------------------------------------------------------------ IA de visión
TIPOS_VISTOS = 'bicitaxi (pedal) | triciclo_electrico | triciclo_electrico_grande (6-8 personas) | triciclo_gasolina | moto | triciclo_carga | carro | otro'


def ia(fotos, chofer):
    def img(datos, detalle):
        return {'type': 'image_url', 'image_url': {'url': 'data:image/jpeg;base64,' + base64.b64encode(datos).decode(), 'detail': detalle}}
    texto = (
        'Eres el verificador de choferes de Triciclub (app de triciclos en La Habana). Te mando 3 fotos de un chofer que se quiere '
        'dar de alta: 1) su carné de identidad cubano por delante, 2) una selfie de su cara (puede venir en espejo), 3) su vehículo. '
        f'Dice que su vehículo es «{chofer["tipo_nombre"]}» (tipo interno {chofer["tipo"]}, motor {chofer["motor"] or "-"}, '
        f'servicios {", ".join(chofer["servicios"])}). Contesta SOLO con este JSON, sin inventar nada que no se vea:\n'
        '{"carne": {"es_carne_cubano": bool, "legible": bool, "foto_de_pantalla_o_fotocopia": bool, "nombre": "nombre y apellidos tal como se leen o \'\'", "numero": "los 11 dígitos o \'\'"},\n'
        ' "selfie": {"persona_real": bool, "foto_de_pantalla_o_impresa": bool, "misma_persona_que_el_carne": "si | no | dudoso"},\n'
        f' "vehiculo": {{"es_vehiculo_real": bool, "que_es": "{TIPOS_VISTOS}", "coincide_con_lo_que_dice": bool, "foto_de_pantalla_o_de_internet": bool}},\n'
        ' "nota": "una frase corta en español si algo no cuadra, o \'\'"}\n'
        'Criterios: un bicitaxi es de pedal; un triciclo eléctrico de pasaje lleva 3-4 personas y el grande 6-8; uno de carga tiene cajón o cama atrás. '
        '«coincide_con_lo_que_dice» es false solo si se ve claramente otro tipo (p. ej. dice eléctrico de pasaje y es un bicitaxi o un carro). '
        '«misma_persona_que_el_carne»: compara rasgos (forma de la cara, cejas, nariz, orejas), no la edad ni el peinado: la foto del carné puede tener años.'
    )
    body = {'model': MODELO, 'temperature': 0, 'max_tokens': 500, 'response_format': {'type': 'json_object'},
            'messages': [{'role': 'user', 'content': [{'type': 'text', 'text': texto}, img(fotos['carne'], 'high'), img(fotos['selfie'], 'low'), img(fotos['triciclo'], 'low')]}]}
    req = urllib.request.Request('https://api.openai.com/v1/chat/completions', data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + ENV['OPENAI_API_KEY']})
    with urllib.request.urlopen(req, timeout=120) as r:
        d = json.loads(r.read())
    u = d.get('usage', {})
    pin, pout = PRECIOS.get(MODELO, (2.0, 8.0))
    costo = (u.get('prompt_tokens', 0) * pin + u.get('completion_tokens', 0) * pout) / 1e6
    gasto(d.get('model', MODELO), u, costo)
    return json.loads(d['choices'][0]['message']['content']), costo


def gasto(modelo, u, costo):
    """Una línea en el registro de gastos de IA (D71), como las del bot."""
    ahora = datetime.datetime.now(datetime.timezone.utc)
    dia = ahora.astimezone(HABANA).strftime('%Y-%m-%d')
    linea = {'at': ahora.isoformat().replace('+00:00', 'Z'), 'day': dia, 'fn': 'triciclub-verifica', 'provider': 'openai', 'model': modelo, 'tier': 'verifica',
             'kind': 'imagen', 'input_tokens': u.get('prompt_tokens', 0), 'cached_tokens': (u.get('prompt_tokens_details') or {}).get('cached_tokens', 0),
             'cache_write_tokens': 0, 'output_tokens': u.get('completion_tokens', 0), 'images': 3, 'audio_seconds': 0, 'chars': 0,
             'cost_usd': round(costo, 6), 'ok': True, 'error': None}
    try:
        with open(f'/root/archivos/gastos/ia-{dia}.jsonl', 'a', encoding='utf-8') as f:
            f.write(json.dumps(linea) + '\n')
    except OSError:
        pass


# ------------------------------------------------------------------------------------------------ carné
def edad_de(numero):
    """Carné cubano: AAMMDD + 5 cifras; la 7.ª da el siglo (9 → 1800, 0–5 → 1900, 6–8 → 2000). → años o None."""
    if not re.fullmatch(r'\d{11}', numero or ''):
        return None
    aa, mm, dd, s = int(numero[0:2]), int(numero[2:4]), int(numero[4:6]), int(numero[6])
    siglo = 1800 if s == 9 else (1900 if s <= 5 else 2000)
    try:
        nac = datetime.date(siglo + aa, mm, dd)
    except ValueError:
        return None
    hoy = datetime.date.today()
    return hoy.year - nac.year - ((hoy.month, hoy.day) < (nac.month, nac.day))


def palabras(s):
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()
    return {p for p in re.findall(r'[a-z]{3,}', s)}


# ------------------------------------------------------------------------------------------------ un chofer
def verificar(ch):
    cargar_modelos()
    fotos ={k: bajar(v) for k, v in ch['fotos'].items() if k in ('carne', 'selfie', 'triciclo')}
    checks, corregir, revision = [], [], []

    def check(que, ok, nota='', arreglo='', dudas=''):
        checks.append({'que': que, 'ok': bool(ok), 'nota': nota})
        if not ok and arreglo:
            corregir.append(arreglo)
        if not ok and dudas:
            revision.append(dudas)

    falta = [k for k in ('carne', 'selfie', 'triciclo') if not fotos.get(k)]
    if falta:
        return {'resultado': 'corregir', 'motivo': 'Falta la foto: ' + ', '.join(falta) + '.', 'checks': [{'que': 'Fotos', 'ok': False, 'nota': 'faltan ' + ', '.join(falta)}]}

    # 1) caras
    im_c, caras_c = caras(imagen(fotos['carne']))
    im_s, caras_s = caras(imagen(fotos['selfie']))
    check('Cara en el carné', caras_c, '' if caras_c else 'no se ve la cara', arreglo='La foto del carné no se ve bien: sácala de nuevo por delante, con buena luz y sin reflejos.')
    check('Cara en la selfie', caras_s, '' if caras_s else 'no se ve una cara', arreglo='En la selfie tiene que verse bien tu cara, de frente y con buena luz.')
    similitud, perfil = None, ''
    if caras_c and caras_s:
        # La foto del carné es pequeña: al doble, YuNet pone mejor los ojos y la boca y SFace compara bien (prueba 4-nov
        # con Ernesto: 0,18 → 0,53). La selfie, también en espejo (la cámara delantera a veces la guarda así).
        carnes = [rasgo(im_c, caras_c[0])]
        im_c2, caras_c2 = caras(cv2.resize(im_c, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC))
        if caras_c2:
            carnes.append(rasgo(im_c2, caras_c2[0]))
        vivas = [rasgo(im_s, caras_s[0])]
        espejo, caras_e = caras(cv2.flip(im_s, 1))
        if caras_e:
            vivas.append(rasgo(espejo, caras_e[0]))
        similitud = max(parecido(a, b) for a in vivas for b in carnes)
        perfil = recorte_perfil(im_s, caras_s[0])

    # 2) IA: carné, selfie de verdad, triciclo, y segunda opinión de las caras
    v, costo = ia(fotos, ch)
    c, s, t = v.get('carne') or {}, v.get('selfie') or {}, v.get('vehiculo') or {}
    if similitud is not None:  # OpenCV dice cuánto se parecen; si la foto del carné es vieja, la IA desempata
        ia_misma = str(s.get('misma_persona_que_el_carne', '')).lower()
        misma = ia_misma != 'no' and (similitud >= CARA_OK or (similitud >= CARA_MIN and ia_misma == 'si'))
        check('Misma persona (selfie y carné)', misma, f'parecido {similitud:.2f} (desde {CARA_OK:.2f} es la misma) · la IA dice: {ia_misma or "-"}', dudas='las caras no se parecen lo suficiente')
    check('Carné cubano legible', c.get('es_carne_cubano') and c.get('legible'), '' if c.get('legible') else 'no se lee', arreglo='La foto del carné no se lee: sácala de nuevo por delante, que se lean el nombre y el número.')
    check('Carné de verdad (no foto de pantalla)', not c.get('foto_de_pantalla_o_fotocopia'), dudas='el carné parece una foto de pantalla o una fotocopia')
    check('Selfie de una persona', s.get('persona_real'), arreglo='En la selfie tiene que verse bien tu cara, de frente.')
    check('Selfie de verdad (no foto de pantalla)', not s.get('foto_de_pantalla_o_impresa'), dudas='la selfie parece una foto de pantalla o impresa')
    check('Foto de un vehículo de verdad', t.get('es_vehiculo_real') and not t.get('foto_de_pantalla_o_de_internet'), t.get('que_es', ''),
          arreglo='La foto del triciclo no muestra tu triciclo: sácale una foto de frente o de lado.')
    if t.get('es_vehiculo_real'):
        check(f'Es un {ch["tipo_nombre"]}', t.get('coincide_con_lo_que_dice'), 'se ve: ' + str(t.get('que_es', '')),
              arreglo=f'Tu foto parece un {str(t.get("que_es", "otro")).replace("_", " ")}, no un {ch["tipo_nombre"]}: elige el tipo correcto o cambia la foto.')
    numero = re.sub(r'\D', '', c.get('numero') or '')
    edad = edad_de(numero)
    if c.get('legible'):
        check('Número de carné válido', edad is not None, '' if edad is not None else 'no se pudo leer bien', dudas='el número del carné no se lee bien')
    if edad is not None:
        check('Mayor de 18', edad >= 18, f'{edad} años', dudas='es menor de edad')
    if c.get('nombre') and ch.get('nombre'):  # solo aviso: puede haber puesto un apodo
        checks.append({'que': 'Nombre igual que en el carné', 'ok': bool(palabras(c['nombre']) & palabras(ch['nombre'])), 'nota': 'puso «' + ch['nombre'] + '»'})
    if v.get('nota'):
        checks.append({'que': 'Nota de la IA', 'ok': not (corregir or revision), 'nota': str(v['nota'])[:180]})

    resultado = 'corregir' if corregir else ('revision' if revision else 'aprobado')
    return {'resultado': resultado, 'motivo': ' '.join(corregir[:2])[:200], 'checks': checks, 'similitud': similitud,
            'carne': {'numero': numero, 'nombre': c.get('nombre', ''), 'edad': edad}, 'costo_usd': costo,
            'perfil_jpg': perfil if resultado == 'aprobado' else ''}


def main():
    try:
        intentos = json.load(open(INTENTOS))
    except (OSError, ValueError):
        intentos = {}
    lista = core('/bot/triciclub/verificar').get('choferes', [])
    for ch in lista:
        cid = str(ch['id'])
        try:
            r = verificar(ch)
        except Exception as e:  # la IA o la red fallan: se reintenta el próximo minuto; a la 3.ª, que decida Ernesto
            intentos[cid] = intentos.get(cid, 0) + 1
            registro(f'#{cid} error ({intentos[cid]}): {type(e).__name__}: {str(e)[:120]}')
            if intentos[cid] < 3:
                continue
            r = {'resultado': 'revision', 'motivo': '', 'checks': [{'que': 'Comprobación automática', 'ok': False, 'nota': 'falló 3 veces: ' + type(e).__name__}]}
        res = core(f'/bot/triciclub/verificar/{cid}', r)
        intentos.pop(cid, None)
        sim = r.get('similitud')
        registro(f'#{cid} {r["resultado"]} → {res.get("estado")} · parecido {sim if sim is None else round(sim, 2)} · {sum(not c["ok"] for c in r["checks"])} fallos · {round(r.get("costo_usd") or 0, 4)} USD')
    json.dump(intentos, open(INTENTOS, 'w'))


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--prueba-caras':  # python3 verifica.py --prueba-caras a.jpg b.jpg
        cargar_modelos()
        a, ca =caras(cv2.imread(sys.argv[2]))
        b, cb = caras(cv2.imread(sys.argv[3]))
        print('caras', len(ca), len(cb), 'parecido', round(parecido(rasgo(a, ca[0]), rasgo(b, cb[0])), 3) if ca and cb else '-')
        sys.exit(0)
    main()
