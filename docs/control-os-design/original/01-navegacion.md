# Navegación

## Barra inferior (móvil y tableta)

| Pestaña | Icono | Contiene (módulos) |
| --- | --- | --- |
| Inicio | `house` | A · Inicio ejecutivo |
| Negocio | `briefcase` | B · Comercio · C · Finanzas · D · CRM y WhatsApp · E · Proveedores · G · Marketing |
| Operaciones | `truck` | I · Logística · H · Gestoras y equipo · F · Content Studio |
| IA | `sparkles` | J · Agentes e IA · K · Evolution Engine |
| Más | `ellipsis` | M · Configuración, perfil, ayuda |

- Dentro de Negocio, Operaciones e IA, la primera pantalla es un índice de módulos con un `ListRow` por módulo y su contador de pendientes.
- El badge de una pestaña = cosas que esperan decisión de ese rol (máx. «9+»).
- El botón flotante del asistente (módulo L) no es una pestaña: está sobre todas.

## Barra lateral (escritorio)

Grupos en el mismo orden: **Inicio** (A) · **Negocio** (B, C, D, E, G) · **Operaciones** (I, H, F) · **Inteligencia** (J, K) · **Sistema** (M). Arriba, el acceso al asistente (⌘K); abajo, el usuario y su rol.

## Patrones de pantalla

- **Índice de módulo:** `title-1` + `SegmentedControl` de periodo + KPIs (2×2) + lista.
- **Lista:** búsqueda, chips de filtro, `ListRow` agrupadas por estado o fecha.
- **Detalle:** cabecera (título + `StatusPill`), acción principal fija abajo en móvil, secciones de datos, actividad al final.
- **Editor:** campos en `surface-sunken`, guardado explícito; cambios sensibles abren `ActionProposal`.
- **Hoja modal:** sube desde abajo (`radius-xl` arriba, `shadow-lg`, `overlay`), para acciones rápidas: asignar mensajero, aprobar, filtrar.
