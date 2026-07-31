CREATE DATABASE IF NOT EXISTS `gestion_clientes`
  DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `gestion_clientes`;

CREATE TABLE IF NOT EXISTS gc_schema_version (
  version_num INT NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (version_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_campaign (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('DRAFT','ACTIVE','PAUSED','CLOSED') NOT NULL DEFAULT 'DRAFT',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Guayaquil',
  outbound_context VARCHAR(80) NOT NULL DEFAULT 'from-internal',
  default_phone_order VARCHAR(255) NULL,
  dialing_mode ENUM('MANUAL','AUTO') NOT NULL DEFAULT 'MANUAL',
  created_by VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_gc_campaign_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_import_batch (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  field_mapping_json LONGTEXT NOT NULL,
  imported_by VARCHAR(80) NOT NULL,
  imported_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_import_hash (campaign_id, file_hash),
  CONSTRAINT fk_gc_import_campaign FOREIGN KEY (campaign_id) REFERENCES gc_campaign(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_client (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  import_batch_id BIGINT UNSIGNED NULL,
  external_key VARCHAR(191) NULL,
  display_name VARCHAR(255) NOT NULL,
  state ENUM('PENDING','IN_PROGRESS','NO_CONTACT','CALLBACK','INTERESTED','SALE','NOT_INTERESTED','INVALID','EXHAUSTED','CLOSED_OTHER') NOT NULL DEFAULT 'PENDING',
  terminal TINYINT(1) NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 0,
  custom_data_json LONGTEXT NOT NULL,
  next_action_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  managed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_client_external (campaign_id, external_key),
  KEY idx_gc_client_campaign_state (campaign_id, state, terminal, priority),
  KEY idx_gc_client_next_action (next_action_at),
  CONSTRAINT fk_gc_client_campaign FOREIGN KEY (campaign_id) REFERENCES gc_campaign(id),
  CONSTRAINT fk_gc_client_batch FOREIGN KEY (import_batch_id) REFERENCES gc_import_batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_client_phone (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  original_value VARCHAR(80) NOT NULL,
  normalized_value VARCHAR(32) NOT NULL,
  phone_type VARCHAR(40) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  state ENUM('AVAILABLE','ATTEMPTED','ANSWERED','NO_ANSWER','INVALID','DO_NOT_CALL') NOT NULL DEFAULT 'AVAILABLE',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_client_phone (client_id, normalized_value),
  KEY idx_gc_phone_normalized (normalized_value),
  CONSTRAINT fk_gc_phone_client FOREIGN KEY (client_id) REFERENCES gc_client(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_agent_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issabel_username VARCHAR(80) NOT NULL,
  callcenter_agent_id INT NULL,
  agent_number VARCHAR(40) NOT NULL,
  sip_extension VARCHAR(40) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_gc_agent_username_active (issabel_username, active),
  KEY idx_gc_agent_number (agent_number),
  KEY idx_gc_agent_extension (sip_extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_sip_seat (
  sip_extension VARCHAR(20) NOT NULL,
  label VARCHAR(80) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (sip_extension),
  KEY idx_gc_sip_seat_active (active, sip_extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_work_session (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_map_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL,
  sip_extension VARCHAR(20) NOT NULL,
  active_extension VARCHAR(20) NULL,
  selected_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  released_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_gc_work_session_hash (session_hash),
  UNIQUE KEY uq_gc_active_extension (active_extension),
  KEY idx_gc_work_session_agent (agent_map_id, expires_at),
  CONSTRAINT fk_gc_work_session_agent FOREIGN KEY (agent_map_id) REFERENCES gc_agent_map(id),
  CONSTRAINT fk_gc_work_session_seat FOREIGN KEY (sip_extension) REFERENCES gc_sip_seat(sip_extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_assignment (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  agent_map_id BIGINT UNSIGNED NOT NULL,
  assignment_state ENUM('ACTIVE','RELEASED','COMPLETED') NOT NULL DEFAULT 'ACTIVE',
  active_client_key BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL,
  assigned_by VARCHAR(80) NOT NULL,
  released_at DATETIME NULL,
  release_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_assignment_active_client (active_client_key),
  KEY idx_gc_assignment_agent_state (agent_map_id, assignment_state, assigned_at),
  KEY idx_gc_assignment_campaign (campaign_id, assignment_state),
  CONSTRAINT fk_gc_assignment_campaign FOREIGN KEY (campaign_id) REFERENCES gc_campaign(id),
  CONSTRAINT fk_gc_assignment_client FOREIGN KEY (client_id) REFERENCES gc_client(id),
  CONSTRAINT fk_gc_assignment_agent FOREIGN KEY (agent_map_id) REFERENCES gc_agent_map(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_client_claim (
  client_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  agent_map_id BIGINT UNSIGNED NOT NULL,
  claim_token CHAR(36) NOT NULL,
  claimed_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (client_id),
  UNIQUE KEY uq_gc_claim_token (claim_token),
  KEY idx_gc_claim_agent (agent_map_id, expires_at),
  CONSTRAINT fk_gc_claim_client FOREIGN KEY (client_id) REFERENCES gc_client(id),
  CONSTRAINT fk_gc_claim_assignment FOREIGN KEY (assignment_id) REFERENCES gc_assignment(id),
  CONSTRAINT fk_gc_claim_agent FOREIGN KEY (agent_map_id) REFERENCES gc_agent_map(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_outcome (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NULL,
  code VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  display_order SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  resulting_client_state VARCHAR(30) NOT NULL,
  terminal TINYINT(1) NOT NULL DEFAULT 0,
  requires_callback TINYINT(1) NOT NULL DEFAULT 0,
  mark_phone_invalid TINYINT(1) NOT NULL DEFAULT 0,
  advance_to_next TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_outcome_scope (campaign_id, code),
  KEY idx_gc_outcome_active (campaign_id, active, display_order),
  CONSTRAINT fk_gc_outcome_campaign FOREIGN KEY (campaign_id) REFERENCES gc_campaign(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_attempt (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  phone_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  agent_map_id BIGINT UNSIGNED NOT NULL,
  work_session_id BIGINT UNSIGNED NULL,
  agent_sip_extension VARCHAR(20) NULL,
  correlation_token CHAR(36) NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  requested_at DATETIME NOT NULL,
  originated_at DATETIME NULL,
  answered_at DATETIME NULL,
  ended_at DATETIME NULL,
  technical_state ENUM('CREATED','ORIGINATED','RINGING','ANSWERED','BUSY','NO_ANSWER','FAILED','CANCELED','AMBIGUOUS') NOT NULL DEFAULT 'CREATED',
  business_outcome_id BIGINT UNSIGNED NULL,
  asterisk_uniqueid VARCHAR(64) NULL,
  linkedid VARCHAR(64) NULL,
  cdr_accountcode VARCHAR(80) NOT NULL,
  duration_seconds INT UNSIGNED NULL,
  talk_seconds INT UNSIGNED NULL,
  recording_path VARCHAR(500) NULL,
  agent_note TEXT NULL,
  raw_error_code VARCHAR(100) NULL,
  reconciled_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_attempt_token (correlation_token),
  UNIQUE KEY uq_gc_attempt_idempotency (agent_map_id, idempotency_key),
  KEY idx_gc_attempt_agent_date (agent_map_id, requested_at, technical_state),
  KEY idx_gc_attempt_work_session (work_session_id),
  KEY idx_gc_attempt_outcome_date (business_outcome_id, requested_at),
  UNIQUE KEY uq_gc_attempt_cdr_accountcode (cdr_accountcode),
  KEY idx_gc_attempt_unreconciled (reconciled_at, requested_at),
  CONSTRAINT fk_gc_attempt_campaign FOREIGN KEY (campaign_id) REFERENCES gc_campaign(id),
  CONSTRAINT fk_gc_attempt_client FOREIGN KEY (client_id) REFERENCES gc_client(id),
  CONSTRAINT fk_gc_attempt_phone FOREIGN KEY (phone_id) REFERENCES gc_client_phone(id),
  CONSTRAINT fk_gc_attempt_assignment FOREIGN KEY (assignment_id) REFERENCES gc_assignment(id),
  CONSTRAINT fk_gc_attempt_agent FOREIGN KEY (agent_map_id) REFERENCES gc_agent_map(id),
  CONSTRAINT fk_gc_attempt_work_session FOREIGN KEY (work_session_id) REFERENCES gc_work_session(id),
  CONSTRAINT fk_gc_attempt_outcome FOREIGN KEY (business_outcome_id) REFERENCES gc_outcome(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_callback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  attempt_id BIGINT UNSIGNED NULL,
  due_at_utc DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL,
  status ENUM('OPEN','COMPLETED','CANCELED') NOT NULL DEFAULT 'OPEN',
  note TEXT NOT NULL,
  created_by VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  canceled_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_gc_callback_due (status, due_at_utc, assignment_id),
  CONSTRAINT fk_gc_callback_client FOREIGN KEY (client_id) REFERENCES gc_client(id),
  CONSTRAINT fk_gc_callback_assignment FOREIGN KEY (assignment_id) REFERENCES gc_assignment(id),
  CONSTRAINT fk_gc_callback_attempt FOREIGN KEY (attempt_id) REFERENCES gc_attempt(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_client_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  actor_username VARCHAR(80) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  previous_state VARCHAR(30) NULL,
  new_state VARCHAR(30) NULL,
  metadata_json LONGTEXT NOT NULL,
  source_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_gc_event_client_date (client_id, created_at),
  KEY idx_gc_event_type_date (event_type, created_at),
  CONSTRAINT fk_gc_event_client FOREIGN KEY (client_id) REFERENCES gc_client(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_import_error (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  row_number INT UNSIGNED NOT NULL,
  field_name VARCHAR(100) NULL,
  raw_value TEXT NULL,
  error_code VARCHAR(60) NOT NULL,
  message VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_gc_import_error_batch (batch_id, row_number),
  CONSTRAINT fk_gc_import_error_batch FOREIGN KEY (batch_id) REFERENCES gc_import_batch(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS gc_idempotency (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_username VARCHAR(80) NOT NULL,
  action_name VARCHAR(80) NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  response_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_idempotency (actor_username, action_name, idempotency_key),
  KEY idx_gc_idempotency_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO gc_schema_version (version_num, applied_at) VALUES (1, UTC_TIMESTAMP());
