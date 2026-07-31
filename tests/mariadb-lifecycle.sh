#!/bin/sh
set -eu

PROJECT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CONTAINER="gc-mariadb-lifecycle-$$"
ROOT_PASSWORD="gc_lifecycle_only_$$"

skip() {
  echo "SKIP: MariaDB lifecycle test: $1"
  exit 0
}

command -v docker >/dev/null 2>&1 || skip "docker is not installed"
docker info >/dev/null 2>&1 || skip "docker daemon is unavailable"

IMAGE=$(docker image ls --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | awk '/^mariadb:[^<]/ { print; exit }')
[ -n "$IMAGE" ] || skip "no locally cached mariadb image is available (images are never pulled by this test)"

cleanup() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker run -d --name "$CONTAINER" \
  -e "MARIADB_ROOT_PASSWORD=$ROOT_PASSWORD" \
  -e "MYSQL_ROOT_PASSWORD=$ROOT_PASSWORD" \
  "$IMAGE" >/dev/null

ready=0
attempt=0
while [ "$attempt" -lt 45 ]; do
  if docker exec "$CONTAINER" sh -c 'command -v mariadb >/dev/null 2>&1 || command -v mysql >/dev/null 2>&1' && \
     docker exec "$CONTAINER" sh -c 'CLIENT=$(command -v mariadb || command -v mysql); "$CLIENT" -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SELECT 1"' >/dev/null 2>&1; then
    ready=1
    break
  fi
  attempt=$((attempt + 1))
  sleep 1
done
[ "$ready" -eq 1 ] || { echo "FAIL: MariaDB did not become ready" >&2; docker logs "$CONTAINER" >&2; exit 1; }

mysql_root_input() {
  docker exec -i "$CONTAINER" sh -c 'CLIENT=$(command -v mariadb || command -v mysql); "$CLIENT" -uroot -p"$MARIADB_ROOT_PASSWORD"'
}

mysql_db_input() {
  docker exec -i "$CONTAINER" sh -c 'CLIENT=$(command -v mariadb || command -v mysql); "$CLIENT" -uroot -p"$MARIADB_ROOT_PASSWORD" gestion_clientes'
}

mysql_query() {
  docker exec "$CONTAINER" sh -c 'CLIENT=$(command -v mariadb || command -v mysql); "$CLIENT" -N -B -uroot -p"$MARIADB_ROOT_PASSWORD" gestion_clientes -e "$1"' sh "$1"
}

mysql_root_input < "$PROJECT_DIR/install/schema.sql"
for migration in "$PROJECT_DIR"/install/migrations/*.sql; do
  [ -f "$migration" ] || continue
  mysql_db_input < "$migration" >/dev/null
done
mysql_db_input < "$PROJECT_DIR/install/seed_outcomes.sql"

# Every migration and seed must be safe to run again during a resumed upgrade.
for migration in "$PROJECT_DIR"/install/migrations/*.sql; do
  [ -f "$migration" ] || continue
  mysql_db_input < "$migration" >/dev/null
done
mysql_db_input < "$PROJECT_DIR/install/seed_outcomes.sql"

assert_query() {
  description=$1
  sql=$2
  expected=$3
  actual=$(mysql_query "$sql")
  if [ "$actual" != "$expected" ]; then
    echo "FAIL: $description (expected '$expected', got '$actual')" >&2
    return 1
  fi
  echo "ok - $description"
}

assert_query "dynamic-seat tables exist" \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME IN ('gc_sip_seat','gc_work_session')" "2"
assert_query "all required fresh-schema tables exist" \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME IN ('gc_schema_version','gc_campaign','gc_import_batch','gc_client','gc_client_phone','gc_agent_map','gc_sip_seat','gc_work_session','gc_assignment','gc_client_claim','gc_outcome','gc_attempt','gc_callback','gc_client_event','gc_import_error','gc_idempotency')" "16"
assert_query "attempt snapshot columns exist" \
  "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME IN ('work_session_id','agent_sip_extension')" "2"
assert_query "CDR retry scheduling columns exist" \
  "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME IN ('cdr_retry_count','cdr_next_retry_at')" "2"
assert_query "agent extension has bounded VARCHAR storage" \
  "SELECT CONCAT(DATA_TYPE,':',CHARACTER_MAXIMUM_LENGTH) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='agent_sip_extension'" "varchar:20"
assert_query "work-session attempt index exists once" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_work_session'" "1"
assert_query "work-session attempt foreign key exists once" \
  "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND CONSTRAINT_NAME='fk_gc_attempt_work_session'" "1"
assert_query "unreconciled CDR index contains retry schedule" \
  "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_unreconciled'" "reconciled_at,cdr_next_retry_at,requested_at"
assert_query "default outcome seed remains idempotent" \
  "SELECT COUNT(*) FROM gc_outcome WHERE campaign_id IS NULL" "8"
assert_query "callback seed retains callback requirement" \
  "SELECT CONCAT(resulting_client_state,':',requires_callback) FROM gc_outcome WHERE campaign_id IS NULL AND code='CALLBACK'" "CALLBACK:1"

assert_query "schema ledger records every migration" \
  "SELECT GROUP_CONCAT(version_num ORDER BY version_num SEPARATOR ',') FROM gc_schema_version" "1,2,3"

echo "MariaDB fresh-install and repeat-migration lifecycle passed with $IMAGE."
