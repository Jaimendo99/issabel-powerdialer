# Guía de operación

## Supervisor

1. Cree la campaña y seleccione zona horaria/contexto saliente.
2. Importe un CSV, asigne ID, nombre, teléfonos y campos adicionales; revise errores.
3. Verifique el mapa permanente usuario/agente y configure las extensiones de puestos disponibles.
4. Previsualice la asignación y confirme la cantidad.
5. Use **Reasignar** para transferir un cliente disponible cuando cambie su
   responsable. El historial y los callbacks se conservan. La operación se
   bloquea si el cliente está abierto, en llamada o espera un resultado.
6. Supervise pendientes, callbacks, resultados y llamadas rechazadas.

## Agente

1. Inicie sesión en Issabel y abra **Gestión de Clientes > Mi cartera**.
2. Elija la extensión del puesto donde está trabajando. La extensión pertenece al puesto; la cartera y las estadísticas permanecen asociadas a su usuario Issabel.
3. Tome el siguiente cliente. Los callbacks vencidos y del día aparecen primero.
4. Elija un teléfono y pulse **Llamar**; conteste Zoiper en la extensión seleccionada para iniciar el tramo cliente.
5. Al terminar, guarde resultado y nota. Para callback indique fecha, hora y zona.
6. Pruebe otro número si corresponde; avance solo después de guardar.
7. Antes de cambiar de puesto, pulse **Liberar extensión**. No es posible cambiarla mientras exista una llamada activa.

Si el sistema informa mapeo inexistente/ambiguo, intento activo o estado sin
reconciliar, no repita llamadas: contacte al supervisor con el `request_id`.
## Resultados e historial del agente

Los resultados como **Interesado**, **No interesado**, **Venta** o **Sin
contacto** son etiquetas informativas guardadas sobre cada intento. No cierran
el cliente, no invalidan números y no lo devuelven automáticamente a la cola.

**Volver a llamar** es la única excepción operativa: exige fecha, hora y zona
horaria, y devuelve el cliente a la cola del mismo agente cuando vence.

Cada agente puede abrir **Mis clientes llamados** desde Mi cartera. La lista
muestra su último intento, resultado, nota, número y callback pendiente. El
botón **Abrir** crea una nueva toma explícita para ese mismo agente. No permite
tomar un cliente que haya sido reasignado a otra persona ni abandonar una
llamada o resultado todavía pendiente.

La pantalla **Callbacks** permite reprogramar o cancelar una devolución pendiente.
No se puede modificar mientras el cliente esté abierto o tenga una llamada activa;
el agente solo gestiona callbacks propios y el supervisor puede gestionar todos.

El panel operativo separa resultados comerciales de estados técnicos y permite
exportar el detalle de llamadas del período en CSV.

## Salud operativa

Antes de habilitar un piloto y después de una actualización, el operador ejecuta:

```sh
gestion-clientes-production-check
```

No iniciar pruebas si devuelve estado crítico. Las advertencias por intentos
ambiguos, reconciliación agotada o callbacks vencidos deben revisarse antes de
ampliar el volumen. El detalle del reconciliador queda en
`/var/log/gestion-clientes-reconcile.log` y rota diariamente.

Pausar o cerrar una campaña bloquea nuevas tomas y llamadas desde este módulo sin
interrumpir llamadas que ya estén en curso. Use **Pausada** como paro operativo;
no reinicie Asterisk para detener una campaña de Gestión de Clientes.
