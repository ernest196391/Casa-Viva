# Integración local de Casa Viva

Este entorno es exclusivamente desechable. No usa producción, `casavivadecuba.com`, copias de clientes, credenciales reales ni servicios externos de Casa Viva.

## Elección técnica

Se utiliza **Docker Compose** con las imágenes oficiales de WordPress y MariaDB.

`wp-env` también necesita Docker y es conveniente para desarrollo de bloques, pero este bloque requiere acceso SQL directo, concurrencia, inspección de `dbDelta` y eliminación/restauración controlada de una tabla. Compose ofrece esas capacidades sin añadir otra capa ni mantener dos sistemas.

Referencias:

- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/
- https://hub.docker.com/_/wordpress/
- https://hub.docker.com/_/mariadb/

## Versiones fijadas

| Componente | Versión |
|---|---|
| WordPress | 6.5.5 |
| WooCommerce | 8.2.2 |
| PHP | 8.1, incluido en la imagen WordPress |
| MariaDB | 10.11.8 |
| Casa Viva Dropship Core | 3.6.0 |

Esta configuración representa los mínimos declarados por el plugin. La matriz moderna se aplaza hasta validar primero esta base, evitando duplicar infraestructura.

## Requisitos

- Docker Engine o Docker Desktop.
- Docker Compose v2 (`docker compose`).
- Acceso a Docker Hub y WordPress.org únicamente para descargar imágenes y WooCommerce 8.2.2.

No se necesita PHP, MySQL ni WordPress instalados en el host.

## Comandos

```bash
scripts/integration.sh up
scripts/integration.sh test
scripts/integration.sh logs
scripts/integration.sh down
```

`test` elimina primero los volúmenes del proyecto `casa-viva-integration`, crea una instalación limpia y ejecuta toda la validación. `down` borra contenedores y volúmenes de prueba.

WordPress queda disponible solamente en:

```text
http://127.0.0.1:8889
```

MariaDB no publica ningún puerto al host.

## Datos sintéticos

Se crean únicamente:

- usuarios `cvt_admin`, `cvt_clerk`, `cvt_messenger` y `cvt_gestora`;
- correos bajo `example.invalid`;
- producto `CVT-SYNTHETIC-1`;
- pedidos y dirección marcados explícitamente como sintéticos.

Las contraseñas del Compose son fijas, locales y no reutilizables. No son secretos.

## Alcance automatizado

### Instalación y upgrade

1. Instala WordPress.
2. Instala WooCommerce 8.2.2.
3. Activa Casa Viva 3.6.0.
4. Simula una base anterior conservando un marcador, elimina únicamente la tabla nueva y fija `cvd_version=3.5.0`.
5. Ejecuta `maybe_upgrade()`.
6. Ejecuta `activate()` dos veces para comprobar `dbDelta` repetido.
7. Verifica tabla, índices y conservación de la fila de prueba.

### Flujo de pedido

Ejecuta con acciones públicas del plugin:

```text
CREATED → PREPARING → READY → ASSIGNED → ACCEPTED → TO_STORE
→ PICKED_UP → HANDED_OVER → INCIDENT → HANDED_OVER
→ INCIDENT → HANDED_OVER → DELIVERED → CASH_RETURNED → CLOSED
```

Cada llamada `delivery` se ejecuta en un proceso WP-CLI independiente porque el controlador real finaliza con redirección.

### Persistencia

- Repetición con la misma clave idempotente.
- Dos `INSERT IGNORE` simultáneos contra MariaDB.
- Dos incidencias abiertas legítimas dentro del mismo flujo.
- Actor administrador, dependienta, mensajero, gestora y sistema.
- Pedido legacy más evento canónico posterior.
- 250 eventos y lectura paginada.
- inspección de metadata.
- tabla ausente: el servicio 1C revierte la transición centralizada y restaura la tabla;
- `SHOW CREATE TABLE cvt_cvd_order_events` real.

### Fase 1C

- transición operativa migrada ejecutada por el endpoint legacy y el servicio único;
- dos procesos WP-CLI concurrentes (dependienta y administración) con la misma clave;
- estado, actor, historial y evento inspeccionados directamente en MariaDB;
- exactamente un evento y un historial ante el reintento concurrente;
- tabla de eventos ausente: rollback del estado, sin transición parcial;
- todas las pruebas 1A y 1B permanecen en la misma ejecución.

## Clasificación de pruebas

- `artifacts/tests/test-canonical-*.php`: **unit tests**, sin WordPress.
- `integration/tests/*.php`: **integration tests**, ejecutados dentro de WordPress/WooCommerce/MariaDB reales.
- Esta suite no se denomina E2E de interfaz porque no conduce un navegador ni verifica pantallas.

## Diagnóstico

```bash
scripts/integration.sh logs
```

Los fallos del event store aparecen con el prefijo `Casa Viva event store:`. La prueba también escucha `cvd_order_event_store_failed` sin exponer el error SQL a una interfaz de cliente.

## Limpieza y seguridad

```bash
scripts/integration.sh down
```

Este comando elimina exclusivamente los contenedores y volúmenes nombrados por `casa-viva-integration`. Nunca se deben sustituir sus variables por datos de producción.
