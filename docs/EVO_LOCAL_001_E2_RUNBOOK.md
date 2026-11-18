# E2 — Instalación planificada (NO autorizada ni ejecutada)

Prerequisitos: PR sucesor #251 revisado e integrado (o sucesor inequívoco), SHA integrado exacto y CI de ese SHA verde, E2 autorizado por Ernesto, worker ocioso y sin ejecuciones activas. Confirmar `systemctl cat cv-dev-worker.service`, ruta y propietario de los archivos, ausencia de otro operador, y espacio suficiente para dos checkouts. No ejecutar si el servicio, ruta o configuración difieren. Mantener `CV_DEV_SANDBOX=0`; no abrir la puerta de propuestas. E3 es una tarea posterior de certificación; E4 exige aprobación separada.

## Procedimiento (VPS, root, tras autorización)

Usar un checkout **confiable y revisado** de Casa-Viva en `/root/Casa-Viva` con el SHA aprobado, sin modificaciones locales. Comprobar primero:

```sh
git -C /root/Casa-Viva status --short
git -C /root/Casa-Viva rev-parse HEAD
systemctl cat cv-dev-worker.service
systemctl is-active cv-dev-worker.service
id cvdev
stat -c '%U:%G %a %n' /var /var/lib /root/supervisor /usr/local/lib/control-os /usr/bin/bwrap
sysctl kernel.apparmor_restrict_unprivileged_userns
df -h /var/lib /root
```

Confirmar manualmente SHA, ausencia de ejecuciones activas en Control OS y que estos destinos **nuevos** no existen: /usr/local/lib/control-os/bwrap-dev, /etc/apparmor.d/cv-dev-worker-bwrap, /var/lib/control-os-dev, /etc/systemd/system/cv-dev-worker.service.d/90-evo-local.conf. Si existen, detener y revisar; no sobrescribir. Validar sintaxis del perfil antes de parar el servicio:

```sh
apparmor_parser -Q -r /root/Casa-Viva/scripts/ops/sandbox/dev-worker-sandbox.profile
```

Crear respaldo, guardar hashes y unidad efectiva. Ejecutar en una sesión root estable sin continuar tras errores:

```sh
set -eu
umask 077
backup=$(mktemp -d /root/backups/evo-local-e2.XXXXXX)
echo "$backup"
for item in dev-worker.py dev-sandbox.py sandbox/opencode-sandbox-spec.py; do
  if test -L "/root/supervisor/$item"; then
    echo "Destino simbólico: $item" >&2; exit 1
  elif test -f "/root/supervisor/$item"; then
    mkdir -p "$backup/$(dirname "$item")"
    cp -a "/root/supervisor/$item" "$backup/$item"
    sha256sum "/root/supervisor/$item" >> "$backup/original-sha256.txt"
    cmp -s "/root/supervisor/$item" "$backup/$item"
    printf "present %s\n" "$item" >> "$backup/manifest.txt"
  elif test ! -e "/root/supervisor/$item"; then
    test "$item" != dev-worker.py
    printf "absent %s\n" "$item" >> "$backup/manifest.txt"
  else
    echo "Destino no regular: $item" >&2; exit 1
  fi
done
systemctl cat cv-dev-worker.service > "$backup/unit.txt"
test "$(wc -l < "$backup/manifest.txt")" -eq 3
sha256sum -c "$backup/original-sha256.txt"
systemctl stop cv-dev-worker.service
install -d -o root -g root -m 0755 /var/lib/control-os-dev /var/lib/control-os-dev/work
install -d -o root -g root -m 0700 /root/supervisor/sandbox
install -o root -g root -m 0644 /root/Casa-Viva/scripts/ops/sandbox/opencode-sandbox-spec.py /root/supervisor/sandbox/opencode-sandbox-spec.py
install -o root -g root -m 0644 /root/Casa-Viva/scripts/ops/dev-sandbox.py /root/supervisor/dev-sandbox.py
install -o root -g root -m 0755 /root/Casa-Viva/scripts/ops/dev-worker.py /root/supervisor/dev-worker.py
install -o root -g root -m 0755 /usr/bin/bwrap /usr/local/lib/control-os/bwrap-dev
install -o root -g root -m 0644 /root/Casa-Viva/scripts/ops/sandbox/dev-worker-sandbox.profile /etc/apparmor.d/cv-dev-worker-bwrap
apparmor_parser -r /etc/apparmor.d/cv-dev-worker-bwrap
install -d -o root -g root -m 0755 /etc/systemd/system/cv-dev-worker.service.d
printf '[Service]\nEnvironment=CV_DEV_SANDBOX=0\n' > /etc/systemd/system/cv-dev-worker.service.d/90-evo-local.conf
chmod 0644 /etc/systemd/system/cv-dev-worker.service.d/90-evo-local.conf
systemctl daemon-reload
systemctl start cv-dev-worker.service
systemctl is-active cv-dev-worker.service
systemctl show cv-dev-worker.service -p FragmentPath -p DropInPaths
aa-status
sha256sum /root/supervisor/dev-worker.py /root/supervisor/dev-sandbox.py /root/supervisor/sandbox/opencode-sandbox-spec.py /usr/local/lib/control-os/bwrap-dev
```

