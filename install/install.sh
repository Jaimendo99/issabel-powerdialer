#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
MODULE_ROOT=/var/www/html/modules
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_NAME=gestion_clientes
SKIP_DB=0

usage() {
  echo "Usage: $0 [--module-root DIR] [--db-host HOST] [--db-port PORT] [--db-user USER] [--db-name NAME] [--skip-db]"
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --module-root) MODULE_ROOT=$2; shift 2 ;;
    --db-host) DB_HOST=$2; shift 2 ;;
    --db-port) DB_PORT=$2; shift 2 ;;
    --db-user) DB_USER=$2; shift 2 ;;
    --db-name) DB_NAME=$2; shift 2 ;;
    --skip-db) SKIP_DB=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

case "$MODULE_ROOT" in
  /|""|.) echo "Unsafe module root: $MODULE_ROOT" >&2; exit 2 ;;
esac

mkdir -p "$MODULE_ROOT/gestion_clientes"
cp -R "$PROJECT_DIR/module/gestion_clientes/." "$MODULE_ROOT/gestion_clientes/"

if [ "$SKIP_DB" -eq 0 ]; then
  command -v mysql >/dev/null 2>&1 || { echo "mysql client is required" >&2; exit 1; }
  MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
    --database=mysql --execute="CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci"
  sed "s/\`gestion_clientes\`/\`$DB_NAME\`/g" "$SCRIPT_DIR/schema.sql" | \
    MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"
  sed "s/\`gestion_clientes\`/\`$DB_NAME\`/g" "$SCRIPT_DIR/seed_outcomes.sql" | \
    MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"
fi

echo "Installed module in $MODULE_ROOT/gestion_clientes"
echo "Menu, ACL, AMI, dialplan and cron remain manual approval steps."
