<!-- Source: docs/operator-guide.md; SHA256: f8ca588089305b99c7c9ce7adbbbf5723b3a94fc9874e3e1c664cc44ecb1ab72 -->

<a id="operator-guide"></a>
# Guía del operador

Cómo instalar, ejecutar, actualizar, respaldar y eliminar el plugin, y qué significan sus gráficos y valores. La preparación de nodos está en [node-setup.md](node-setup.md); las alertas con Thold y Maint, en [alerting.md](alerting.md).

Estado: la fase 1 tiene el código completo y solo se ha ejecutado con salida de nodo de prueba en un Cacti desechable. No hay informes de un piloto en un Lustre real ([#57](https://github.com/somethingwithproof/plugin_pfs/issues/57)); hasta entonces, considere cada cifra siguiente un resultado de muestras de prueba.

<a id="requirements"></a>
## Requisitos

| Componente | Requisito |
|---|---|
| Cacti | 1.2.31 o posterior, sin parches del núcleo |
| PHP | 8.2 o posterior en cada host Cacti que ejecute la web o un sondeador. En 8.0 o 8.1 el plugin rechaza activarse y Cacti sigue funcionando |
| Lustre | Servidores accesibles por SSH con el script de lectura forzado; versiones comprobadas en código: 2.15.8, 2.16.1 y 2.17.0 |
| Host del sondeador | Cliente `ssh`, clave privada del lector y archivo de hosts conocidos |

<a id="install"></a>
## Instalar

1. Descomprima la versión para que el directorio sea `<path_cacti>/plugins/pfs`. Debe llamarse `pfs`; el archivo fuente de GitHub se extrae como `plugin_pfs-<version>` y hay que renombrarlo.
2. En Cacti abra Configuración, Plugins, e instale y active Sistemas de archivos paralelos. La instalación crea tablas, registra hooks y tres ámbitos, importa plantillas de dispositivo, datos y gráficos y copia dos archivos de consulta a Cacti (sección siguiente).
3. Asigne los ámbitos necesarios en Gestión de usuarios: **Parallel Filesystem View** para inventario y actualidad, **Parallel Filesystem Operator** para la página Operaciones y confirmaciones personales de clasificación, y **Parallel Filesystem Admin** para registro y estado. En cada solicitud, Admin hereda Operator y Operator hereda View, sin añadir concesiones persistentes. Los tres respetan los permisos de dispositivo de Cacti.
4. Abra Consola, Estado de sistemas de archivos paralelos. Cada aviso indica qué corregir. Una instalación nueva suele indicar que faltan la clave SSH y los hosts conocidos hasta completar los pasos siguientes.
5. Prepare nodos y sondeador según [node-setup.md](node-setup.md).
6. En Administración de sistemas de archivos paralelos permita las redes de cada sondeador, añada el sistema y después sus nodos (endpoint, puerto, nombre de usuario, rol). No se guarda un nodo cuyo endpoint resuelva fuera de las redes permitidas.
7. Espere un intervalo. El lector se ejecuta desde `poller_bottom` de Cacti en cada sondeador y rellena el inventario. Revise los destinos propuestos en la página del sistema; ignorar uno lo excluye de las consultas y el resumen.
8. Cree el dispositivo del sistema desde administración, o asígnelo a uno existente. El plugin enumera antes lo que añadirá: dispositivo, ambas consultas y cinco gráficos generales. Los gráficos por destino se crean bajo demanda desde Nuevos gráficos de Cacti.

<a id="data-query-files"></a>
### Archivos de consulta de datos

Cacti solo lee XML de consulta dentro de `<path_cacti>/resource`, por lo que se copian `resource/script_server/pfs_osts.xml` y `pfs_mdts.xml` del plugin a `<path_cacti>/resource/script_server/`. Se rechaza un enlace simbólico en cualquiera de los destinos y cada archivo se escribe mediante un temporal en el mismo directorio. Solo registra SHA-256 de archivos que escribe: uno existente con el mismo contenido, por ejemplo copiado con los pasos siguientes, se usa pero sigue siendo suyo y no se desinstala. Nunca sustituye archivos ajenos o modificados posteriormente; solo elimina los propios que no han cambiado.

Si el usuario web no puede escribir allí, la página de estado lo indica y las consultas no pueden listar destinos. Copie una vez con un usuario autorizado, manteniendo lectura para usuarios web y sondeador:

```sh
cd <path_cacti>
install -m 0644 plugins/pfs/resource/script_server/pfs_osts.xml resource/script_server/
install -m 0644 plugins/pfs/resource/script_server/pfs_mdts.xml resource/script_server/
```

Después pulse **Instalar las plantillas de nuevo** en Estado. El aviso desaparece cuando ambas copias coinciden. Al ser suyas, el plugin no las actualiza ni elimina: repita la copia después de una actualización que cambie algún archivo (Estado avisa si difieren) y elimínelas usted tras desinstalar.

<a id="what-the-graphs-and-values-mean"></a>
## Significado de gráficos y valores

Cada sistema de archivos es un dispositivo Cacti. Sus destinos OST y MDT son índices de dos consultas del servidor de scripts. Todos los valores vienen de la caché del lector; ningún gráfico, página ni fuente contacta con nodos.

| Gráfico | Valores | Desconocido cuando |
|---|---|---|
| Ocupación (general) | Máximo, media y mínimo del porcentaje usado OST y máximo del porcentaje usado de inodos por destino; totales de destinos, ausentes, no saludables y segundos desde la observación más antigua del resumen, impresos bajo este gráfico | Porcentajes de capacidad: ningún destino tiene una lectura actual de esa métrica; antigüedad: nunca observado |
| Desequilibrio (general) | Diferencia entre los porcentajes usados OST máximo y mínimo | Ningún OST tiene lectura actual de capacidad |
| Capacidad (general) | Kilobytes totales y disponibles del sistema | Falta cualquier OST o está obsoleto, por lo que el total sería parcial |
| Rendimiento (general) | Bytes leídos y escritos por segundo del sistema y gauges de tasa de lectura y escritura para alertas (bajo el gráfico) | Algún OST falta, está obsoleto, no saludable sin contador o se reinició en el intervalo; los gauges de tasa también durante dos o tres sondeos posteriores al reinicio |
| Metadatos (general) | Operaciones de metadatos por segundo del sistema, todas las permitidas juntas, y gauge de tasa de operaciones para alertas | Algún MDT falta, está obsoleto o se reinició en el intervalo; el gauge de tasa también durante dos o tres sondeos posteriores al reinicio |
| Capacidad, Inodos, Rendimiento OST (por destino) | Usado y disponible, inodos, bytes leídos/escritos y operaciones por segundo | El destino falta, está obsoleto o el nodo no imprimió el valor |
| Capacidad, Operaciones MDT (por destino) | Usado y disponible, inodos, operaciones por segundo por tipo | Igual |

Reglas de la tabla:

- **Porcentaje usado** sigue `lfs df`: usado dividido entre usado más disponible, redondeado hacia arriba.
- **Desconocido es un hueco, nunca cero.** Un destino ausente, lectura fallida o valor no impreso se dibuja como hueco.
- Los **contadores** se guardan en bruto como DERIVE con mínimo 0. Un reinicio (disminución, cambio de `start_time`, otro nodo tras conmutación o valor anterior desconocido) hace desconocido exactamente un intervalo.
- **Actualidad.** Un valor es actual hasta un intervalo y medio, retrasado hasta tres y obsoleto después. Los gauges se sirven actuales o retrasados; los contadores y gauges de tasa del resumen solo actuales, para que una lectura perdida sea un hueco y no una línea plana seguida de una tasa doble.
- **Destinos inactivos.** Lustre no imprime un contador que nunca contó desde su inicio. Las sumas lo consideran cero si el destino está presente, en servicio y comunicó sus otros valores en esa lectura; el gráfico por destino sigue mostrando un hueco ([ADR 0013](../../../decisions/0013-absent-counter-lines-count-as-zero-in-sums.md)).
- **Cambios del conjunto de destinos.** Cuando uno entra o sale de una suma, esta es desconocida durante un sondeo para no dibujar todo su historial como un intervalo.
- Los **destinos ausentes** conservan índice, fuentes e historial hasta que un administrador los ignore en el registro.

<a id="upgrade"></a>
## Actualizar

1. Haga primero una copia de seguridad (abajo).
2. Sustituya `plugins/pfs` por la nueva versión. Mantenga el nombre.
3. Abra Configuración, Plugins. Cacti muestra la nueva versión; el plugin actualiza esquema, hooks, ámbitos, plantillas y archivos de consulta cuando Cacti vuelve a cargar su configuración (abrir Plugins lo hace). Una actualización interrumpida termina en la próxima carga. El lector no se ejecuta si el esquema está atrasado y registra que debe finalizar la actualización.
4. Si actualiza desde una versión preliminar anterior a los gauges de tasa, conserve el historial necesario de los gráficos Rendimiento y Metadatos; después elimine y vuelva a crear esos gráficos generales. Sus archivos RRD existentes no contienen las nuevas fuentes de tasa.
5. Revise Estado y el registro de Cacti. Los problemas de plantilla o consulta se notifican, no se omiten silenciosamente.

Se rechaza volver a una versión anterior si el esquema es más reciente que el código; restaure la copia previa a la actualización.

<a id="back-up-and-restore"></a>
## Copias de seguridad y restauración

Respalde todo lo siguiente conjuntamente, en el mismo momento:

| Qué | Dónde |
|---|---|
| Tablas del plugin | Todas las `plugin_pfs_*` de la base Cacti (`mysqldump cacti $(mysql -N -e "SHOW TABLES LIKE 'plugin\_pfs\_%'" cacti)`), o la base completa |
| Objetos Cacti | Base de datos: dispositivos de sistemas, fuentes y gráficos, plantillas y ajustes en `settings` (`pfs_*`) |
| Archivos RRD | `<path_cacti>/rra` o su directorio RRD |
| Acceso a nodos | En cada sondeador, clave SSH y hosts conocidos de los ajustes. En cada nodo, cuenta lectora, su línea `authorized_keys` y `/usr/local/libexec/pfs-read.sh` |
| Copias de consultas | `<path_cacti>/resource/script_server/pfs_osts.xml` y `pfs_mdts.xml`, si se copiaron a mano |

Para restaurar, detenga sondeadores, restaure base de datos y directorio RRD desde la misma copia, restaure clave y hosts conocidos y arranque los sondeadores. El lector continúa en la siguiente ejecución sin duplicar fuentes: dispositivo, fuentes y RRD se relacionan por sus identificadores y rutas guardados. El primer sondeo tras restaurar dibuja un intervalo de contador desconocido porque no se puede confiar en una tasa que atraviese el hueco. Si el esquema restaurado es anterior al código instalado, el plugin lo detecta y actualiza como se describió. La restauración se prueba con `tests/bin/restore-flow.php`.

<a id="uninstall"></a>
## Desinstalar

1. Opcional: exporte el registro desde Administración de sistemas de archivos paralelos.
2. Desactive y desinstale en Configuración, Plugins.
3. Se eliminan hooks, ámbitos y copias de consulta escritas por el plugin que no hayan cambiado. Quitar `poller_bottom` detiene el lector, por lo que no se recogen valores tras desinstalar: los gráficos conservan historial pero dejan de actualizarse y sus valores nuevos se vuelven desconocidos. Las plantillas importadas permanecen en Cacti como cualquier plantilla. Con **Conservar tablas del plugin al desinstalar** marcado (predeterminado) permanecen las tablas; desmárquelo para borrarlas.
   - **Dispositivos.** Por defecto se conserva cada dispositivo con sus fuentes y gráficos. Marque **Eliminar al desinstalar los dispositivos creados por este plugin** en Configuración, Ajustes, Sistemas de archivos paralelos (desactivado por defecto) para que se eliminen los dispositivos creados desde Administración mediante el borrado de dispositivos de Cacti: se eliminan también sus gráficos y fuentes; los RRD solo se borran si lo dispone el ajuste de limpieza RRD de Cacti. El plugin registra cada dispositivo creado, una fila por dispositivo en `plugin_pfs_created_devices`; un dispositivo existente que haya asociado a un sistema nunca figura en ese registro y siempre se conserva, con las consultas y gráficos generales añadidos por la asociación. Un sistema cuyo dispositivo se eliminó pierde esa asociación, para que las tablas conservadas no apunten a dispositivos borrados. Una desinstalación que borra las tablas conserva `plugin_pfs_created_devices` mientras enumere algún dispositivo que siga en Cacti, para poder eliminarlo al reinstalar y desinstalar después; borre esa tabla manualmente si conserva esos dispositivos de forma definitiva. Le corresponde eliminar los dispositivos, fuentes, gráficos y RRD que haya creado usted.
4. En cada nodo quite la línea `authorized_keys` del lector, el script y la cuenta. La desinstalación nunca contacta con nodos de almacenamiento.

<a id="troubleshooting"></a>
## Resolución de problemas

Empiece por Estado: indica nodo, sondeador y causa. Después consulte el registro Cacti filtrado por `PFS`.

| Síntoma | Causa y solución |
|---|---|
| Un nodo indica «la clave no está en hosts conocidos o cambió» | Añada o corrija la clave con el nombre exacto del endpoint registrado. Verifique por otro canal una clave cambiada antes de confiar; el lector nunca la acepta solo |
| «respondió sin la delimitación del script fijo» | La clave no está limitada al comando forzado o el comando no es `pfs-read.sh`. Corrija `authorized_keys` |
| «imprimió N parámetros que el plugin no lee» | El nodo ejecuta algo distinto del script incluido. Reinstale `node/pfs-read.sh` |
| «resuelve fuera de las redes permitidas» | Permita la red para ese sondeador en administración o corrija el endpoint |
| Lectura de nodo fallida o parcial | Inaccesible, tiempo agotado o respuesta superior a **Respuesta máxima**. Los destinos conservan sus últimos valores hasta quedar obsoletos, luego desconocidos |
| «el lector del sondeador N no ha completado una ejecución» | No se ejecuta `poller_bottom`, PHP del sondeador es anterior a 8.2 o **Ruta del binario PHP** está vacía. El registro indica cuál |
| Las consultas no listan destinos | Faltan copias de consulta o difieren (véase Archivos de consulta), o aún no terminó una lectura |
| Los contadores por destino siempre son huecos | La cuenta no puede leer `stats` del OST. En Lustre 2.17 está en debugfs ([node-setup.md](node-setup.md)) |
| El rendimiento del sistema es un hueco pero el de destinos se dibuja | Un destino falta, está obsoleto, no saludable o recién reiniciado; los recuentos de ausentes y no saludables en Ocupación indican cuál |
| Los gráficos generales se crean pero están vacíos | El dispositivo se sondea desde otro sondeador que el que lee sus nodos; Estado avisa de la discrepancia |
| El registro muestra `Plugin 'thold': Uncaught TypeError` y Thold está desactivado | Un umbral Thold sobre contador vio un intervalo desconocido; elimine el umbral, reactive Thold en Plugins y consulte [alerting.md](alerting.md#counters-and-thold-182) |
