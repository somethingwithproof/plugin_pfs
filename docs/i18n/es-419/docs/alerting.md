<!-- Source: docs/alerting.md; SHA256: 62a4dfef764e3ad8210d016cb703a66d160b2a6e53838acc1d6cad199a17809c -->

<a id="alerting-with-thold-and-maint"></a>
# Alertas con Thold y Maint

El plugin no tiene motor de alertas. Los umbrales de Thold y las programaciones de Maint funcionan sobre sus fuentes de datos sin cambios ([ADR 0002](../../../decisions/0002-alert-through-cacti-data-sources.md)). Esta página explica qué valores usar, qué se probó y los límites.

<a id="what-to-threshold"></a>
## Sobre qué crear umbrales

Utilice los valores de **Resumen de sistema de archivos paralelo**. Cada gráfico general tiene su propia fuente de resumen; cree el umbral desde el gráfico que dibuja el valor:

| Valor | Gráfico | Ejemplo de umbral | Notas |
|---|---|---|---|
| `fill_spread_pct` | Desequilibrio | Alto 20 (puntos porcentuales) | OST más lleno menos el más vacío |
| `fill_max_pct` | Ocupación | Alto 90 | OST más lleno |
| `targets_missing` | Ocupación o Desequilibrio | Alto 0.5 | Destinos excluidos del resumen: ausentes o sin lectura actual de capacidad |
| `targets_unhealthy` | Ocupación | Alto 0.5 | Destinos cuyas condiciones no indican servicio; desconocido si no se conoce la condición de ninguno |
| `age` | Ocupación | Alto 3 veces el intervalo de sondeo | Segundos desde la observación más antigua del resumen; numérico si alguna vez se observó el sistema, por lo que aumenta mientras un nodo calla |
| `kbytes_avail` | Capacidad | Bajo, en kilobytes | Desconocido si falta cualquier OST; no alerta por una interrupción |
| `read_bytes_rate`, `write_bytes_rate` | Rendimiento | Alto, en bytes por segundo, 2 sondeos | Tasas de lectura y escritura del sistema calculadas por el lector; desconocidas durante dos o tres sondeos tras un reinicio |
| `md_ops_rate` | Operaciones de metadatos | Alto, en operaciones por segundo, 2 sondeos | Tasa de operaciones de metadatos del sistema, calculada del mismo modo |
| `read_bytes`, `write_bytes`, `md_ops` | Rendimiento, Operaciones de metadatos | Ninguno con Thold 1.8.2; use las tasas anteriores | Contadores (DERIVE), solo para gráficos |

Los gauges por destino (capacidad, inodos) admiten umbrales del mismo modo desde sus gráficos.

<a id="durations"></a>
## Duraciones