Antes del stop, registrar el manifiesto de destinos nuevos y comprobar que todos los respaldos son recuperables. Desde el stop, cualquier error exige detener la secuencia y ejecutar la reversión, incluso si el servicio nunca llegó a iniciarse. Registrar qué pasos llegaron a ejecutarse; no retirar destinos que no fueron creados por esta operación.

Verificar el valor efectivo del interruptor leyendo únicamente la entrada exacta `CV_DEV_SANDBOX=0` del entorno de `/proc/<MainPID>/environ` (no mostrar otras variables), comprobar heartbeat y ausencia de tareas nuevas. No relajar AppArmor, no reiniciar el VPS. Si falla, detener y revertir.

## Reversión

Conservar el respaldo exacto impreso anteriormente. Parar solo cv-dev-worker, retirar el drop-in nuevo, descargar el perfil dedicado con `apparmor_parser -R /etc/apparmor.d/cv-dev-worker-bwrap`, retirar exclusivamente ese perfil y su binario, restaurar las tres rutas según manifest.txt (si un archivo no existía, retirar exclusivamente el nuevo); comprobar `sha256sum -c original-sha256.txt` antes de iniciar, ejecutar daemon-reload, iniciar el servicio y comprobar hash/heartbeat. Dejar la raíz de caja vacía; no hacer borrados recursivos de rutas no comprobadas. No cambiar el perfil Codex existente ni el sysctl.

## E3 — bloqueante, todavía pendiente

Adaptar el arnés verify-sandbox al binario/perfil/ruta dedicados; probar en vivo red, secretos, escritura fuera, metadatos Git falsos y enlaces, escape por procesos hijos, límites efectivos de memoria y procesos; verificar npm sin secretos ni credenciales de usuario (incluido ~/.npmrc y acceso a otros trabajos cvdev), y validar una tarea ficticia de docs con ejecución aislada controlada sin habilitar el servicio persistente. Confirmar compatibilidad de builds que esperan metadatos Git. No declarar aislamiento certificado ni activar E4 hasta superar E3 y recibir SÍ de Ernesto.

### Criterios de aceptación E3 (antes de E4)

- Canarios sintéticos, nunca secretos reales: HOME y configuración npm de usuario/global, archivos de otro trabajo y escritura fuera del destino. El instalador no debe leerlos; no basta una lista blanca de variables.
- Separar HOME, npm cache y configuración para cada trabajo; verificar bloqueo de scripts de instalación y que la etapa de pruebas no tiene red.
- Medir límites cgroup de CPU, memoria y procesos, tiempo máximo y terminación de descendientes. Un timeout por sí solo no prueba aislamiento.
- Alterar la copia desechable y comprobar hash/diff del checkout confiable intacto; intentar falsificar metadatos Git y crear enlaces colgantes.
- Guardar resultados, duración, códigos de salida y SHA, sin datos sensibles. Cualquier fallo o ausencia de evidencia bloquea E4.
