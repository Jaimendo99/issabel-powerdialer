# Rollback

Use el directorio `GC_BACKUP_DIR` creado durante el despliegue. No purgue datos ni
borre respaldos durante un rollback operativo.

1. Retirar el permiso ACL de `gestion_clientes` para impedir llamadas nuevas.
2. Esperar el cierre de intentos activos y tomar un dump final de
   `gestion_clientes` para preservar auditoría.
3. Desactivar el cron del reconciliador. Retirar la inclusión custom del dialplan
   y el usuario AMI usando las copias respaldadas; validar Asterisk antes de una
   recarga acotada.
4. Restaurar `/var/www/db/menu.db` desde el respaldo completo, o eliminar solamente
   la fila `gestion_clientes` si otros cambios legítimos ocurrieron después. Nunca
   restaurar a ciegas una SQLite antigua sobre cambios posteriores.
5. Retirar el código sin tocar datos:

```sh
cd /root/issabel-powerdialer
install/uninstall.sh --module-root /var/www/html/modules
```

El script mueve el módulo a un archivo recuperable y conserva la base. También
conserva deliberadamente menú/ACL, cron, configuración, secreto AMI y uploads para
que cada artefacto se retire de forma explícita y auditable.

Si solo se revierte una actualización de código, mover el módulo actual a otro
nombre y restaurar el `.gestion_clientes.previous.*` anunciado por el instalador.
Verificar propietario/permisos y `php -l` antes de habilitar el menú.

La restauración de base requiere aprobación explícita porque sobrescribe datos
posteriores al respaldo:

```sh
test -s "$GC_BACKUP_DIR/gestion_clientes.sql"
mysql -h localhost -uroot -p gestion_clientes < "$GC_BACKUP_DIR/gestion_clientes.sql"
```

`install/uninstall.sh --purge-data` se permite únicamente con aprobación separada,
dump verificado y objetivo confirmado. Después del rollback comprobar: módulo no
visible, AMI dedicado inaccesible, contexto custom retirado, cron ausente, Call
Center tradicional operativo y cantidad de canales igual a la línea base.

Conservar dump final, backup inicial, logs, archivos `.previous`/`.removed` y
auditoría hasta que Jaime confirme el cierre.
