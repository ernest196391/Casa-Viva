# Triciclub · verificador de choferes (VPS)

Comprueba solo a cada chofer nuevo: su carné, una selfie con el carné en la mano y la foto del triciclo (Core 3.14.132).

- Dónde: `/root/triciclub-verifica/` en el VPS (`verifica.py`, `modelos/yunet.onnx`, `modelos/sface.onnx`, `registro.log`).
- Cuándo: cada minuto, `/etc/cron.d/triciclub-verifica` con `flock` (nunca dos a la vez).
- Necesita: `python3-opencv` (apt), los modelos de OpenCV Zoo (YuNet 2022mar y SFace 2021dec) y en `/root/vivabot/.env`: `WOO_URL`, `CURRU_KEY` (llave del bot para Core) y `OPENAI_API_KEY`.
- Sin choferes esperando: 0,9 s y 23 MB por minuto (OpenCV solo se carga si hay alguien). Costo: caras gratis (OpenCV en el VPS); IA de visión `gpt-4.1` ≈ 1–2 centavos USD por chofer, anotado en `/root/archivos/gastos/ia-AAAA-MM-DD.jsonl` (`fn: triciclub-verifica`). Otro modelo: `TC_VERIF_MODELO=…`.
- Resultados: `aprobado` (y foto de perfil recortada de la selfie) · `corregir` (el chofer ve qué arreglar) · `revision` (decide Ernesto en `/triciclub/admin`).
- Probar dos caras: `python3 verifica.py --prueba-caras a.jpg b.jpg`.
- Apagar la verificación (vuelve a aprobar al momento): `wp option update cvd_tc_verificar no` en la web.
