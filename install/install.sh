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
STAGE=

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
case "$DB_NAME" in
  ''|*[!A-Za-z0-9_]*) echo "Unsafe database name: $DB_NAME" >&2; exit 2 ;;
esac
case "$DB_PORT" in
  ''|*[!0-9]*) echo "Invalid database port: $DB_PORT" >&2; exit 2 ;;
esac
if [ "$DB_PORT" -lt 1 ] || [ "$DB_PORT" -gt 65535 ]; then
  echo "Invalid database port: $DB_PORT" >&2
  exit 2
fi

if [ "$SKIP_DB" -eq 0 ]; then
  command -v mysql >/dev/null 2>&1 || { echo "mysql client is required" >&2; exit 1; }
  MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
    --database=mysql --execute="CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci"
  sed "s/\`gestion_clientes\`/\`$DB_NAME\`/g" "$SCRIPT_DIR/schema.sql" | \
    MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"
  for GC_MIGRATION in "$SCRIPT_DIR"/migrations/*.sql; do
    [ -f "$GC_MIGRATION" ] || continue
    sed "s/\`gestion_clientes\`/\`$DB_NAME\`/g" "$GC_MIGRATION" | \
      MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"
  done
  sed "s/\`gestion_clientes\`/\`$DB_NAME\`/g" "$SCRIPT_DIR/seed_outcomes.sql" | \
    MYSQL_PWD=${MYSQL_PWD-} mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"
fi

# Build a complete module tree before replacing the live tree. Database work is
# intentionally completed first, so a failed migration cannot publish new code.
mkdir -p "$MODULE_ROOT"
STAGE="$MODULE_ROOT/.gestion_clientes.stage.$$"
trap 'if [ -n "$STAGE" ] && [ -d "$STAGE" ]; then rm -rf "$STAGE"; fi' 0 1 2 3 15
mkdir "$STAGE"
cp -R "$PROJECT_DIR/module/gestion_clientes/." "$STAGE/"
# The source archive may inherit a restrictive deployment umask (0640/0750).
# Module code contains no secrets; make it consistently readable/traversable by
# the Issabel web worker regardless of its runtime group.
find "$STAGE" -type d -exec chmod 0755 {} \;
find "$STAGE" -type f -exec chmod 0644 {} \;

TARGET="$MODULE_ROOT/gestion_clientes"
PREVIOUS=
if [ -d "$TARGET" ]; then
  PREVIOUS="$MODULE_ROOT/.gestion_clientes.previous.$(date +%Y%m%d%H%M%S).$$"
  mv "$TARGET" "$PREVIOUS"
fi
if ! mv "$STAGE" "$TARGET"; then
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then mv "$PREVIOUS" "$TARGET"; fi
  exit 1
fi
STAGE=
trap - 0 1 2 3 15

echo "Installed module in $TARGET"
if [ -n "$PREVIOUS" ]; then echo "Previous module archived in $PREVIOUS"; fi
echo "Menu, ACL, AMI, dialplan and cron remain manual approval steps."
