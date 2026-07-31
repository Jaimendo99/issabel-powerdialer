<?php

/** Binds an authenticated Issabel agent session to an administrator-approved SIP seat. */
class GestionClientesSeatSession
{
    private $db;
    private $ttl;

    public function __construct($db, $ttl)
    {
        $this->db = $db;
        $this->ttl = max(300, min(43200, (int)$ttl));
    }

    public function activeSeats()
    {
        return $this->db->fetchAll(
            'SELECT sip_extension, label FROM gc_sip_seat WHERE active=1 ORDER BY (sip_extension+0), sip_extension',
            array()
        );
    }

    public function current($agentMapId, $touch)
    {
        $hash = $this->sessionHash();
        $row = $this->db->fetchOne(
            'SELECT ws.id, ws.agent_map_id, ws.sip_extension, s.label, ws.selected_at, ws.expires_at FROM gc_work_session ws JOIN gc_sip_seat s ON s.sip_extension=ws.sip_extension AND s.active=1 WHERE ws.agent_map_id=? AND ws.session_hash=? AND ws.released_at IS NULL AND ws.expires_at>UTC_TIMESTAMP() LIMIT 1',
            array((int)$agentMapId, $hash)
        );
        if ($row && $touch) {
            $this->db->execute(
                'UPDATE gc_work_session SET last_seen_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $this->ttl . ' SECOND) WHERE id=? AND released_at IS NULL',
                array($row['id'])
            );
        }
        return $row;
    }

    public function select($agentMapId, $extension)
    {
        $extension = trim((string)$extension);
        if (!preg_match('/^[0-9]{1,20}$/', $extension)) {
            throw new RuntimeException('SEAT_INVALID');
        }
        $hash = $this->sessionHash();
        $ttl = $this->ttl;
        return $this->db->transaction(function ($tx) use ($agentMapId, $extension, $hash, $ttl) {
            $tx->execute('UPDATE gc_work_session ws SET ws.active_extension=NULL, ws.released_at=UTC_TIMESTAMP() WHERE ws.active_extension IS NOT NULL AND ws.expires_at<=UTC_TIMESTAMP() AND NOT EXISTS (SELECT 1 FROM gc_attempt at WHERE at.work_session_id=ws.id AND at.ended_at IS NULL AND at.technical_state IN (\'CREATED\',\'ORIGINATED\',\'RINGING\',\'ANSWERED\'))', array());
            $seat = $tx->fetchOne('SELECT sip_extension, label FROM gc_sip_seat WHERE sip_extension=? AND active=1 FOR UPDATE', array($extension));
            if (!$seat) {
                throw new RuntimeException('SEAT_NOT_ALLOWED');
            }
            $agent = $tx->fetchOne('SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE', array((int)$agentMapId));
            if (!$agent) {
                throw new RuntimeException('AGENT_MAPPING_REQUIRED');
            }
            $existing = $tx->fetchOne('SELECT id, sip_extension FROM gc_work_session WHERE agent_map_id=? AND session_hash=? AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE', array((int)$agentMapId, $hash));
            if ($existing && $existing['sip_extension'] === $extension) {
                $tx->execute('UPDATE gc_work_session SET last_seen_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $ttl . ' SECOND) WHERE id=?', array($existing['id']));
                return array('id'=>$existing['id'], 'sip_extension'=>$extension, 'label'=>$seat['label']);
            }
            if ($existing && $this->hasActiveAttempt($tx, $existing['id'])) {
                throw new RuntimeException('SEAT_HAS_ACTIVE_CALL');
            }
            $occupied = $tx->fetchOne('SELECT id FROM gc_work_session WHERE active_extension=? AND released_at IS NULL FOR UPDATE', array($extension));
            if ($occupied) {
                throw new RuntimeException('SEAT_IN_USE');
            }
            if ($existing) {
                $tx->execute('UPDATE gc_work_session SET active_extension=NULL, released_at=UTC_TIMESTAMP() WHERE id=?', array($existing['id']));
            }
            $tx->execute('INSERT INTO gc_work_session (agent_map_id, session_hash, sip_extension, active_extension, selected_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $ttl . ' SECOND))', array((int)$agentMapId, $hash, $extension, $extension));
            return array('id'=>$tx->pdo()->lastInsertId(), 'sip_extension'=>$extension, 'label'=>$seat['label']);
        });
    }

    public function release($agentMapId)
    {
        $hash = $this->sessionHash();
        return $this->db->transaction(function ($tx) use ($agentMapId, $hash) {
            $current = $tx->fetchOne('SELECT id FROM gc_work_session WHERE agent_map_id=? AND session_hash=? AND released_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE', array((int)$agentMapId, $hash));
            if (!$current) return false;
            if ($this->hasActiveAttempt($tx, $current['id'])) {
                throw new RuntimeException('SEAT_HAS_ACTIVE_CALL');
            }
            $tx->execute('UPDATE gc_work_session SET active_extension=NULL, released_at=UTC_TIMESTAMP() WHERE id=?', array($current['id']));
            return true;
        });
    }

    private function hasActiveAttempt($db, $workSessionId)
    {
        return (bool)$db->fetchOne('SELECT id FROM gc_attempt WHERE work_session_id=? AND ended_at IS NULL AND technical_state IN (\'CREATED\',\'ORIGINATED\',\'RINGING\',\'ANSWERED\') LIMIT 1', array((int)$workSessionId));
    }

    private function sessionHash()
    {
        $id = session_id();
        if ($id === '') throw new RuntimeException('AUTH_REQUIRED');
        return hash('sha256', 'gestion_clientes|' . $id);
    }
}
