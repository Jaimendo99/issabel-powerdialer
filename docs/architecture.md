# Arquitectura

## Límites

`gestion_clientes` posee sus tablas `gc_*` y no escribe en `call_center.campaign`,
`calls` ni `call_attribute`. Solo lee las identidades existentes y el CDR. La sesión
y ACL provienen de Issabel; `gc_agent_map` enlaza explícitamente usuario web,
agente de Call Center y extensión SIP.

## Flujo de llamada

```text
Navegador -> módulo: POST api_start_call (CSRF + idempotency key)
módulo -> MySQL: bloquea cliente/asignación y crea gc_attempt
módulo -> AMI local: Originate SIP/<agente>, Local/<teléfono>@gestion-clientes-outbound
Asterisk -> agente: timbra primero
agente -> Asterisk: contesta
Asterisk -> from-internal: marca al cliente con GC_ATTEMPT_ID heredado
Asterisk -> CDR: accountcode/userfield = GC-<token>
reconciliador -> CDR: encuentra piernas y actualiza estado técnico
agente -> módulo: registra resultado comercial y siguiente acción
```

## Estado de descubrimiento

La información de versiones en el plan fue capturada el 31 de julio de 2026. Antes
de desplegar deben confirmarse en el servidor: esquema exacto de usuarios/agentes,
backend y columnas de CDR, inclusiones custom de Asterisk, contexto saliente,
política de grabación y comportamiento de las cinco llamadas de prueba. La capa
`GestionClientesPlatform` aísla las diferencias de sesión/ACL y la configuración
permite adaptar nombres sin modificar el dominio.

## Concurrencia

Las asignaciones y claims usan transacciones y `SELECT ... FOR UPDATE`. Un claim es
un lease con vencimiento. El intento tiene claves únicas de correlación e
idempotencia. Los endpoints siempre vuelven a validar campaña, agente, propiedad,
estado terminal, teléfono y ausencia de intento activo.

## Tiempo

La base guarda UTC. La zona de campaña se usa para entrada, salida y agrupación por
día comercial. El navegador no decide la autorización ni el estado canónico.
