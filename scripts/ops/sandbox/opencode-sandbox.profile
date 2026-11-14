# --------------------------------------------------------------------
# Casa Viva Control OS — PROPUESTA de perfil AppArmor para Bubblewrap
# Fase E — NO INSTALADA. Ver docs/CONTROL_OS_SANDBOX_PROPOSAL.md
#
# Objetivo: permitir que /usr/bin/bwrap cree user namespaces bajo la
# restricción de Ubuntu 24.04 (apparmor_restrict_unprivileged_userns)
# manteniendo todo lo demás mediado. NO replica el perfil de Flatpak:
# flags=(unconfined) quedó prohibido explícitamente.
#
# Revisión de sintaxis sin cargar (revisar antes de instalar):
#   sudo apparmor_parser -Q -r scripts/ops/sandbox/opencode-sandbox.profile
#
# Instalación real (solo tras revisión humana; ver documento):
#   sudo install -m 0644 scripts/ops/sandbox/opencode-sandbox.profile \
#       /etc/apparmor.d/cv-opencode-bwrap
#   sudo apparmor_parser -r /etc/apparmor.d/cv-opencode-bwrap
#
# Reversión:
#   sudo apparmor_parser -R /etc/apparmor.d/cv-opencode-bwrap
#   sudo rm /etc/apparmor.d/cv-opencode-bwrap
# --------------------------------------------------------------------
abi 4.0,
include <tunables/global>

@{CV_CONTROL_OS} = /home/cvdev/control-os-dev

profile cv-opencode-bwrap /usr/bin/bwrap flags=(attach_disconnected,mediate_deleted) {
  include <abstractions/base>

  # Motivo central del perfil: crear user namespaces (bwrap sin setuid).
  # Si esta regla no resuelve el error de uid_map, la hipótesis central
  # de la propuesta falla y hay que volver al documento antes de tocar
  # nada del VPS.
  userns,

  # Defensa en profundidad: el proceso aislado no usa red (el namespace
  # de red ya está aislado por bwrap). La regla cubre inet/inet6/raw y
  # no afecta a sockets UNIX de abi 4.0.
  # ATENCIÓN: con esta regla la jaula NO puede ejecutar el modelo
  # remoto (la CLI de OpenCode necesita salida a la red). Es una
  # decisión pendiente de diseño, ver docs/CONTROL_OS_SANDBOX_PROPOSAL.md
  # §4/§6: nunca aflojar esta regla sin revisión humana.
  deny network,

  # Binarios: los hijos heredan este perfil (ix). Bibliotecas y datos.
  # /usr/lib64 cubre el cargador dinámico en distros usrmerge (el
  # intérprete ELF se resuelve dentro de la jaula vía /lib64 → usr/lib64).
  /usr/bin/** ix,
  /usr/local/bin/** ix,
  /usr/lib/** mr,
  /usr/lib64/** mr,
  /usr/share/** r,

  # Lectura del sistema: los únicos /etc concedidos, todos montados
  # read-only. /etc/ssl NUNCA entero (contiene /etc/ssl/private con las
  # claves TLS del host): solo el directorio público de CA.
  /etc/{localtime,ld.so.cache,ld.so.preload} r,
  /etc/ssl/certs/** r,

  # Escritura permitida: únicamente el worktree desechable y tmpfs.
  # El resto del árbol queda denegado por omisión.
  @{CV_CONTROL_OS}/work/{,**} rw,
  /tmp/{,**} rw,

  # NOTA: no se concede capability alguna. Dentro del user namespace,
  # bwrap opera con las capacidades propias del namespace (incluida la
  # configuración del loopback del nuevo namespace de red). Si la
  # calibración supervisada necesitara una capability real (p. ej.
  # net_admin), debe proponerse aquí y pasar revisión humana; nunca se
  # resuelve instalando un perfil unconfined.
}
