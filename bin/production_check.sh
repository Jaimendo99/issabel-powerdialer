#!/bin/sh
set -eu

CONFIG_FILE=/etc/issabel/gestion_clientes.conf.php
MODULE_ROOT=/var/www/html/modules/gestion_clientes
HEALTH_COMMAND=/usr/local/sbin/gestion-clientes-health

while [ "$#" -gt 0 ]; do
  case "$1" in
    --config) CONFIG_FILE=$2; shift 2 ;;
    --module-root) MODULE_ROOT=$2; shift 2 ;;
    --health-command) HEALTH_COMMAND=$2; shift 2 ;;
    -h|--help)
      echo "Usage: production_check.sh [--config FILE] [--module-root DIR] [--health-command FILE]"
      exit 0 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

status=0
ok() { echo "OK: $1"; }
warn() { echo "WARNING: $1" >&2; [ "$status" -ge 1 ] || status=1; }
critical() { echo "CRITICAL: $1" >&2; status=2; }

if [ -r "$CONFIG_FILE" ]; then
  ok "application configuration is readable"
else
  critical "application configuration is not readable: $CONFIG_FILE"
fi

if [ -e "$CONFIG_FILE" ]; then
  config_mode=$(stat -c '%a' "$CONFIG_FILE" 2>/dev/null || true)
  case "$config_mode" in 600|640) ok "application configuration permissions are restricted" ;; *) warn "application configuration mode is ${config_mode:-unknown}; expected 600 or 640" ;; esac
fi

if [ -e /etc/issabel/gestion_clientes_ami.secret ]; then
  ami_mode=$(stat -c '%a' /etc/issabel/gestion_clientes_ami.secret 2>/dev/null || true)
  case "$ami_mode" in 600|640) ok "AMI secret permissions are restricted" ;; *) warn "AMI secret mode is ${ami_mode:-unknown}; expected 600 or 640" ;; esac
else
  critical "AMI secret file is missing"
fi

if [ -f "$MODULE_ROOT/index.php" ]; then
  ok "Issabel module is installed"
else
  critical "Issabel module is missing: $MODULE_ROOT"
fi

if [ -x "$HEALTH_COMMAND" ]; then
  if "$HEALTH_COMMAND" --config "$CONFIG_FILE"; then
    ok "application health check passed"
  else
    health_status=$?
    if [ "$health_status" -eq 1 ]; then warn "application health check requires attention"; else critical "application health check failed"; fi
  fi
else
  critical "health command is missing or not executable: $HEALTH_COMMAND"
fi

if [ -f /etc/cron.d/gestion-clientes ] && grep -q 'gestion-clientes-reconcile-cdr' /etc/cron.d/gestion-clientes; then
  ok "CDR reconciliation cron is installed"
else
  critical "CDR reconciliation cron is missing"
fi

if command -v asterisk >/dev/null 2>&1; then
  if asterisk -rx 'dialplan show gestion-clientes-outbound' 2>/dev/null | grep -q "gestion-clientes-outbound"; then
    ok "custom outbound dialplan is loaded"
  else
    critical "custom outbound dialplan is not loaded"
  fi
  if asterisk -rx 'manager show user gestion_clientes' 2>/dev/null | grep -q 'username: gestion_clientes'; then
    ok "dedicated AMI user is loaded"
  else
    critical "dedicated AMI user is not loaded"
  fi
  channels=$(asterisk -rx 'core show channels count' 2>/dev/null | tr '\n' ' ' || true)
  [ -n "$channels" ] && echo "INFO: $channels"
else
  critical "Asterisk CLI is unavailable"
fi

if [ -d /var/lib/asterisk/gestion_clientes/uploads ]; then
  upload_mode=$(stat -c '%a' /var/lib/asterisk/gestion_clientes/uploads 2>/dev/null || true)
  if [ "$upload_mode" = 700 ]; then ok "private upload directory exists with mode 700"; else warn "upload directory mode is ${upload_mode:-unknown}; expected 700"; fi
else
  critical "private upload directory is missing"
fi

if command -v df >/dev/null 2>&1; then
  usage=$(df -P "$MODULE_ROOT" 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')
  if [ -n "$usage" ] && [ "$usage" -ge 90 ]; then warn "filesystem usage is ${usage}%"; else [ -n "$usage" ] && ok "filesystem usage is ${usage}%"; fi
fi

exit "$status"
