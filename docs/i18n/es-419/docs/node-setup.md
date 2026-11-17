<!-- Source: docs/node-setup.md; SHA256: c8550f0416a418ce66d9fdcaf77fcd251f23daa25dcc969b8b05ad76a4793b11 -->

<a id="node-setup"></a>
# Configuración de nodos

Cómo preparar un servidor Lustre para que el lector del plugin pueda leerlo ([ADR 0009](../../../decisions/0009-read-nodes-through-a-forced-ssh-command.md)). Nada de esto se ha ejecutado aún en un nodo Lustre real; las comprobaciones reales están en [#20](https://github.com/somethingwithproof/plugin_pfs/issues/20) y el [plan piloto](../../../pilot-plan.md). La instalación en Cacti está en [operator-guide.md](operator-guide.md).

<a id="on-each-lustre-server"></a>
## En cada servidor Lustre

1. Cree una cuenta dedicada sin privilegios para el lector, por ejemplo `pfsread`, sin contraseña ni otro uso.
2. Instale [node/pfs-read.sh](../../../../node/pfs-read.sh) como `/usr/local/libexec/pfs-read.sh`, propiedad de root y modo 0755. No acepta argumentos, solo lee su lista permitida e imprime salida delimitada.
3. Agregue la clave pública del lector al `authorized_keys` de esa cuenta con el script como comando forzado y sin permitir nada más:

   ```
   restrict,command="/usr/local/libexec/pfs-read.sh" ssh-ed25519 AAAA... cacti-pfs
   ```

   `restrict` desactiva reenvíos, reenvío de agente, X11 y pseudoterminal. sshd ejecuta el comando forzado en lugar de lo que solicite el cliente, de modo que este no puede ejecutar otra cosa.
4. En Lustre 2.17, los archivos OST `stats` y `recovery_status` están en debugfs, legible solo por root de forma predeterminada. Sin root o un montaje debugfs cuyo modo permita leerlos a la cuenta, esos destinos comunican capacidad y condición, pero sus contadores siguen desconocidos. Qué permitir es una decisión del sitio ([privilegios de nodo](../../../research/node-privileges.md)).

<a id="what-the-reader-account-needs-by-node-type"></a>
## Qué necesita la cuenta lectora por tipo de nodo

La cuenta lectora solo necesita leer los archivos siguientes: sin reglas sudo, sin grupos aparte del propio, sin escritura en Lustre y sin uso del shell de inicio de sesión salvo el comando forzado. Deducido del código de Lustre ([privilegios de nodo](../../../research/node-privileges.md)); aún no confirmado en un nodo real.

| Tipo de nodo | Archivos que lee el script | Legibles sin privilegios |
|---|---|---|
| OSS (2.15, 2.16) | `osd-*/<fs>-OST*/kbytes*`, `files*` y `obdfilter/<fs>-OST*/degraded`, `no_precreate`, `no_create`, `readonly` bajo `/sys/fs/lustre`; `obdfilter/<fs>-OST*/stats` y `recovery_status` bajo `/proc/fs/lustre` | Sí |
| OSS (2.17) | Los mismos archivos sysfs; `stats` y `recovery_status` bajo `/sys/kernel/debug/lustre` | Sysfs sí; debugfs solo para root por defecto |
| MDS (los tres) | `osd-*/<fs>-MDT*/kbytes*`, `files*` bajo `/sys/fs/lustre`; `mdt/<fs>-MDT*/md_stats` bajo `/proc/fs/lustre` | Sí |
| Cualquiera | `version` bajo `/sys/fs/lustre` | Sí |

Si el sitio concede acceso debugfs en OSS 2.17, prefiera un montaje cuyo `gid` y `mode` permitan leer a un grupo dedicado en lugar de ejecutar el script como root; tenga en cuenta que esto abre todo debugfs a ese grupo.

<a id="confirm-nothing-more-is-granted"></a>
## Confirmar que no se concede nada más

En cada servidor, como root:

```sh
id pfsread                                   # only its own group, plus any debugfs group you chose
sudo -l -U pfsread                           # "not allowed to run sudo"
grep -n pfsread /etc/sudoers /etc/sudoers.d/* 2>/dev/null   # nothing
cat ~pfsread/.ssh/authorized_keys            # exactly one line, starting with restrict,command="/usr/local/libexec/pfs-read.sh"
ls -l /usr/local/libexec/pfs-read.sh         # root-owned, 0755, not writable by pfsread
sshd -T -C user=pfsread,host=cacti,addr=<poller address> | grep -Ei 'permituserenvironment|authorizedkeyscommand|forcecommand'
```

Desde el sondeador, como su usuario y con la clave configurada:

```sh
ssh -F /dev/null -i /etc/cacti/pfs/id_ed25519 -o UserKnownHostsFile=/etc/cacti/pfs/known_hosts -o HostKeyAlias=<endpoint> pfsread@<address> 'id; cat /etc/shadow'
```

La respuesta debe ser exclusivamente la salida delimitada del script (`#pfs-read 1` … `#pfs-read end`), sin salida del comando solicitado. Cualquier otra cosa indica que el comando forzado no está activo; corríjalo antes de agregar el nodo al registro. Compruebe que `PermitUserEnvironment` sea `no` y que ningún bloque `Match` ni `AuthorizedKeysCommand` dé otra clave o comando a la cuenta.

<a id="on-each-cacti-poller-that-reads-nodes"></a>
## En cada sondeador Cacti que lea nodos

1. Coloque la clave privada en **Archivo de clave SSH** (predeterminado `/etc/cacti/pfs/id_ed25519`), legible solo por el usuario del sondeador. El plugin nunca la crea, copia ni imprime. La página de estado avisa si otros usuarios pueden leerla.
2. Coloque la clave de host de cada nodo en **Archivo de hosts conocidos** (predeterminado `/etc/cacti/pfs/known_hosts`) bajo el nombre de endpoint exacto del registro. El lector conecta a la dirección resuelta con `HostKeyAlias` igual al endpoint y rechaza claves ausentes o cambiadas. Nunca acepta una nueva por sí mismo.
3. En el registro, permita las redes que puede alcanzar ese sondeador. Un nodo cuyo endpoint resuelva a cualquier dirección fuera de ellas no se guarda ni se lee.

<a id="ssh-options"></a>
## Opciones SSH

El lector ejecuta `ssh` con `-F /dev/null` antes de cualquier otra opción, por lo que no lee `~/.ssh/config` del usuario del sondeador ni `ssh_config` del sistema; nada en ellos puede agregar o modificar opciones. Configure valores del sitio en los ajustes del plugin (Ajustes, Sistemas de archivos paralelos): ruta del cliente SSH, archivo de clave, hosts conocidos, tiempo de conexión, tiempo de lectura, tamaño máximo de respuesta, presupuesto del lector y concurrencia. El puerto y el usuario de inicio de sesión se configuran por nodo en el registro. No se admiten opciones fuera de estos ajustes, como un host de salto o un comando proxy.
