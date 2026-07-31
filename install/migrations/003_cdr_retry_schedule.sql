-- Prevent permanently unmatched CDRs from monopolizing every bounded batch.
SET @gc_add_cdr_retry_count = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='cdr_retry_count') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN cdr_retry_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER raw_error_code',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_retry_count; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_cdr_next_retry = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND COLUMN_NAME='cdr_next_retry_at') = 0,
  'ALTER TABLE gc_attempt ADD COLUMN cdr_next_retry_at DATETIME NULL AFTER cdr_retry_count',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_cdr_next_retry; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_drop_old_unreconciled_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_unreconciled') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_unreconciled' AND COLUMN_NAME='cdr_next_retry_at') = 0,
  'ALTER TABLE gc_attempt DROP INDEX idx_gc_attempt_unreconciled',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_drop_old_unreconciled_index; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_unreconciled_index = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_attempt' AND INDEX_NAME='idx_gc_attempt_unreconciled') = 0,
  'ALTER TABLE gc_attempt ADD KEY idx_gc_attempt_unreconciled (reconciled_at, cdr_next_retry_at, requested_at)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_unreconciled_index; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

INSERT IGNORE INTO gc_schema_version (version_num, applied_at) VALUES (3, UTC_TIMESTAMP());
