abi <abi/4.0>,
include <tunables/global>

@{CV_CONTROL_OS} = /var/lib/control-os-dev

profile cv-dev-worker-bwrap /usr/local/lib/control-os/bwrap-dev flags=(attach_disconnected,mediate_deleted) {
  include <abstractions/base>

  # Motivo central del perfil: crear user namespaces (bwrap sin setuid).
  # Si esta regla no resuelve el error de uid_map, la hipótesis central
  # de la propuesta falla y hay que volver al documento antes de tocar
  # nada del VPS.
  userns,

  # Read-only kernel ID mapping constants required during bwrap bootstrap.
  /proc/sys/kernel/overflowuid r,
  /proc/sys/kernel/overflowgid r,
  /proc/sys/user/max_user_namespaces r,

  # Defensa en profundidad: el proceso aislado no usa red (el namespace
  # de red ya está aislado por bwrap). La regla cubre inet/inet6/raw y
  # no afecta a sockets UNIX de abi 4.0.
  # ATENCIÓN: con esta regla la jaula NO puede ejecutar el modelo
  # remoto (la CLI de OpenCode necesita salida a la red). Es una
  # decisión pendiente de diseño, ver docs/CONTROL_OS_SANDBOX_PROPOSAL.md
  # §4/§6: nunca aflojar esta regla sin revisión humana.
  deny network,
  deny /root/{,**} rwklx,
  deny /home/cvdev/.ssh/{,**} rwklx,
  deny /etc/ssl/private/{,**} rwklx,
  deny /var/lib/control-os-auth/{,**} rwklx,

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

# CV_RELEASE_PILOT_BEGIN
# Named profiles only: no automatic attachment and no replacement of E2.
# Trusted bwrap setup runs as cvdev, never with host capabilities.
profile cv-release-pilot-bootstrap flags=(attach_disconnected,mediate_deleted) {
  include <abstractions/base>
  userns,
  capability setpcap,
  capability sys_admin,
  capability net_admin,
  capability sys_chroot,
  capability setuid,
  capability setgid,
  mount,
  umount,
  pivot_root,
  network netlink raw,
  deny network inet,
  deny network inet6,
  signal (send,receive) peer=cv-release-pilot-bootstrap,
  signal (receive) peer=unconfined,
  /proc/sys/kernel/{overflowuid,overflowgid} r,
  /proc/sys/user/max_user_namespaces r,
  /proc/[0-9]*/{uid_map,gid_map,setgroups} rw,
  /proc/{,**} r,
  # Opening the root directory is necessary for trusted mount setup.
  / r,
  /usr/{,**} r,
  /usr/lib/** mr,
  /usr/lib64/** mr,
  /usr/bin/python3.12 Px -> cv-release-pilot-candidate,
  /usr/local/lib/control-os/bwrap-dev ix,
  /newroot/ rw,
  /newroot/** rwkm,
  /newroot/usr/bin/python3.12 Px -> cv-release-pilot-candidate,
  /tmp/{,**} rw,
  /dev/{null,zero,random,urandom,tty} rw,
  /etc/{localtime,ld.so.cache,ld.so.preload} r,
  /etc/ssl/certs/{,**} r,
  @{CV_CONTROL_OS}/work/{,**} rw,
  deny /root/{,**} rwklmx,
  deny /home/{,**} rwklmx,
  deny /var/lib/control-os-auth/{,**} rwklmx,
  deny /etc/ssl/private/{,**} rwklmx,
  deny /newroot/root/{,**} rwklmx,
  deny /newroot/home/{,**} rwklmx,
  deny /newroot/etc/ssl/private/{,**} rwklmx,
}
profile cv-release-pilot-candidate flags=(attach_disconnected,mediate_deleted) {
  include <abstractions/base>
  deny capability,
  deny userns,
  deny network,
  deny mount,
  deny umount,
  deny pivot_root,
  /usr/bin/python3.12 rix,
  /usr/lib/** mr,
  /usr/lib64/** mr,
  /usr/share/** r,
  /proc/{self,[0-9]*}/status r,
  /proc/{self,[0-9]*}/attr/current r,
  /etc/{localtime,ld.so.cache} r,
  /tmp/{,**} rw,
  @{CV_CONTROL_OS}/work/{,**} rw,
  deny /root/{,**} rwklmx,
  deny /home/{,**} rwklmx,
  deny /var/lib/control-os-auth/{,**} rwklmx,
  deny /etc/ssl/private/{,**} rwklmx,
}
