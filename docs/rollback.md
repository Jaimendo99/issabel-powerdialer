# Rollback

1. Deshabilitar el menú del módulo y detener nuevas llamadas.
2. Esperar el cierre de intentos activos y respaldar `gestion_clientes`.
3. Retirar la inclusión custom del dialplan y el usuario AMI; validar y hacer una
   recarga acotada.
4. Restaurar los archivos y filas de menú/ACL respaldados.
5. Ejecutar `install/uninstall.sh` para retirar código. Por defecto conserva datos.
6. Usar `--purge-data` solo con aprobación explícita y respaldo comprobado.

La restauración de base usa el dump tomado antes del despliegue. Conservar logs y
auditoría hasta que Jaime confirme el cierre.
