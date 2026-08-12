-- Bounded CDR reconciliation and durable operational heartbeat.
SET @gc_add_cdr_last_checked = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='cdr_last_checked_at') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN cdr_last_checked_at DATETIME NULL AFTER cdr_next_retry_at',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_last_checked; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_cdr_exhausted = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='cdr_exhausted_at') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN cdr_exhausted_at DATETIME NULL AFTER cdr_last_checked_at',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_exhausted; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_cdr_last_error = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='cdr_last_error') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN cdr_last_error VARCHAR(100) NULL AFTER cdr_exhausted_at',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_last_error; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_cdr_attention_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_cdr_attention') = 0,
  'ALTER TABLE gc_attempt ADD KEY idx_gc_attempt_cdr_attention (cdr_exhausted_at, reconciled_at, requested_at)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_attention_index; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

CREATE TABLE IF NOT EXISTS gc_operational_status (
  component VARCHAR(60) NOT NULL,
  last_started_at DATETIME NULL,
  last_completed_at DATETIME NULL,
  last_status VARCHAR(20) NOT NULL,
  last_message VARCHAR(255) NULL,
  details_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (component),
  KEY idx_gc_operational_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- One durable agent identity may own only one current client. If legacy data
-- contains duplicates, fail closed here so an operator can review each claim.
SET @gc_add_claim_agent_unique = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_client_claim' AND INDEX_NAME='uq_gc_claim_agent') = 0,
  'ALTER TABLE gc_client_claim ADD UNIQUE KEY uq_gc_claim_agent (agent_map_id)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_claim_agent_unique; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

-- Current agent-only failures never created a customer CDR and need no fallback reconciliation.
UPDATE gc_attempt
SET reconciled_at=COALESCE(ended_at, UTC_TIMESTAMP()),
    cdr_next_retry_at=NULL,
    cdr_last_checked_at=UTC_TIMESTAMP(),
    cdr_last_error=NULL
WHERE reconciled_at IS NULL
  AND ended_at IS NOT NULL
  AND (raw_error_code LIKE 'AMI_AGENT_%' OR raw_error_code='DIALPLAN_CORRELATION_REJECTED');

-- Repair clients stranded by the pre-v6 behavior that deleted expired claims
-- without restoring the queue state. Never touch active calls or pending outcomes.
UPDATE gc_client c
LEFT JOIN gc_client_claim cl ON cl.client_id=c.id
LEFT JOIN gc_callback cb ON cb.id=(SELECT MAX(cb2.id) FROM gc_callback cb2 WHERE cb2.client_id=c.id AND cb2.status='OPEN')
SET c.state=IF(cb.id IS NULL, 'PENDING', 'CALLBACK'),
    c.next_action_at=cb.due_at_utc,
    c.updated_at=UTC_TIMESTAMP(),
    c.row_version=c.row_version+1
WHERE c.state='IN_PROGRESS'
  AND c.terminal=0
  AND cl.client_id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM gc_attempt at
    WHERE at.client_id=c.id
      AND (at.ended_at IS NULL OR (at.business_outcome_id IS NULL AND (at.raw_error_code IS NULL OR at.raw_error_code NOT LIKE 'AMI_AGENT_%')))
  );

INSERT IGNORE INTO gc_schema_version (version_num, applied_at) VALUES (6, UTC_TIMESTAMP());
