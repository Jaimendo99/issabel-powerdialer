#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
SMOKE_DIR=$(mktemp -d "${TMPDIR-/tmp}/gestion-clientes-smoke.XXXXXX")
trap 'rm -rf "$SMOKE_DIR"' EXIT HUP INT TERM

MODULE_ROOT="$SMOKE_DIR/modules"
mkdir -p "$MODULE_ROOT"

sh "$PROJECT_DIR/install/install.sh" --module-root "$MODULE_ROOT" --skip-db

INSTALLED="$MODULE_ROOT/gestion_clientes"
test -f "$INSTALLED/index.php" || { echo "Installed index.php is missing" >&2; exit 1; }
test -d "$INSTALLED/libs" || { echo "Installed libs directory is missing" >&2; exit 1; }

# A second dry installation must be safe and leave a usable module.
sh "$PROJECT_DIR/install/install.sh" --module-root "$MODULE_ROOT" --skip-db
test -f "$INSTALLED/index.php" || { echo "Repeated install damaged the module" >&2; exit 1; }

if find "$INSTALLED" -type l -print | grep . >/dev/null 2>&1; then
  echo "Installer must not deploy symbolic links" >&2
  exit 1
fi

echo "Install smoke test passed."

# Operational tools install into an isolated prefix during smoke validation.
for tool in bin/reconcile_cdr.php bin/cleanup_claims.php bin/health_check.php bin/health_alert.sh bin/production_check.sh bin/backup.sh bin/verify_backup.sh; do
  test -s "$PROJECT_DIR/$tool" || { echo "Operational tool is missing: $tool" >&2; exit 1; }
done

if command -v sha256sum >/dev/null 2>&1 && command -v sqlite3 >/dev/null 2>&1; then
  BACKUP_SAMPLE="$SMOKE_DIR/backup-sample"
  mkdir -p "$BACKUP_SAMPLE/config" "$BACKUP_SAMPLE/module" "$BACKUP_SAMPLE/issabel"
  printf '%s\n' 'db_name=gestion_clientes' > "$BACKUP_SAMPLE/BACKUP.meta"
  printf '%s\n' 'CREATE DATABASE `gestion_clientes`;' > "$BACKUP_SAMPLE/database.sql"
  printf '%s\n' '<?php return array();' > "$BACKUP_SAMPLE/config/gestion_clientes.conf.php"
  printf '%s\n' '<?php' > "$BACKUP_SAMPLE/module/index.php"
  sqlite3 "$BACKUP_SAMPLE/issabel/menu.db" 'CREATE TABLE menu (id TEXT PRIMARY KEY);'
  (cd "$BACKUP_SAMPLE" && find . -type f ! -name MANIFEST.sha256 -exec sha256sum {} \; | sort -k2) > "$BACKUP_SAMPLE/MANIFEST.sha256"
  sh "$PROJECT_DIR/bin/verify_backup.sh" "$BACKUP_SAMPLE" >/dev/null
  mv "$BACKUP_SAMPLE/MANIFEST.sha256" "$BACKUP_SAMPLE/MANIFEST.sha256.missing"
  if sh "$PROJECT_DIR/bin/verify_backup.sh" "$BACKUP_SAMPLE" >/dev/null 2>&1; then
    echo "Backup verification accepted a missing checksum manifest" >&2
    exit 1
  fi
else
  echo "SKIP: backup verification smoke requires sha256sum and sqlite3"
fi