- Configure el disparador de fallo con al menos un sondeo. El lector y el sondeador de Cacti tienen programaciones independientes, por lo que un valor puede tener un intervalo de antigüedad al sondearlo Cacti.
- Un valor desconocido (`U`) nunca supera un umbral alto o bajo. En el código de Thold 1.8.2 sigue la ruta sin superación, por lo que un umbral superado registra un restablecimiento ante un hueco (leído en `thold_check_threshold()`, no probado de extremo a extremo). Un destino ausente aparece en `targets_missing` mientras el resumen es actual. Cuando cesan las lecturas y el resumen queda obsoleto, todos los valores salvo `age`, incluido `targets_missing`, se sirven como `U`; por eso `age` alerta de una interrupción de lectura. Alerte sobre estos indicadores, no sobre un hueco.
- Un reinicio de contador produce un intervalo desconocido en su gráfico y dos o tres sondeos desconocidos en el gauge de tasa correspondiente.
- Los umbrales de tasa deben ser altos, con un disparador de fallo de al menos dos sondeos. La fase 1 no admite umbrales de tasa bajos ("la tasa de escritura cayó por debajo"), porque un sondeo desconocido tras un reinicio los restablece ([ADR 0012](../../../decisions/0012-thold-rate-alerts-high-thresholds-only.md#amendment-2026-11-15)).

<a id="maintenance"></a>
## Mantenimiento

Incluya el dispositivo del sistema de archivos en una programación Maint. Thold consulta el dispositivo mediante la función de Maint, por lo que durante ella no cuenta ni notifica superaciones ni registra nada por ellas. La recolección continúa: el lector lee, el sondeador sondea y los RRD guardan valores. Al terminar, una superación persistente cuenta desde cero y notifica después del disparador de fallo.

<a id="counters-and-thold-182"></a>
## Contadores y Thold 1.8.2

No cree umbrales Thold sobre contadores (`read_bytes`, `write_bytes`, `md_ops` o contadores por destino) con Thold 1.8.2. Cree los umbrales sobre los gauges de tasa.

El plugin sirve `U` para un intervalo de contador no fiable: reinicio, entrada o salida de un destino de la suma o lectura no actual. Thold 1.8.2 guarda ese `U` como valor bruto anterior de una fuente DERIVE; con el siguiente valor numérico, su cálculo de tasa lanza un `TypeError` de PHP en el procesamiento de salida del sondeador de Cacti. Cacti desactiva Thold y se detiene el procesamiento de salida de ese sondeo. Se observó en la ejecución de extremo a extremo (registro en [research/thold-counters.md](../../../research/thold-counters.md#milestone-4-end-to-end-run)).

Los gauges de tasa evitan esto: el lector calcula la tasa, Cacti la guarda como GAUGE y Thold compara el nivel sin calcular nada. Un reinicio deja el gauge como `U` durante dos o tres sondeos; Thold lo considera no superado y la prueba de extremo a extremo pasa manteniendo Thold activo. La rama de desarrollo de Thold en `dc76952` (sin publicar) protege la ruta DERIVE; los umbrales de contador podrán reconsiderarse cuando una versión incluya ese cambio.

Si este error desactivó Thold, elimine el umbral del contador, vuelva a activar Thold en Plugins y cree el umbral sobre el gauge de tasa.

<a id="what-was-tested"></a>
## Qué se probó

`tests/bin/alert-replay.sh` se ejecuta con Thold v1.8.2 (`1eb406bd0f81f3204358cce7bd43c8bb5adc8e4a`) y Maint v1.3 (`cd62de9cc323a4f5a8ac80a8d9dda3c7fa16c470`), sin cambios, en un Cacti desechable 1.2.31 y la rama 1.2.x, con salida de nodo de prueba y ejecuciones reales del sondeador. Los umbrales se guardan mediante `save_thold()` de Thold; la programación Maint son las dos filas que guarda su página de programación.

| Escenario | Umbral | Resultado |
|---|---|---|
| Desequilibrio de ocupación y después equilibrio | `fill_spread_pct` superior a 20, 1 sondeo | Notifica una vez y registra restablecimiento |
| Un OST desaparece y vuelve | `targets_missing` superior a 0.5, 1 sondeo | Notifica y se restablece |
| El OSS no responde durante 40 segundos | `age` superior a 30, 1 sondeo | Notifica mientras `age` sigue numérico; se restablece al responder el nodo |
| Tasa de escritura muy superior al umbral durante dos sondeos | `write_bytes_rate` superior a 1 GB/s, 2 sondeos | El primero supera sin notificar, el segundo notifica y después se restablece |
| Se reinician los contadores de todos los OST y vuelve un pico de escritura | `write_bytes_rate` | Dos sondeos desconocidos sin superación ni registro y con Thold activo; en el tercero vuelve el valor numérico y el siguiente pico supera, notifica y se restablece |
| Desequilibrio durante una programación Maint, dos sondeos | `fill_spread_pct` | Sin recuento ni registro mientras el RRD guarda el valor; notifica en el sondeo posterior al fin de la programación |

No probado: entrega de notificaciones (sin servidor de correo), modo demonio de Thold y Maint en un recolector remoto (*en vivo*).
