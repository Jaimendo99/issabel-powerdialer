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
