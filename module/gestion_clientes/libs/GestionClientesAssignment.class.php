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
        return $this->db->transaction(function ($tx) use ($assignmentId, $newAgentMapId, $actor, $reason, $ip) {
            $row = $tx->fetchOne(
                'SELECT a.*, c.state, c.terminal FROM gc_assignment a JOIN gc_client c ON c.id=a.client_id WHERE a.id=? FOR UPDATE',
                array($assignmentId)
            );
            if (!$row || $row['assignment_state'] !== 'ACTIVE' || (int)$row['terminal'] === 1 || !in_array($row['state'], array('PENDING','NO_CONTACT','CALLBACK'), true)) {
                throw new RuntimeException('REASSIGNMENT_NOT_ELIGIBLE');
            }
            $tx->execute('UPDATE gc_assignment SET assignment_state=\'RELEASED\', active_client_key=NULL, released_at=UTC_TIMESTAMP(), release_reason=? WHERE id=?', array($reason, $assignmentId));
            $tx->execute('INSERT INTO gc_assignment (campaign_id, client_id, agent_map_id, assignment_state, active_client_key, assigned_at, assigned_by) VALUES (?, ?, ?, \'ACTIVE\', ?, UTC_TIMESTAMP(), ?)', array($row['campaign_id'], $row['client_id'], $newAgentMapId, $row['client_id'], $actor));
            $tx->audit($row['client_id'], $actor, 'CLIENT_REASSIGNED', $row['state'], $row['state'], array('from_agent' => $row['agent_map_id'], 'to_agent' => $newAgentMapId, 'reason' => $reason), $ip);
            return (int)$tx->pdo()->lastInsertId();
        });
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
