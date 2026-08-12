#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
INSTALL_CRON=0
CONFIG_FILE=/etc/issabel/gestion_clientes.conf.php

while [ "$#" -gt 0 ]; do
  case "$1" in
    --install-cron) INSTALL_CRON=1; shift ;;
    --config) CONFIG_FILE=$2; shift 2 ;;
    -h|--help)
      echo "Usage: $0 [--install-cron] [--config FILE]"
      echo "Installs production CLI tools. Cron is installed only when explicitly requested."
      exit 0 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

[ "$(id -u)" -eq 0 ] || { echo "Must run as root" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php is required" >&2; exit 1; }
[ -r "$CONFIG_FILE" ] || { echo "Configuration is not readable: $CONFIG_FILE" >&2; exit 1; }
php -r '
$c=require $argv[1];
if (!is_array($c) || empty($c["db_dsn"]) || empty($c["db_user"]) || !isset($c["db_password"])) exit(2);
try {
  $pdo=new PDO($c["db_dsn"],$c["db_user"],$c["db_password"],array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
  $version=(int)$pdo->query("SELECT MAX(version_num) FROM gc_schema_version")->fetchColumn();
  exit($version>=6 ? 0 : 3);
} catch (Exception $e) { exit(4); }
' "$CONFIG_FILE" || { echo "Schema version 6 must be applied before installing operational tools" >&2; exit 1; }

install -m 0755 "$PROJECT_DIR/bin/reconcile_cdr.php" /usr/local/sbin/gestion-clientes-reconcile-cdr
install -o root -g asterisk -m 0750 "$PROJECT_DIR/bin/finalize_call.php" /var/lib/asterisk/agi-bin/gestion-clientes-finalize-call
install -m 0755 "$PROJECT_DIR/bin/health_check.php" /usr/local/sbin/gestion-clientes-health
install -m 0755 "$PROJECT_DIR/bin/health_alert.sh" /usr/local/sbin/gestion-clientes-health-alert
install -m 0755 "$PROJECT_DIR/bin/cleanup_claims.php" /usr/local/sbin/gestion-clientes-cleanup-claims
install -m 0755 "$PROJECT_DIR/bin/production_check.sh" /usr/local/sbin/gestion-clientes-production-check
install -m 0750 "$PROJECT_DIR/bin/backup.sh" /usr/local/sbin/gestion-clientes-backup
install -m 0750 "$PROJECT_DIR/bin/verify_backup.sh" /usr/local/sbin/gestion-clientes-verify-backup
install -m 0644 "$SCRIPT_DIR/gestion-clientes.logrotate" /etc/logrotate.d/gestion-clientes
touch /var/log/gestion-clientes-reconcile.log
chown root:asterisk /var/log/gestion-clientes-reconcile.log
chmod 0640 /var/log/gestion-clientes-reconcile.log

if [ "$INSTALL_CRON" -eq 1 ]; then
  install -m 0644 "$SCRIPT_DIR/gestion-clientes.cron" /etc/cron.d/gestion-clientes
  echo "Installed production tools and reconciliation cron."
else
  echo "Installed production tools. Cron was not changed; pass --install-cron in an approved window."
fi
