# Gestión de Clientes para Issabel 4

Módulo nativo, independiente del marcador tradicional, para importar carteras,
asignarlas a agentes, realizar llamadas manuales y registrar resultados y callbacks.

## Estado

El repositorio contiene el MVP instalable. La instalación en producción y cualquier
recarga de Asterisk requieren una ventana aprobada; consulte
[`docs/deployment.md`](docs/deployment.md) y [`docs/rollback.md`](docs/rollback.md).

## Requisitos

- Issabel 4, PHP 5.4+, MySQL/MariaDB y Asterisk 11
- Extensiones SIP (`SIP/<extensión>`)
- Credenciales AMI locales dedicadas, fuera del web root
- PDO MySQL y extensiones JSON/mbstring

## Verificación local

```bash
make check
make test
make install-smoke
make shell-check
make db-test
```

`make test` usa el intérprete indicado por `PHP_BIN` (por defecto `php`).

## Instalación

```bash
sudo install/install.sh --module-root /var/www/html/modules \
  --db-host 127.0.0.1 --db-user root --db-name gestion_clientes
```

El instalador no modifica archivos de Asterisk automáticamente. Copie y revise los
ejemplos de `asterisk/` durante una ventana aprobada. Tampoco almacena credenciales
AMI en el repositorio.

Las herramientas de salud, respaldo y reconciliación se instalan por separado:

```bash
sudo install/install-operations.sh
```

El cron solo se reemplaza con `--install-cron` durante una ventana aprobada.

## Seguridad

Todas las escrituras exigen POST, CSRF e idempotencia. La autorización y propiedad
se vuelven a comprobar en el servidor. Los datos importados se escapan al renderizar
y las consultas usan parámetros preparados.
