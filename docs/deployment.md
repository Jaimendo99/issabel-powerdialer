# Despliegue controlado

## Puertas antes de instalar

1. Confirmar las comprobaciones de `docs/architecture.md`, la ventana aprobada y
   que no haya llamadas activas que dependan de una recarga.
2. Usar clientes sintéticos y un destino interno controlado para el primer piloto.
   No marcar números externos hasta aprobar la matriz interna.
3. Crear un directorio de respaldo único y conservarlo hasta el cierre:

```sh
GC_BACKUP_DIR="/root/gc-backup-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$GC_BACKUP_DIR"
mysqldump -h localhost -uroot -p --single-transaction --routines --triggers \
  gestion_clientes > "$GC_BACKUP_DIR/gestion_clientes.sql"
cp -a /var/www/db/menu.db "$GC_BACKUP_DIR/menu.db"
cp -a /etc/asterisk/extensions_custom.conf "$GC_BACKUP_DIR/" 2>/dev/null || true
cp -a /etc/asterisk/manager_custom.conf "$GC_BACKUP_DIR/" 2>/dev/null || true
if [ -d /var/www/html/modules/gestion_clientes ]; then
  cp -a /var/www/html/modules/gestion_clientes "$GC_BACKUP_DIR/module"
fi
test -s "$GC_BACKUP_DIR/gestion_clientes.sql"
```

Si la base todavía no existe, omitir solamente su `mysqldump`. Respaldar también
la base SQLite que realmente contenga ACL, después de descubrirla con
`ls -l /var/www/db/*.db`; no asumir su nombre.

## Código y base

Ejecutar `install/install.sh` con una cuenta de migración. El nombre de base solo
acepta letras ASCII, números y `_`; el puerto debe estar entre 1 y 65535. El
instalador termina la migración antes de publicar el nuevo árbol del módulo y
archiva el árbol anterior como `.gestion_clientes.previous.*`.

La cuenta web de ejecución debe tener únicamente `SELECT`, `INSERT`, `UPDATE` y
`DELETE` sobre `gestion_clientes.*`. La cuenta de CDR debe tener únicamente
`SELECT` sobre las columnas/tablas verificadas. Guardar sus claves fuera del web
root en `/etc/issabel/gestion_clientes.conf.php`, `root:<grupo-web>` y `0640`.
Guardar el secreto AMI separado, también fuera del web root y con `0640`.

## Menú y ACL

La instalación validada expone exactamente una entrada:
**Call Center -> Gestión de Clientes**, id `gestion_clientes`. El módulo decide si
el usuario aterriza en administración o en su cartera. No crear filas separadas
para dashboard o workspace.

Antes de aplicar `install/menu.sql`, verificar esquema y padre:

```sh
sqlite3 /var/www/db/menu.db '.schema menu'
sqlite3 /var/www/db/menu.db \
  "SELECT id,IdParent,Link,Name,Type,order_no FROM menu WHERE id IN ('call_center','gestion_clientes');"
```

Aplicar la plantilla solo si `call_center` es el id real del padre. Conceder el
recurso `gestion_clientes` desde la interfaz **Security** de Issabel al grupo de
administradores y al grupo aprobado de agentes. `install/acl.sql` no ejecuta
inserciones porque el esquema ACL cambia entre instalaciones. La visibilidad del
menú no sustituye la autorización del módulo.

## Asterisk, reconciliación y piloto

1. Revisar, copiar e incluir los archivos de `asterisk/`; restringir AMI a
   `127.0.0.1` y validar configuración antes de una recarga acotada.
2. Ejecutar el reconciliador con `--dry-run`. Programarlo solo después de comparar
   sus coincidencias con las piernas CDR reales.
3. Comprobar acceso: administrador permitido, agente mapeado permitido y usuario
   no autorizado rechazado.
4. Registrar canales activos antes y después. Pilotear en este orden: agente no
   disponible, agente sin respuesta, destino interno sin respuesta, ocupado y
   contestado/cuelgue.
5. Comparar UI, intento, CDR y grabación después de cada caso. Detener el piloto
   ante un intento ambiguo, duplicado o sin reconciliar; no pulsar llamar de nuevo.

No se modifica el marcador tradicional en ningún paso.
