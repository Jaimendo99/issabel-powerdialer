# Diccionario de datos

Todas las tablas usan InnoDB, `utf8` y fechas UTC. `install/schema.sql` es la fuente
canónica. Las entidades principales son:

- `gc_campaign`: configuración y ciclo de vida de una cartera.
- `gc_import_batch`, `gc_import_error`: trazabilidad de cada CSV.
- `gc_client`, `gc_client_phone`: cliente, datos arbitrarios y teléfonos ordenados.
- `gc_agent_map`: enlace verificado entre identidades web, Call Center y SIP.
- `gc_assignment`: historial de asignaciones; solo una activa por cliente.
- `gc_client_claim`: lease por pestaña/agente para impedir trabajo concurrente.
- `gc_attempt`: registro técnico y comercial inmutable de cada intento.
- `gc_outcome`: catálogo configurable de transiciones.
- `gc_callback`: agenda UTC de devoluciones.
- `gc_client_event`: auditoría append-only.
- `gc_idempotency`: respuesta estable para reintentos de mutaciones.

Los campos JSON se almacenan como `LONGTEXT` por compatibilidad con MariaDB antiguo.
