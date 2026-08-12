#!/bin/sh
set -eu

HEALTH_COMMAND=${GC_HEALTH_COMMAND-/usr/local/sbin/gestion-clientes-health}
CONFIG_FILE=${GC_CONFIG_FILE-/etc/issabel/gestion_clientes.conf.php}
STATE_FILE=${GC_HEALTH_STATE_FILE-/var/run/gestion-clientes-health.state}
output=
status=0
output=$($HEALTH_COMMAND --config "$CONFIG_FILE" --json 2>&1) || status=$?
current=$(printf '%s\n' "$output" | sed -n 's/.*"status":"\([A-Z]*\)".*/\1/p' | head -1)
[ -n "$current" ] || current=UNKNOWN
previous=
[ -r "$STATE_FILE" ] && previous=$(sed -n '1p' "$STATE_FILE")

if [ "$current" != "$previous" ]; then
  if command -v logger >/dev/null 2>&1; then
    if [ "$status" -eq 0 ] && [ -n "$previous" ]; then
      logger -t gestion-clientes-health -- "Recovered from $previous to OK"
    elif [ "$status" -ne 0 ]; then
      logger -t gestion-clientes-health -- "$output"
    fi
  elif [ "$status" -ne 0 ]; then
    echo "$output" >&2
  fi
fi

umask 077
state_tmp="$STATE_FILE.$$"
printf '%s\n' "$current" > "$state_tmp"
mv "$state_tmp" "$STATE_FILE"
exit "$status"
