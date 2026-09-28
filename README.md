# Casa Viva

Casa Viva es el primer comercio operativo de una plataforma multiempresa en evolución. El repositorio contiene la aplicación pública de referencia, el plugin operativo para WordPress/WooCommerce, contratos de pedidos, gestoras, dependientas, mensajería, inventario, pruebas, release y despliegue.

## Reanudación obligatoria

Antes de modificar el proyecto, leer en este orden:

1. `docs/CASA_VIVA_PLATFORM_MASTER_BLUEPRINT.md`
2. `docs/CASA_VIVA_PLATFORM_CHECKPOINT.md`
3. `docs/CASA_VIVA_CURRENT_STATE.md`
4. `docs/CASA_VIVA_BLUEPRINT.md`
5. `AGENTS.md`

El checkpoint indica la fase activa, los bloqueos, el SHA auditado y la siguiente acción autorizada.

## Tecnología utilizada

- Next.js con App Router.
- TypeScript.
- Tailwind CSS.
- ESLint.
- npm como gestor de paquetes.
- Alias de importación `@/*`.

## Instalación de dependencias

```bash
npm install
```

## Iniciar el proyecto localmente

```bash
npm run dev
```

Luego abre `http://localhost:3000` en el navegador.

## Comprobaciones

```bash
npm run lint
npm run typecheck
npm run build
```

## Alcance actual

La Fase 0 está dedicada a cerrar y certificar Casa Viva Core. La Foundation multiempresa, las tiendas adicionales, Gestor multitienda, mensajería global y contabilidad Windows permanecen en fases posteriores. No deben iniciarse antes del baseline estable documentado.
