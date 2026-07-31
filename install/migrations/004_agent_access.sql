-- Stable Issabel identity and removal of the obsolete permanent-seat requirement.
SET @gc_add_issabel_user_id = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_agent_map' AND COLUMN_NAME='issabel_user_id') = 0,
  'ALTER TABLE gc_agent_map ADD COLUMN issabel_user_id VARCHAR(80) NULL AFTER id',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_issabel_user_id; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_make_sip_extension_optional = IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_agent_map' AND COLUMN_NAME='sip_extension') = 'NO',
  'ALTER TABLE gc_agent_map MODIFY sip_extension VARCHAR(40) NULL',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_make_sip_extension_optional; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

SET @gc_add_issabel_user_key = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gc_agent_map' AND INDEX_NAME='uq_gc_agent_issabel_user') = 0,
  'ALTER TABLE gc_agent_map ADD UNIQUE KEY uq_gc_agent_issabel_user (issabel_user_id)',
  'SELECT 1'
);
PREPARE gc_stmt FROM @gc_add_issabel_user_key; EXECUTE gc_stmt; DEALLOCATE PREPARE gc_stmt;

INSERT IGNORE INTO gc_schema_version (version_num, applied_at) VALUES (4, UTC_TIMESTAMP());
