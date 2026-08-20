# Modelo operativo de despliegue automatizado

## Decisión

Casa Viva no depende del Hostinger Connector para su flujo crítico de desarrollo o despliegue.

La vía canónica es:

ChatGPT/GitHub → CI → release reproducible → SSH → script controlado en Hostinger → WP-CLI → verificación/smoke → rollback automático si falla.

Hostinger Connector queda como herramienta auxiliar de inspección/administración cuando esté disponible, nunca como dependencia obligatoria.

## Objetivo operativo

Maximizar el trabajo autónomo y minimizar la intervención humana. El sistema debe avanzar solo en operaciones seguras, reversibles y verificables. El usuario debe ser requerido únicamente cuando exista una autorización humana necesaria, un secreto/permiso que no pueda resolverse automáticamente o una decisión de negocio/producto.

## Capas

1. GitHub es la fuente de verdad del código y documentación.
2. GitHub Actions valida antes de desplegar.
3. La release se genera de forma reproducible con SHA/checksum verificable.
4. SSH es el canal de transporte/ejecución hacia Hostinger.
5. Un script de despliegue residente y versionado ejecutará backup local de la unidad desplegable, instalación atómica, permisos y verificaciones.
6. WP-CLI se usa para inspección y operaciones WordPress automatizables cuando esté disponible.
7. Smoke tests verifican la aplicación después del despliegue.
8. Cualquier fallo de instalación/verificación/smoke activa rollback automático cuando sea técnicamente posible.

## Reglas de seguridad

- No hacer cambios irreversibles sin autorización explícita.
- No cambiar DNS, credenciales, base de datos, pedidos, stock, comisiones, payouts o finanzas como efecto colateral de un despliegue.
- No ampliar alcance funcional durante una certificación de release.
- Conservar evidencia de SHA, checksum, resultado de CI, resultado de deploy y smoke.
- Mantener una ruta de rollback probada.

## Estrategia de herramientas

Orden preferido:

1. GitHub Actions + SSH.
2. WP-CLI remoto sobre SSH.
3. Script de deployment residente en Hostinger.
4. SSH manual asistido como contingencia.
5. Hostinger Connector como comodidad adicional cuando esté disponible.

## Patrón reutilizable para próximos proyectos

Los proyectos futuros deben adoptar este patrón salvo que su plataforma exija otro mecanismo nativo superior. La automatización no debe depender de la disponibilidad temporal de Work/Codex o de un conector de hosting.

## Estado Casa Viva

El canal GitHub Actions → SSH → Hostinger ya fue demostrado con un despliegue exitoso. La Fase 7D debe convertirlo en una ruta final reproducible y certificada antes de declarar GO de lanzamiento.
