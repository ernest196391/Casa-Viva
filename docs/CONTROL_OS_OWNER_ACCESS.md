# Acceso de propietarios

Auditoría WordPress 2026-11-14: Lennys Rondon usa Lengonga (WP 14) y ya tiene administrator, cvd_gestora y cvd_messenger. No se cambian sus roles ni contraseña.

/control/ permite consultar a WP 1 y 14, únicamente mientras tengan manage_options. WP 14 no crea solicitudes ni decisiones por web: ambos endpoints devuelven 403 antes de escribir archivos; las aprobaciones siguen por WhatsApp. WP 1 conserva su acceso de trabajo. Esta vista es la cola Control OS; pedidos e inventario están en /centro-operaciones/.

Sin sesión se muestran los enlaces para iniciar sesión y volver a /control/. El selector de entrada del Core todavía puede redirigir a Operaciones o gestoras después del login; volver a abrir /control/ conserva la sesión y permite entrar. No se introduce un formulario de credenciales propio.

Contraseña: la persona titular usa la que ya tiene o inicia su recuperación desde /wp-login.php?action=lostpassword. No se extraen contraseñas ni se comparten cuentas.

Pruebas: php scripts/ops/tests/control-web-access.php ejecuta el guard real con 11 casos, incluyendo retirada de manage_options, otro administrador, consulta y ambos POST de Lennys. CI también valida sintaxis y aplicación.

Despliegue: deploy_control_web con SHA revisado y fusionado; backup atómico de los tres archivos. Rollback: desplegar 237ce68ae4eb34020cf72a4eaecac43ff6645b40 con la misma acción, restaurando acceso exclusivo WP 1. La cola no se modifica.

La rama review/ corresponde a mantenimiento revisado. La rama inicial control-os/owner-mobile-access activó por su prefijo el gate exclusivo de commits producidos por Dev Worker (requiere control-os/task-N-..., un commit y metadatos). Ese gate rechazó correctamente la promoción automática. No se alteró ni desactivó el gate; este cambio se procesa por revisión y CI generales.
