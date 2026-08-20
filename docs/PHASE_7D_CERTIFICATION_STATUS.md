# Casa Viva — Fase 7D: estado de certificación

## Baseline de entrada

- `main` de entrada a 7D: `96578598fdb1f0491ca28c4dd1653ccc2258b29d`.
- 7C queda cerrado funcionalmente con sus incrementos 7C.1, 7C.2 y 7C.3 integrados en `main`.
- La matriz oficial de certificación está en `docs/PHASE_7D_E2E_CERTIFICATION.md`.
- No iniciar implementación funcional de Casa Viva Network durante 7D.

## Estado por clasificación

### CURRENT

- WooCommerce sigue siendo la fuente oficial de pedidos y stock.
- Pedidos, eventos, transiciones, logística, inventario, comisiones y payouts conservan sus servicios canónicos existentes.
- Release reproducible, manifest, checksum, despliegue controlado por SHA, smoke y rollback ya existen desde Bloque 06.

### NEXT

Ejecutar y documentar la certificación E2E sobre el candidato real de lanzamiento:

1. cliente → compra → confirmación → pedidos → seguimiento;
2. atribución y privacidad de gestora;
3. operación/dependienta;
4. mensajero → aceptación → custodia → ruta → entrega;
5. administración;
6. comisión/payout;
7. separación de permisos y privacidad;
8. CI completo verde;
9. release reproducible desde SHA exacto;
10. despliegue, smoke y rollback.

### PREPARE

- Mantener compatibilidad futura con multirol sin reconstruir identidad ahora.
- Mantener fuentes de verdad únicas y fronteras auditables.
- Evitar acoplar nuevas decisiones de lanzamiento a un proveedor único de geolocalización o a conectividad permanente.

### FUTURE

Casa Viva Network y su Bloque 08 permanecen fuera del alcance de 7D.

## Criterio de salida

7D solo se cierra cuando exista evidencia reproducible de la matriz completa y resultado `GO` o `NO-GO`.

Para `GO` deben quedar, como mínimo:

- `validate`, `integration` y `browser` en verde;
- fundación de release y contrato de staging/deploy en verde;
- SHA desplegado igual al SHA certificado;
- smoke final en verde;
- rollback validado/disponible;
- sin fallos P0/P1 abiertos;
- checklist operativo del primer día;
- checkpoint `CASA VIVA CORE — BASELINE ESTABLE PRE-NETWORK` con SHA exacto.

Al completar ese baseline, detenerse antes de Bloque 08 y avisar que Casa Viva Core está listo para Network.