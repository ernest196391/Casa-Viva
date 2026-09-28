# Casa Viva Platform — Checkpoint operativo

## Resumen de reanudación

```text
FASE: 0 — Casa Viva Core estable
SUBFASE: CV-RECOVERY-001 — Recuperar una línea base confiable
ESTADO: EN CURSO
MAIN AUDITADO: 5f802abbf8cedce060b11bd0a5884485071388e0
PRODUCCIÓN OBSERVADA: 7c5305c9dd2c6c1246354e6807282abfdbe9bc5f
DECISIÓN: NO-GO para baseline final; la tienda puede seguir operando con cautela
COSTO NUEVO NECESARIO: 0 USD para este subpaso
REQUIERE APROBACIÓN PARA DESPLEGAR: sí
```

## Qué estamos construyendo

Casa Viva será el primer comercio certificado de una plataforma multiempresa que después incorporará Todo Hogar, Estilo y Hogar y Dulce Hogar, Gestor multitienda, mensajería global y contabilidad offline para Windows.

No se deben iniciar esas fases hasta cerrar la Fase 0.

## Hallazgos activos

### P1 — Producción desincronizada

- `main` contiene cambios posteriores a producción.
- Las correcciones recientes del tarifario no están desplegadas.
- No desplegar hasta obtener un SHA candidato completamente certificado.

### P1 — Guard de estabilidad del mensajero

- `test-messenger-immediate-reload-guard.mjs` falla en el baseline auditado.
- El test no forma parte del workflow `validate.yml`.
- Riesgo: recarga inmediata/repetida del Centro del Mensajero.

### P2 — Documentación desactualizada

- `README.md` describe una base inicial y contradice el producto real.
- checkpoints 7D contienen SHA antiguos.

### P2 — Lanzamiento web

- cuatro imágenes de categorías rotas en portada;
- falta H1, description y canonical;
- título genérico;
- catálogo y accesos necesitan saneamiento.

## Plan de CV-RECOVERY-001

| Paso | Estado | Criterio de cierre |
| --- | --- | --- |
| 001.1 Crear Blueprint y checkpoint vivos | VALIDADO LOCALMENTE | Documentos presentes, enlazados y revisables en PR |
| 001.2 Corregir orden de carga del guard del mensajero | VALIDADO LOCALMENTE | Ambos contratos de recarga verdes |
| 001.3 Incorporar prueba omitida al CI | VALIDADO LOCALMENTE | Workflow ejecuta las dos regresiones |
| 001.4 Inventariar pruebas huérfanas | PENDIENTE | Toda prueba relevante está ejecutada o justificada |
| 001.5 Ejecutar validación completa | PENDIENTE | contratos, lint, typecheck, build, integración y browser verdes |
| 001.6 Fijar SHA candidato | PENDIENTE | SHA exacto documentado; sin despliegue todavía |

## Límites de esta subfase

- No desplegar producción.
- No cambiar tarifas ni reglas comerciales.
- No iniciar Foundation multiempresa.
- No crear nuevas tablas de plataforma.
- No comprar servicios.
- No alterar pedidos reales.

## Próxima acción

Publicar 001.1–001.3 en un PR pequeño y esperar CI remoto. Después inventariar pruebas huérfanas y completar integración/navegador antes de fijar el SHA candidato.

## Evidencia local de 001.1–001.3

- todas las pruebas estáticas Node: verdes;
- contratos de recarga del mensajero: verdes;
- ESLint: 0 errores y 77 advertencias legacy;
- TypeScript: verde;
- build Next.js: verde;
- integración WordPress/MariaDB y navegador: pendientes de CI/entorno completo.

## Cómo actualizar este archivo

Al cerrar un paso:

1. cambiar su estado;
2. registrar SHA/PR y pruebas;
3. actualizar hallazgos activos;
4. indicar costo nuevo, si existe;
5. dejar una única próxima acción;
6. no avanzar de fase sin aprobación.
