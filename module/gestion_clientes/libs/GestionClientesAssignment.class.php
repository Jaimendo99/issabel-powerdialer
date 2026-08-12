<?php

class GestionClientesAssignment
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function preview($campaignId, $quantity)
    {
        $quantity = $this->quantity($quantity);
        return $this->db->fetchAll(
            'SELECT c.id, c.external_key, c.display_name, c.priority, c.created_at FROM gc_client c LEFT JOIN gc_assignment a ON a.client_id=c.id AND a.assignment_state=\'ACTIVE\' WHERE c.campaign_id=? AND c.terminal=0 AND a.id IS NULL ORDER BY c.priority DESC, c.created_at ASC, c.id ASC LIMIT ' . $quantity,
            array($campaignId)
        );
    }

    public function assign($campaignId, $agentMapId, $quantity, $actor, $ip)
    {
        $quantity = $this->quantity($quantity);
        return $this->db->transaction(function ($tx) use ($campaignId, $agentMapId, $quantity, $actor, $ip) {
            $campaign = $tx->fetchOne('SELECT id,status FROM gc_campaign WHERE id=? FOR UPDATE', array($campaignId));
            if (!$campaign || $campaign['status'] === 'CLOSED') throw new RuntimeException('CAMPAIGN_NOT_ASSIGNABLE');
            $agent = $tx->fetchOne('SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE', array($agentMapId));
            if (!$agent) {
                throw new RuntimeException('AGENT_MAPPING_INVALID');
            }
            $clients = $tx->fetchAll(
                'SELECT c.id, c.state FROM gc_client c LEFT JOIN gc_assignment a ON a.client_id=c.id AND a.assignment_state=\'ACTIVE\' WHERE c.campaign_id=? AND c.terminal=0 AND a.id IS NULL ORDER BY c.priority DESC, c.created_at ASC, c.id ASC LIMIT ' . $quantity . ' FOR UPDATE',
                array($campaignId)
            );
            foreach ($clients as $client) {
                $tx->execute(
                    'INSERT INTO gc_assignment (campaign_id, client_id, agent_map_id, assignment_state, active_client_key, assigned_at, assigned_by) VALUES (?, ?, ?, \'ACTIVE\', ?, UTC_TIMESTAMP(), ?)',
                    array($campaignId, $client['id'], $agentMapId, $client['id'], $actor)
                );
                $tx->audit($client['id'], $actor, 'CLIENT_ASSIGNED', $client['state'], $client['state'], array('agent_map_id' => $agentMapId), $ip);
            }
            return count($clients);
        });
    }

    public function reassign($assignmentId, $newAgentMapId, $actor, $reason, $ip)
    {
        $assignmentId = (int)$assignmentId;
        $newAgentMapId = (int)$newAgentMapId;
        $reason = trim((string)$reason);
        if ($assignmentId < 1 || $newAgentMapId < 1) throw new InvalidArgumentException('REASSIGNMENT_INVALID');
        if ($reason === '' || strlen($reason) > 255) throw new InvalidArgumentException('REASSIGNMENT_REASON_INVALID');
        return $this->db->transaction(function ($tx) use ($assignmentId, $newAgentMapId, $actor, $reason, $ip) {
            $row = $tx->fetchOne(
                'SELECT a.*, c.state, c.terminal, camp.status AS campaign_status FROM gc_assignment a JOIN gc_client c ON c.id=a.client_id JOIN gc_campaign camp ON camp.id=a.campaign_id WHERE a.id=? FOR UPDATE',
                array($assignmentId)
            );
            if (!$row || $row['assignment_state'] !== 'ACTIVE' || $row['campaign_status'] === 'CLOSED' || (int)$row['terminal'] === 1 || !in_array($row['state'], array('PENDING','NO_CONTACT','CALLBACK'), true)) {
                throw new RuntimeException('REASSIGNMENT_NOT_ELIGIBLE');
            }
            if ((int)$row['agent_map_id'] === $newAgentMapId) throw new RuntimeException('REASSIGNMENT_SAME_AGENT');
            $newAgent = $tx->fetchOne('SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE', array($newAgentMapId));
            if (!$newAgent) throw new RuntimeException('AGENT_MAPPING_INVALID');
            $claim = $tx->fetchOne('SELECT client_id FROM gc_client_claim WHERE client_id=? LIMIT 1 FOR UPDATE', array($row['client_id']));
            if ($claim) throw new RuntimeException('REASSIGNMENT_CLIENT_ACTIVE');
            $unresolvedAttempt = $tx->fetchOne(
                'SELECT id FROM gc_attempt WHERE assignment_id=? AND (ended_at IS NULL OR (business_outcome_id IS NULL AND (raw_error_code IS NULL OR raw_error_code NOT LIKE \'AMI_AGENT_%\'))) LIMIT 1 FOR UPDATE',
                array($assignmentId)
            );
            if ($unresolvedAttempt) throw new RuntimeException('REASSIGNMENT_CLIENT_ACTIVE');
            $tx->execute('UPDATE gc_assignment SET assignment_state=\'RELEASED\', active_client_key=NULL, released_at=UTC_TIMESTAMP(), release_reason=? WHERE id=?', array($reason, $assignmentId));
            $tx->execute('INSERT INTO gc_assignment (campaign_id, client_id, agent_map_id, assignment_state, active_client_key, assigned_at, assigned_by) VALUES (?, ?, ?, \'ACTIVE\', ?, UTC_TIMESTAMP(), ?)', array($row['campaign_id'], $row['client_id'], $newAgentMapId, $row['client_id'], $actor));
            $newAssignmentId = (int)$tx->pdo()->lastInsertId();
            $tx->execute('UPDATE gc_callback SET assignment_id=? WHERE client_id=? AND assignment_id=? AND status=\'OPEN\'', array($newAssignmentId, $row['client_id'], $assignmentId));
            $tx->audit($row['client_id'], $actor, 'CLIENT_REASSIGNED', $row['state'], $row['state'], array('from_agent' => $row['agent_map_id'], 'to_agent' => $newAgentMapId, 'reason' => $reason), $ip);
            return $newAssignmentId;
        });
    }

    public function activeAssignments($campaignId, $agentMapId, $query)
    {
        $sql = 'SELECT a.id AS assignment_id,a.campaign_id,a.agent_map_id,c.external_key,c.display_name,c.state,camp.name AS campaign_name,camp.status AS campaign_status,am.issabel_username AS agent_name,cb.due_at_utc AS callback_due_at,cb.timezone AS callback_timezone,'
            . ' EXISTS(SELECT 1 FROM gc_client_claim cl WHERE cl.client_id=c.id) AS has_claim,'
            . ' EXISTS(SELECT 1 FROM gc_attempt at WHERE at.assignment_id=a.id AND (at.ended_at IS NULL OR (at.business_outcome_id IS NULL AND (at.raw_error_code IS NULL OR at.raw_error_code NOT LIKE \'AMI_AGENT_%\')))) AS has_unresolved_attempt'
            . ' FROM gc_assignment a JOIN gc_client c ON c.id=a.client_id JOIN gc_campaign camp ON camp.id=a.campaign_id JOIN gc_agent_map am ON am.id=a.agent_map_id'
            . ' LEFT JOIN gc_callback cb ON cb.id=(SELECT MAX(cb2.id) FROM gc_callback cb2 WHERE cb2.client_id=c.id AND cb2.status=\'OPEN\')'
            . ' WHERE a.assignment_state=\'ACTIVE\' AND c.terminal=0';
        $params = array();
        if ((int)$campaignId > 0) { $sql .= ' AND a.campaign_id=?'; $params[] = (int)$campaignId; }
        if ((int)$agentMapId > 0) { $sql .= ' AND a.agent_map_id=?'; $params[] = (int)$agentMapId; }
        $query = trim((string)$query);
        if ($query !== '') {
            $sql .= ' AND (c.display_name LIKE ? OR c.external_key LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }
        return $this->db->fetchAll($sql . ' ORDER BY camp.name,c.display_name,c.id LIMIT 500', $params);
    }

    private function quantity($value)
    {
        $quantity = (int)$value;
        if ($quantity < 1 || $quantity > 10000) {
            throw new InvalidArgumentException('INVALID_QUANTITY');
        }
        return $quantity;
    }
}
