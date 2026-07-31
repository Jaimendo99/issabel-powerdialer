<?php

class GestionClientesAdmin
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function campaigns($query, $status)
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM gc_client cl WHERE cl.campaign_id=c.id) AS client_count FROM gc_campaign c WHERE 1=1';
        $params = array();
        if ($query !== '') {
            $sql .= ' AND c.name LIKE ?';
            $params[] = '%' . $query . '%';
        }
        if (in_array($status, array('DRAFT','ACTIVE','PAUSED','CLOSED'), true)) {
            $sql .= ' AND c.status=?';
            $params[] = $status;
        }
        return $this->db->fetchAll($sql . ' ORDER BY c.updated_at DESC, c.id DESC', $params);
    }

    public function saveCampaign($values, $actor)
    {
        $errors = GestionClientesValidator::validateCampaign($values);
        if (count($errors)) {
            throw new InvalidArgumentException(implode(',', $errors));
        }
        $status = isset($values['status']) && in_array($values['status'], array('DRAFT','ACTIVE','PAUSED','CLOSED'), true) ? $values['status'] : 'DRAFT';
        $mode = isset($values['dialing_mode']) && $values['dialing_mode'] === 'AUTO' ? 'AUTO' : 'MANUAL';
        $id = isset($values['id']) ? (int)$values['id'] : 0;
        if ($id > 0) {
            $this->db->execute('UPDATE gc_campaign SET name=?, description=?, status=?, timezone=?, outbound_context=?, dialing_mode=?, updated_at=UTC_TIMESTAMP() WHERE id=?', array(trim($values['name']), trim(isset($values['description']) ? $values['description'] : ''), $status, $values['timezone'], $values['outbound_context'], $mode, $id));
            return $id;
        }
        $this->db->execute('INSERT INTO gc_campaign (name, description, status, timezone, outbound_context, dialing_mode, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', array(trim($values['name']), trim(isset($values['description']) ? $values['description'] : ''), $status, $values['timezone'], $values['outbound_context'], $mode, $actor));
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function saveAgentMap($values)
    {
        $username = trim(isset($values['issabel_username']) ? $values['issabel_username'] : '');
        $agent = trim(isset($values['agent_number']) ? $values['agent_number'] : '');
        $extension = trim(isset($values['sip_extension']) ? $values['sip_extension'] : '');
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $username) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $agent) || !preg_match('/^[0-9]{1,20}$/', $extension)) {
            throw new InvalidArgumentException('INVALID_AGENT_MAPPING');
        }
        $id = isset($values['id']) ? (int)$values['id'] : 0;
        $active = empty($values['active']) ? 0 : 1;
        if ($active) {
            $duplicate = $this->db->fetchOne('SELECT id FROM gc_agent_map WHERE issabel_username=? AND active=1 AND id<>?', array($username, $id));
            if ($duplicate) {
                throw new RuntimeException('AMBIGUOUS_AGENT_MAPPING');
            }
        }
        if ($id) {
            $this->db->execute('UPDATE gc_agent_map SET issabel_username=?, callcenter_agent_id=?, agent_number=?, sip_extension=?, active=?, updated_at=UTC_TIMESTAMP() WHERE id=?', array($username, empty($values['callcenter_agent_id']) ? null : (int)$values['callcenter_agent_id'], $agent, $extension, $active, $id));
            return $id;
        }
        $this->db->execute('INSERT INTO gc_agent_map (issabel_username, callcenter_agent_id, agent_number, sip_extension, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', array($username, empty($values['callcenter_agent_id']) ? null : (int)$values['callcenter_agent_id'], $agent, $extension, $active));
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function callbacks($agentMapId)
    {
        $sql = 'SELECT cb.*, c.display_name AS client_name, am.agent_number, CASE WHEN cb.due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue FROM gc_callback cb JOIN gc_client c ON c.id=cb.client_id JOIN gc_assignment a ON a.id=cb.assignment_id JOIN gc_agent_map am ON am.id=a.agent_map_id WHERE cb.status=\'OPEN\'';
        $params = array();
        if ($agentMapId !== null) {
            $sql .= ' AND a.agent_map_id=?';
            $params[] = $agentMapId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY cb.due_at_utc ASC LIMIT 500', $params);
    }

    public function audit($user, $event)
    {
        $sql = 'SELECT * FROM gc_client_event WHERE 1=1';
        $params = array();
        if ($user !== '') { $sql .= ' AND actor_username=?'; $params[] = $user; }
        if ($event !== '') { $sql .= ' AND event_type=?'; $params[] = $event; }
        return $this->db->fetchAll($sql . ' ORDER BY created_at DESC, id DESC LIMIT 500', $params);
    }
}
