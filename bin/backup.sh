#!/bin/sh
set -eu

CONFIG_FILE=/etc/issabel/gestion_clientes.conf.php
OUTPUT_ROOT=/root
DB_NAME=gestion_clientes
MODULE_ROOT=/var/www/html/modules/gestion_clientes
ISSABEL_DB_DIR=/var/www/db

while [ "$#" -gt 0 ]; do
  case "$1" in
    --config) CONFIG_FILE=$2; shift 2 ;;
    --output-root) OUTPUT_ROOT=$2; shift 2 ;;
    --db-name) DB_NAME=$2; shift 2 ;;
    --module-root) MODULE_ROOT=$2; shift 2 ;;
    --issabel-db-dir) ISSABEL_DB_DIR=$2; shift 2 ;;
    -h|--help)
      echo "Usage: backup.sh [--config FILE] [--output-root DIR] [--db-name NAME] [--module-root DIR] [--issabel-db-dir DIR]"
      exit 0 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

case "$DB_NAME" in ''|*[!A-Za-z0-9_]*) echo "Unsafe database name: $DB_NAME" >&2; exit 2 ;; esac
case "$OUTPUT_ROOT" in /|""|.) echo "Unsafe output root: $OUTPUT_ROOT" >&2; exit 2 ;; esac
case "$MODULE_ROOT" in /|""|.) echo "Unsafe module root: $MODULE_ROOT" >&2; exit 2 ;; esac
case "$ISSABEL_DB_DIR" in /|""|.) echo "Unsafe Issabel database directory: $ISSABEL_DB_DIR" >&2; exit 2 ;; esac

command -v php >/dev/null 2>&1 || { echo "php is required" >&2; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump is required" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "sha256sum is required" >&2; exit 1; }
command -v sqlite3 >/dev/null 2>&1 || { echo "sqlite3 is required" >&2; exit 1; }
[ -r "$CONFIG_FILE" ] || { echo "Configuration is not readable: $CONFIG_FILE" >&2; exit 1; }
[ -d "$MODULE_ROOT" ] || { echo "Module directory does not exist: $MODULE_ROOT" >&2; exit 1; }
[ -d "$ISSABEL_DB_DIR" ] || { echo "Issabel database directory does not exist: $ISSABEL_DB_DIR" >&2; exit 1; }

umask 077
mkdir -p "$OUTPUT_ROOT"
BACKUP_DIR="$OUTPUT_ROOT/gestion-clientes-backup-$(date +%Y%m%d-%H%M%S)"
mkdir "$BACKUP_DIR"
printf 'db_name=%s\n' "$DB_NAME" > "$BACKUP_DIR/BACKUP.meta"
MYSQL_CNF="$BACKUP_DIR/.mysql.cnf"
complete=0
cleanup() {
  rm -f "$MYSQL_CNF"
  if [ "$complete" -ne 1 ] && [ -d "$BACKUP_DIR" ]; then
    echo "Incomplete backup preserved for inspection: $BACKUP_DIR" >&2
  fi
}
trap cleanup EXIT HUP INT TERM

php -r '
$c=require $argv[1];
if (!is_array($c) || empty($c["db_user"]) || !isset($c["db_password"])) exit(2);
$dsn=isset($c["db_dsn"])?$c["db_dsn"]:"";
$host="127.0.0.1";
if (preg_match("/(?:^|;)host=([^;]+)/",$dsn,$m)) $host=$m[1];
function q($v){return "\"".str_replace(array("\\","\"","\n","\r"),array("\\\\","\\\"","",""),(string)$v)."\"";}
echo "[client]\nuser=".q($c["db_user"])."\npassword=".q($c["db_password"])."\nhost=".q($host)."\n";
' "$CONFIG_FILE" > "$MYSQL_CNF"
chmod 600 "$MYSQL_CNF"

mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --routines --triggers --databases "$DB_NAME" > "$BACKUP_DIR/database.sql"
test -s "$BACKUP_DIR/database.sql"
grep -q "CREATE DATABASE.*$DB_NAME" "$BACKUP_DIR/database.sql"

mkdir "$BACKUP_DIR/config" "$BACKUP_DIR/asterisk" "$BACKUP_DIR/issabel"
cp -p "$CONFIG_FILE" "$BACKUP_DIR/config/gestion_clientes.conf.php"
[ -f /etc/issabel/gestion_clientes_ami.secret ] && cp -p /etc/issabel/gestion_clientes_ami.secret "$BACKUP_DIR/config/"
[ -f /etc/cron.d/gestion-clientes ] && cp -p /etc/cron.d/gestion-clientes "$BACKUP_DIR/config/"
[ -f /etc/asterisk/extensions_gestion_clientes.conf ] && cp -p /etc/asterisk/extensions_gestion_clientes.conf "$BACKUP_DIR/asterisk/"
[ -f /etc/asterisk/manager_gestion_clientes.conf ] && cp -p /etc/asterisk/manager_gestion_clientes.conf "$BACKUP_DIR/asterisk/"
[ -f /etc/asterisk/extensions_custom.conf ] && cp -p /etc/asterisk/extensions_custom.conf "$BACKUP_DIR/asterisk/"
[ -f /etc/asterisk/manager_custom.conf ] && cp -p /etc/asterisk/manager_custom.conf "$BACKUP_DIR/asterisk/"
sqlite_count=0
for sqlite_db in "$ISSABEL_DB_DIR"/*.db; do
  [ -f "$sqlite_db" ] || continue
  sqlite_name=$(basename "$sqlite_db")
  case "$sqlite_name" in ''|*[!A-Za-z0-9_.-]*) echo "Unsafe SQLite database name: $sqlite_name" >&2; exit 1 ;; esac
  (cd "$BACKUP_DIR/issabel" && printf ".timeout 5000\n.backup '%s'\n" "$sqlite_name" | sqlite3 "$sqlite_db")
  integrity=$(sqlite3 "$BACKUP_DIR/issabel/$sqlite_name" 'PRAGMA integrity_check;')
  [ "$integrity" = "ok" ] || { echo "SQLite integrity check failed: $sqlite_name" >&2; exit 1; }
  sqlite_count=$((sqlite_count + 1))
done
[ "$sqlite_count" -gt 0 ] || { echo "No Issabel SQLite databases found in $ISSABEL_DB_DIR" >&2; exit 1; }
cp -a "$MODULE_ROOT" "$BACKUP_DIR/module"

(cd "$BACKUP_DIR" && find . -type f ! -name MANIFEST.sha256 ! -name .mysql.cnf -exec sha256sum {} \; | sort -k2) > "$BACKUP_DIR/MANIFEST.sha256"
[ -s "$BACKUP_DIR/MANIFEST.sha256" ] || { echo "Checksum manifest is empty" >&2; exit 1; }
rm -f "$MYSQL_CNF"
complete=1
trap - EXIT HUP INT TERM
echo "Backup completed: $BACKUP_DIR"
