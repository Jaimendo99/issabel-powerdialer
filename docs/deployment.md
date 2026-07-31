# Despliegue controlado

1. Confirmar las comprobaciones de `docs/architecture.md` y completar la matriz de
   llamadas en una ventana de prueba.
2. Respaldar base, `/var/www/db/menu.db`, módulo y archivos custom de Asterisk.
3. Ejecutar `install/install.sh` con los parámetros de base. Puede repetirse.
4. Crear un usuario MySQL limitado a la base `gestion_clientes` y editar
   `configs/default.conf.php` fuera del repositorio desplegado o proveer un archivo
   local de secretos con permisos `0640`.
5. Insertar menú/ACL adaptando `install/menu.sql` y `install/acl.sql` al esquema
   verificado. El instalador no los aplica a ciegas.
6. Revisar, copiar e incluir los archivos de `asterisk/`. Crear el secreto AMI.
7. Validar dialplan, usuario AMI y extensión antes de una recarga acotada.
8. Instalar el reconciliador y ejecutarlo manualmente. Programarlo solo después de
   verificar las reglas de piernas CDR.
9. Pilotear con cinco clientes sintéticos y comparar UI, CDR y grabaciones.

No se modifica el marcador tradicional en ningún paso.
