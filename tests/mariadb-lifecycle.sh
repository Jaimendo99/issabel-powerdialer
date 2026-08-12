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

# Simulate a client left IN_PROGRESS by the pre-v6 expired-claim cleanup.
mysql_db_input <<'SQL'
INSERT INTO gc_campaign (id,name,description,status,timezone,outbound_context,dialing_mode,created_by,created_at,updated_at)
VALUES (990,'Migration fixture','', 'ACTIVE','America/Guayaquil','from-internal','MANUAL','test',UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT INTO gc_client (id,campaign_id,import_batch_id,external_key,display_name,state,terminal,priority,custom_data_json,next_action_at,last_attempt_at,managed_at,created_at,updated_at,row_version)
VALUES (990,990,NULL,'orphan-fixture','Orphan fixture','IN_PROGRESS',0,0,'{}',NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1);
SQL

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
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME IN ('gc_schema_version','gc_campaign','gc_import_batch','gc_client','gc_client_phone','gc_agent_map','gc_sip_seat','gc_work_session','gc_assignment','gc_client_claim','gc_outcome','gc_attempt','gc_callback','gc_client_event','gc_import_error','gc_idempotency','gc_operational_status')" "17"
assert_query "attempt snapshot columns exist" \
  "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME IN ('work_session_id','agent_sip_extension')" "2"
assert_query "CDR retry scheduling columns exist" \
  "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME IN ('cdr_retry_count','cdr_next_retry_at','cdr_last_checked_at','cdr_exhausted_at','cdr_last_error')" "5"
assert_query "agent extension has bounded VARCHAR storage" \
  "SELECT CONCAT(DATA_TYPE,':',CHARACTER_MAXIMUM_LENGTH) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='agent_sip_extension'" "varchar:20"
assert_query "permanent agent extension is optional" \
  "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_agent_map' AND COLUMN_NAME='sip_extension'" "YES"
assert_query "stable Issabel user identity index exists once" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_agent_map' AND INDEX_NAME='uq_gc_agent_issabel_user'" "1"
assert_query "work-session attempt index exists once" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_work_session'" "1"
assert_query "work-session attempt foreign key exists once" \
  "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND CONSTRAINT_NAME='fk_gc_attempt_work_session'" "1"
assert_query "unreconciled CDR index contains retry schedule" \
  "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_unreconciled'" "reconciled_at,cdr_next_retry_at,requested_at"
assert_query "CDR supervisor attention index exists once" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_cdr_attention'" "3"
assert_query "one-current-client-per-agent index exists once" \
  "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='gestion_clientes' AND TABLE_NAME='gc_client_claim' AND INDEX_NAME='uq_gc_claim_agent'" "1"
assert_query "default outcome seed remains idempotent" \
  "SELECT COUNT(*) FROM gc_outcome WHERE campaign_id IS NULL" "8"
assert_query "callback seed retains callback requirement" \
  "SELECT CONCAT(resulting_client_state,':',requires_callback) FROM gc_outcome WHERE campaign_id IS NULL AND code='CALLBACK'" "CALLBACK:1"
assert_query "non-callback outcomes remain informational" \
  "SELECT COUNT(*) FROM gc_outcome WHERE requires_callback=0 AND (resulting_client_state<>'PENDING' OR terminal<>0 OR mark_phone_invalid<>0)" "0"
assert_query "migration repairs a safely identifiable orphaned client" \
  "SELECT state FROM gc_client WHERE id=990" "PENDING"

assert_query "schema ledger records every migration" \
  "SELECT GROUP_CONCAT(version_num ORDER BY version_num SEPARATOR ',') FROM gc_schema_version" "1,2,3,4,5,6"

mysql_db_input <<'SQL'
INSERT INTO gc_agent_map (id,issabel_user_id,issabel_username,callcenter_agent_id,agent_number,sip_extension,active,verified_at,created_at,updated_at)
VALUES (990,'990','claim-fixture',NULL,'claim-fixture',NULL,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT INTO gc_assignment (id,campaign_id,client_id,agent_map_id,assignment_state,active_client_key,assigned_at,assigned_by)
VALUES (990,990,990,990,'ACTIVE',990,UTC_TIMESTAMP(),'test');
INSERT INTO gc_client_claim (client_id,assignment_id,agent_map_id,claim_token,claimed_at,expires_at)
VALUES (990,990,990,'00000000-0000-4000-8000-000000000990',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE));
SQL
if mysql_db_input <<'SQL' >/dev/null 2>&1
INSERT INTO gc_client (id,campaign_id,import_batch_id,external_key,display_name,state,terminal,priority,custom_data_json,created_at,updated_at,row_version)
VALUES (991,990,NULL,'second-claim','Second claim','PENDING',0,0,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP(),1);
INSERT INTO gc_assignment (id,campaign_id,client_id,agent_map_id,assignment_state,active_client_key,assigned_at,assigned_by)
VALUES (991,990,991,990,'ACTIVE',991,UTC_TIMESTAMP(),'test');
INSERT INTO gc_client_claim (client_id,assignment_id,agent_map_id,claim_token,claimed_at,expires_at)
VALUES (991,991,990,'00000000-0000-4000-8000-000000000991',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE));
SQL
then
  echo "FAIL: database allowed a second current client for one agent" >&2
  exit 1
else
  echo "ok - database rejects a second current client for one agent"
fi

echo "MariaDB fresh-install and repeat-migration lifecycle passed with $IMAGE."
