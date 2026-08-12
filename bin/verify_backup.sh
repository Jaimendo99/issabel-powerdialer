#!/bin/sh
set -eu

[ "$#" -eq 1 ] || { echo "Usage: verify_backup.sh BACKUP_DIR" >&2; exit 2; }
BACKUP_DIR=$1
case "$BACKUP_DIR" in /|""|.) echo "Unsafe backup directory" >&2; exit 2 ;; esac
[ -d "$BACKUP_DIR" ] || { echo "Backup directory does not exist: $BACKUP_DIR" >&2; exit 1; }
[ -s "$BACKUP_DIR/BACKUP.meta" ] || { echo "Backup metadata is missing" >&2; exit 1; }
DB_NAME=$(sed -n 's/^db_name=//p' "$BACKUP_DIR/BACKUP.meta")
case "$DB_NAME" in ''|*[!A-Za-z0-9_]*) echo "Backup database name is invalid" >&2; exit 1 ;; esac
[ -s "$BACKUP_DIR/database.sql" ] || { echo "database.sql is missing or empty" >&2; exit 1; }
grep -q "CREATE DATABASE.*\`$DB_NAME\`" "$BACKUP_DIR/database.sql" || { echo "database.sql does not contain $DB_NAME" >&2; exit 1; }
[ -s "$BACKUP_DIR/config/gestion_clientes.conf.php" ] || { echo "Application configuration is missing" >&2; exit 1; }
[ -d "$BACKUP_DIR/module" ] || { echo "Module backup is missing" >&2; exit 1; }
[ -d "$BACKUP_DIR/issabel" ] || { echo "Issabel database backup is missing" >&2; exit 1; }
set -- "$BACKUP_DIR"/issabel/*.db
[ -f "$1" ] || { echo "No Issabel SQLite database was backed up" >&2; exit 1; }

[ -s "$BACKUP_DIR/MANIFEST.sha256" ] || { echo "Checksum manifest is missing or empty" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { echo "sha256sum is required" >&2; exit 1; }
command -v sqlite3 >/dev/null 2>&1 || { echo "sqlite3 is required" >&2; exit 1; }
(cd "$BACKUP_DIR" && sha256sum -c MANIFEST.sha256)
for sqlite_db in "$BACKUP_DIR"/issabel/*.db; do
  integrity=$(sqlite3 "$sqlite_db" 'PRAGMA integrity_check;')
  [ "$integrity" = "ok" ] || { echo "SQLite integrity check failed: $sqlite_db" >&2; exit 1; }
done

echo "Backup verified: $BACKUP_DIR"
