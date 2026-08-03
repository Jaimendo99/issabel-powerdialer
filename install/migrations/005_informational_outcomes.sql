-- Dispositions are call metadata. Only callback-required outcomes schedule work.
UPDATE gc_outcome
SET resulting_client_state=IF(requires_callback=1, 'CALLBACK', 'PENDING'),
    terminal=0,
    mark_phone_invalid=0,
    advance_to_next=1;

INSERT IGNORE INTO gc_schema_version (version_num, applied_at) VALUES (5, UTC_TIMESTAMP());
