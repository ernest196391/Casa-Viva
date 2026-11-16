# EVO-LOCAL-001 — Proponedor local (Qwen en el equipo de Ernesto)

Estado (16-nov-2026): primer corte construido y probado. Sin cambios en producción.

## Qué es
`scripts/ops/local-proposer.py` convierte una tarea en una propuesta `cv-change-proposal-v1` con un modelo local (Ollama, `qwen2.5-coder:7b`). Es la pieza de **generación** de la cadena de [CONTROL_OS_PROPOSAL_CONTRACT.md](CONTROL_OS_PROPOSAL_CONTRACT.md): la propuesta entra después por el puente, el ejecutor aislado produce un diff y una persona aprueba.

## Qué garantiza
- Solo habla con el modelo por loopback (`127.0.0.1`, `localhost`, `::1`); otro host se rechaza.
- Solo lectura sobre el clon local; ignora symlinks, archivos de más de 12 000 caracteres y más de 6 archivos de contexto.
- La propuesta pasa por el validador oficial (`opencode-proposal.py`: rutas, `allow_paths`, rutas protegidas, secretos, solo `create_file`/`replace_file`/`text_replace`) y se comprueba contra los archivos reales (texto a reemplazar presente el número exacto de veces; archivo nuevo que no existe).
- No aplica, no entrega, no ejecuta nada ni usa credenciales (prueba estática por AST). El ejecutor, el puente y el Dev Worker no lo conocen.

## Uso
```
python scripts/ops/local-proposer.py --task tarea.json --repo <clon-casa-viva> --out <carpeta>
```
`tarea.json`: `run_id`, `task_id`, `title`, `request` (≤ 2000 caracteres), `allow_paths`. Salidas: 0 escrita · 2 uso · 3 contrato · 6 ruta · 7 modelo apagado · 8 respuesta inválida.

## Evidencia
- 18 pruebas (`scripts/ops/tests/local-proposer.test.py`, cableadas en `validate.yml`): 17 pasan en Windows y 1 (symlinks) se omite allí.
- Prueba real con Qwen sobre un archivo de ejemplo en una carpeta temporal: propuesta válida en 57 s (CPU), original intacto.
- En Windows el validador se importa con módulos POSIX vacíos (`fcntl`, `resource`, `pwd`, `grp`) solo para poder cargarlo; nunca se ejecuta el ejecutor aquí. En el VPS y en CI son los módulos reales.

## Límites y siguiente paso
- Qwen 7B en CPU es lento y puede proponer cambios malos; el validador y la aplicabilidad los rechazan, pero la calidad es la de un modelo pequeño.
- Falta **recoger tareas** del Control OS y **entregar** la propuesta al puente del VPS. Recomendado: el equipo lee los paquetes `waiting_ai` por el canal SSH ya existente (sin copiar claves de Supabase al portátil) y entrega la propuesta firmada por el puente con aprobación humana. No se construye hasta que Ernesto lo apruebe.
- El ejecutor aislado (Fase B) aún no corre como servicio en el VPS y el sandbox (Fase E) sigue sin instalar.
