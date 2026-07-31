-- Backward-compatible dynamic seat support for existing installations.
CREATE TABLE IF NOT EXISTS gc_sip_seat (
  sip_extension VARCHAR(20) NOT NULL,
  label VARCHAR(80) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (sip_extension),
  KEY idx_gc_sip_seat_active (active, sip_extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO gc_sip_seat (sip_extension, label, active, created_at, updated_at)
SELECT DISTINCT sip_extension, CONCAT('Extensión ', sip_extension), 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM gc_agent_map WHERE sip_extension REGEXP '^[0-9]{1,20}$';

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

SET @gc_add_work_session = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='work_session_id') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN work_session_id BIGINT UNSIGNED NULL AFTER agent_map_id',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_work_session; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_agent_extension = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='agent_sip_extension') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN agent_sip_extension VARCHAR(20) NULL AFTER work_session_id',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_agent_extension; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

UPDATE gc_attempt at
JOIN gc_agent_map am ON am.id=at.agent_map_id
SET at.agent_sip_extension=am.sip_extension
WHERE at.agent_sip_extension IS NULL;

SET @gc_add_ws_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_work_session') = 0,
  'ALTER TABLE gc_attempt ADD KEY idx_gc_attempt_work_session (work_session_id)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_ws_index; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_ws_fk = IF(
  (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND CONSTRAINT_NAME='fk_gc_attempt_work_session') = 0,
  'ALTER TABLE gc_attempt ADD CONSTRAINT fk_gc_attempt_work_session FOREIGN KEY (work_session_id) REFERENCES gc_work_session(id)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_ws_fk; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;
