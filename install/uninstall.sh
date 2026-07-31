#!/bin/sh
set -eu

MODULE_ROOT=/var/www/html/modules
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_NAME=gestion_clientes
PURGE=0

while [ "$#" -gt 0 ]; do
  case "$1" in
    --module-root) MODULE_ROOT=$2; shift 2 ;;
    --db-host) DB_HOST=$2; shift 2 ;;
    --db-port) DB_PORT=$2; shift 2 ;;
    --db-user) DB_USER=$2; shift 2 ;;
    --db-name) DB_NAME=$2; shift 2 ;;
    --purge-data) PURGE=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

TARGET="$MODULE_ROOT/gestion_clientes"
case "$TARGET" in
  /|/var|/var/www|/var/www/html|/var/www/html/modules|""|.)
    echo "Refusing unsafe target: $TARGET" >&2; exit 2 ;;
esac

if [ -d "$TARGET" ]; then
  ARCHIVE="${TARGET}.removed.$(date +%Y%m%d%H%M%S)"
  mv "$TARGET" "$ARCHIVE"
  echo "Module moved to recoverable archive: $ARCHIVE"
fi

if [ "$PURGE" -eq 1 ]; then
  command -v mysql >/dev/null 2>&1 || { echo "mysql client is required for purge" >&2; exit 1; }
  echo "Purging database $DB_NAME in 5 seconds; interrupt now to cancel." >&2
  sleep 5
  MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
    --execute="DROP DATABASE IF EXISTS \`$DB_NAME\`"
  echo "Database $DB_NAME removed; recover it from the pre-deployment backup."
else
  echo "Database $DB_NAME preserved."
fi
