#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${PHP_BIN-php}

has_php=1
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "PHP executable not found: $PHP_BIN; running static compatibility checks only." >&2
  has_php=0
fi

status=0
files=$(find "$PROJECT_DIR/module" "$PROJECT_DIR/bin" "$PROJECT_DIR/tests" -type f -name '*.php' 2>/dev/null || true)
if [ -z "$files" ]; then
  echo "No PHP files found" >&2
  exit 1
fi

if [ "$has_php" -eq 1 ]; then
  for file in $files; do
    if ! "$PHP_BIN" -l "$file" >/dev/null; then
      status=1
    fi
  done
fi

# Constructs introduced after PHP 5.4. This is intentionally conservative and
# complements (rather than replaces) linting with a real PHP 5.4 binary.
if printf '%s\n' "$files" | xargs grep -nE '(^|[^[:alnum:]_])(yield|finally)[[:space:]]|\.\.\.|\?\?|<=>|function[[:space:]]*\([^)]*\)[[:space:]]*:'; then
  echo "Unsupported post-PHP-5.4 syntax detected" >&2
  status=1
fi

if printf '%s\n' "$files" | xargs grep -nE '(^|[,(])[[:space:]]*(int|string|float|bool)[[:space:]]+\$'; then
  echo "Scalar parameter type declaration detected" >&2
  status=1
fi

if [ "$status" -eq 0 ]; then
  if [ "$has_php" -eq 1 ]; then
    echo "PHP 5.4 compatibility checks passed (${PHP_BIN})."
  else
    echo "Static PHP 5.4 compatibility checks passed; runtime lint was skipped."
  fi
fi
exit "$status"
