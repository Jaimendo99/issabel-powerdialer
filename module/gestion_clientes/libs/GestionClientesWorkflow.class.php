<?php

class GestionClientesWorkflow
{
    private $db;
    private $dialer;
    private $claimTtl;

    public function __construct($db, $dialer, $claimTtl)
    {
        $this->db = $db;
        $this->dialer = $dialer;
        $this->claimTtl = max(60, min(3600, (int)$claimTtl));
    }

    public function currentClient($agentMapId)
    {
        $row = $this->db->fetchOne(
            'SELECT c.*, a.id AS assignment_id, cl.claim_token, cl.expires_at FROM gc_client_claim cl JOIN gc_client c ON c.id=cl.client_id JOIN gc_assignment a ON a.id=cl.assignment_id WHERE cl.agent_map_id=? AND cl.expires_at>UTC_TIMESTAMP() AND a.assignment_state=\'ACTIVE\' ORDER BY cl.claimed_at DESC LIMIT 1',
            array($agentMapId)
        );
        return $row ? $this->hydrateClient($row) : null;
    }

    public function claimNext($agentMapId, $actor, $ip)
    {
        $ttl = $this->claimTtl;
        $self = $this;
        return $this->db->transaction(function ($tx) use ($agentMapId, $actor, $ip, $ttl, $self) {
            $tx->execute('DELETE FROM gc_client_claim WHERE expires_at<=UTC_TIMESTAMP()', array());
            $existing = $tx->fetchOne(
                'SELECT c.*, a.id AS assignment_id, cl.claim_token, cl.expires_at FROM gc_client_claim cl JOIN gc_client c ON c.id=cl.client_id JOIN gc_assignment a ON a.id=cl.assignment_id WHERE cl.agent_map_id=? AND cl.expires_at>UTC_TIMESTAMP() AND a.assignment_state=\'ACTIVE\' ORDER BY cl.claimed_at DESC LIMIT 1 FOR UPDATE',
                array($agentMapId)
            );
            if ($existing) {
                return $self->hydrateClient($existing);
            }
            $client = $tx->fetchOne(
                'SELECT c.*, a.id AS assignment_id FROM gc_assignment a JOIN gc_client c ON c.id=a.client_id LEFT JOIN gc_client_claim cl ON cl.client_id=c.id WHERE a.agent_map_id=? AND a.assignment_state=\'ACTIVE\' AND c.terminal=0 AND c.state IN (\'PENDING\',\'NO_CONTACT\',\'CALLBACK\') AND cl.client_id IS NULL AND (c.state<>\'CALLBACK\' OR EXISTS (SELECT 1 FROM gc_callback due_cb WHERE due_cb.assignment_id=a.id AND due_cb.status=\'OPEN\' AND due_cb.due_at_utc<=UTC_TIMESTAMP())) ORDER BY CASE WHEN EXISTS (SELECT 1 FROM gc_callback due_cb WHERE due_cb.assignment_id=a.id AND due_cb.status=\'OPEN\' AND due_cb.due_at_utc<=UTC_TIMESTAMP()) THEN 0 ELSE 1 END, c.priority DESC, a.assigned_at ASC, c.id ASC LIMIT 1 FOR UPDATE',
                array($agentMapId)
            );
            if (!$client) {
                return null;
            }
            $token = $tx->uuid();
            $tx->execute(
                'INSERT INTO gc_client_claim (client_id, assignment_id, agent_map_id, claim_token, claimed_at, expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $ttl . ' SECOND))',
                array($client['id'], $client['assignment_id'], $agentMapId, $token)
            );
            $previous = $client['state'];
            $tx->execute('UPDATE gc_client SET state=\'IN_PROGRESS\', updated_at=UTC_TIMESTAMP(), row_version=row_version+1 WHERE id=? AND terminal=0', array($client['id']));
            $tx->audit($client['id'], $actor, 'CLIENT_CLAIMED', $previous, 'IN_PROGRESS', array('claim_token' => $token), $ip);
            $client['state'] = 'IN_PROGRESS';
            $client['claim_token'] = $token;
            return $self->hydrateClient($client);
        });
    }

    public function startCall($agent, $seatSessionId, $clientId, $phoneId, $claimToken, $idempotencyKey, $actor, $ip)
    {
        if (!GestionClientesValidator::safeIdempotencyKey($idempotencyKey)) {
            throw new InvalidArgumentException('INVALID_IDEMPOTENCY_KEY');
        }
        $db = $this->db;
        $attempt = $db->transaction(function ($tx) use ($agent, $seatSessionId, $clientId, $phoneId, $claimToken, $idempotencyKey, $actor, $ip) {
            $existing = $tx->fetchOne('SELECT * FROM gc_attempt WHERE agent_map_id=? AND idempotency_key=?', array($agent['id'], $idempotencyKey));
            if ($existing) {
                // An accepted AMI request can outlive the PHP request.  Never send it
                // again merely because the post-originate state update was interrupted.
                $existing['_idempotent_replay'] = true;
                return $existing;
            }
            $row = $tx->fetchOne(
                'SELECT c.campaign_id, c.terminal, a.id AS assignment_id, p.normalized_value, p.state AS phone_state FROM gc_client c JOIN gc_assignment a ON a.client_id=c.id AND a.assignment_state=\'ACTIVE\' JOIN gc_client_claim cl ON cl.client_id=c.id AND cl.assignment_id=a.id JOIN gc_client_phone p ON p.client_id=c.id WHERE c.id=? AND p.id=? AND a.agent_map_id=? AND cl.claim_token=? AND cl.expires_at>UTC_TIMESTAMP() FOR UPDATE',
                array($clientId, $phoneId, $agent['id'], $claimToken)
            );
            if (!$row || (int)$row['terminal'] === 1) {
                throw new RuntimeException('CALL_OWNERSHIP_INVALID');
            }
            if (in_array($row['phone_state'], array('INVALID','DO_NOT_CALL'), true)) {
                throw new RuntimeException('PHONE_NOT_ELIGIBLE');
            }
            // Lock stable parent rows: a SELECT on an empty attempt set is not a mutex.
            $assignmentLock = $tx->fetchOne('SELECT id FROM gc_assignment WHERE id=? AND agent_map_id=? AND assignment_state=\'ACTIVE\' FOR UPDATE', array($row['assignment_id'], $agent['id']));
            if (!$assignmentLock) {
                throw new RuntimeException('CALL_OWNERSHIP_INVALID');
            }
            $agentLock = $tx->fetchOne('SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE', array($agent['id']));
            if (!$agentLock) {
                throw new RuntimeException('AGENT_MAPPING_REQUIRED');
            }
            $seat = $tx->fetchOne('SELECT ws.id, ws.sip_extension FROM gc_work_session ws JOIN gc_sip_seat s ON s.sip_extension=ws.sip_extension AND s.active=1 WHERE ws.id=? AND ws.agent_map_id=? AND ws.released_at IS NULL AND ws.expires_at>UTC_TIMESTAMP() FOR UPDATE', array((int)$seatSessionId, $agent['id']));
            if (!$seat) {
                throw new RuntimeException('SEAT_SELECTION_REQUIRED');
            }
            $recent = $tx->fetchOne('SELECT id FROM gc_attempt WHERE agent_map_id=? AND requested_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) ORDER BY id DESC LIMIT 1', array($agent['id']));
            if ($recent) {
                throw new RuntimeException('CALL_RATE_LIMITED');
            }
            $active = $tx->fetchOne('SELECT id FROM gc_attempt WHERE agent_map_id=? AND ended_at IS NULL AND technical_state IN (\'CREATED\',\'ORIGINATED\',\'RINGING\',\'ANSWERED\') LIMIT 1', array($agent['id']));
            if ($active) {
                throw new RuntimeException('ACTIVE_ATTEMPT_EXISTS');
            }
            $token = $tx->uuid();
            // Asterisk's production CDR accountcode is VARCHAR(20).  Keep the full
            // UUID in userfield and use a compact, independently indexed accountcode.
            $account = 'GC-' . substr(str_replace('-', '', strtolower($token)), 0, 17);
            $tx->execute(
                'INSERT INTO gc_attempt (campaign_id, client_id, phone_id, assignment_id, agent_map_id, work_session_id, agent_sip_extension, correlation_token, idempotency_key, requested_at, technical_state, cdr_accountcode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), \'CREATED\', ?)',
                array($row['campaign_id'], $clientId, $phoneId, $row['assignment_id'], $agent['id'], $seat['id'], $seat['sip_extension'], $token, $idempotencyKey, $account)
            );
            $attemptId = $tx->pdo()->lastInsertId();
            $tx->execute('UPDATE gc_client_phone SET state=\'ATTEMPTED\', attempt_count=attempt_count+1, last_attempt_at=UTC_TIMESTAMP() WHERE id=?', array($phoneId));
            $tx->execute('UPDATE gc_client SET last_attempt_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), row_version=row_version+1 WHERE id=?', array($clientId));
            $tx->audit($clientId, $actor, 'CALL_REQUESTED', 'IN_PROGRESS', 'IN_PROGRESS', array('attempt_id' => $attemptId, 'phone_id' => $phoneId, 'sip_extension' => $seat['sip_extension']), $ip);
            return array('id' => $attemptId, 'correlation_token' => $token, 'cdr_accountcode' => $account, 'normalized_value' => $row['normalized_value'], 'agent_sip_extension' => $seat['sip_extension'], 'technical_state' => 'CREATED');
        });
        if (!empty($attempt['_idempotent_replay'])) {
            unset($attempt['_idempotent_replay']);
            return $attempt;
        }
        if ($attempt['technical_state'] !== 'CREATED') {
            return $attempt;
        }
        $dialNumber = GestionClientesValidator::toDialString($attempt['normalized_value']);
        if ($dialNumber === false) {
            $db->execute('UPDATE gc_attempt SET technical_state=\'FAILED\', ended_at=UTC_TIMESTAMP(), raw_error_code=\'INVALID_DIAL_NUMBER\' WHERE id=? AND technical_state=\'CREATED\'', array($attempt['id']));
            throw new RuntimeException('PHONE_NOT_ELIGIBLE');
        }
        try {
            $result = $this->dialer->originate($attempt['agent_sip_extension'], $dialNumber, $attempt['correlation_token'], $attempt['cdr_accountcode']);
            $db->execute('UPDATE gc_attempt SET technical_state=\'ORIGINATED\', originated_at=UTC_TIMESTAMP() WHERE id=? AND technical_state=\'CREATED\'', array($attempt['id']));
            $attempt['technical_state'] = 'ORIGINATED';
            $attempt['ami'] = $result;
        } catch (Exception $e) {
            $db->execute('UPDATE gc_attempt SET technical_state=\'FAILED\', ended_at=UTC_TIMESTAMP(), raw_error_code=\'AMI_ORIGINATE_FAILED\' WHERE id=? AND technical_state=\'CREATED\'', array($attempt['id']));
            $attempt['technical_state'] = 'FAILED';
            throw new RuntimeException('AMI_ORIGINATE_FAILED');
        }
        return $attempt;
    }

    public function saveOutcome($agentMapId, $attemptId, $outcomeId, $note, $callback, $actor, $ip)
    {
        return $this->db->transaction(function ($tx) use ($agentMapId, $attemptId, $outcomeId, $note, $callback, $actor, $ip) {
            $attempt = $tx->fetchOne(
                'SELECT at.*, c.state AS client_state, c.terminal AS client_terminal FROM gc_attempt at JOIN gc_client c ON c.id=at.client_id JOIN gc_assignment a ON a.id=at.assignment_id WHERE at.id=? AND at.agent_map_id=? AND a.assignment_state=\'ACTIVE\' FOR UPDATE',
                array($attemptId, $agentMapId)
            );
            if (!$attempt) {
                throw new RuntimeException('ATTEMPT_OWNERSHIP_INVALID');
            }
            if ($attempt['business_outcome_id'] !== null) {
                $retryNote = trim($note);
                if ((int)$attempt['business_outcome_id'] === (int)$outcomeId && trim((string)$attempt['agent_note']) === '' && $retryNote !== '') {
                    $tx->execute('UPDATE gc_attempt SET agent_note=? WHERE id=? AND (agent_note IS NULL OR agent_note=\'\')', array($retryNote, $attemptId));
                }
                return array('attempt_id' => (int)$attemptId, 'already_saved' => true);
            }
            if ($attempt['ended_at'] === null || !in_array($attempt['technical_state'], array('ANSWERED','BUSY','NO_ANSWER','FAILED','CANCELED','AMBIGUOUS'), true)) {
                throw new RuntimeException('ATTEMPT_NOT_FINISHED');
            }
            $outcome = $tx->fetchOne(
                'SELECT * FROM gc_outcome WHERE id=? AND active=1 AND (campaign_id IS NULL OR campaign_id=?)',
                array($outcomeId, $attempt['campaign_id'])
            );
            $errors = GestionClientesValidator::validateOutcome($outcome, $callback);
            if (count($errors)) {
                throw new InvalidArgumentException($errors[0]);
            }
            $newState = $outcome['resulting_client_state'];
            if ((int)$outcome['mark_phone_invalid'] === 1) {
                $tx->execute('UPDATE gc_client_phone SET state=\'INVALID\' WHERE id=?', array($attempt['phone_id']));
                $usable = $tx->fetchOne('SELECT COUNT(*) AS total FROM gc_client_phone WHERE client_id=? AND state NOT IN (\'INVALID\',\'DO_NOT_CALL\')', array($attempt['client_id']));
                if ((int)$usable['total'] === 0) {
                    $newState = 'INVALID';
                    $outcome['terminal'] = 1;
                }
            }
            $tx->execute('UPDATE gc_attempt SET business_outcome_id=?, agent_note=? WHERE id=?', array($outcomeId, trim($note), $attemptId));
            $tx->execute('UPDATE gc_callback SET status=\'COMPLETED\', completed_at=UTC_TIMESTAMP() WHERE assignment_id=? AND status=\'OPEN\'', array($attempt['assignment_id']));
            $nextAction = null;
            if ((int)$outcome['requires_callback'] === 1) {
                $nextAction = GestionClientesValidator::localToUtc($callback['due_at'], $callback['timezone']);
                $tx->execute(
                    'INSERT INTO gc_callback (client_id, assignment_id, attempt_id, due_at_utc, timezone, status, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, \'OPEN\', ?, ?, UTC_TIMESTAMP())',
                    array($attempt['client_id'], $attempt['assignment_id'], $attemptId, $nextAction, $callback['timezone'], trim($callback['note']), $actor)
                );
            }
            $managed = (int)$outcome['terminal'] === 1 ? 'UTC_TIMESTAMP()' : 'NULL';
            $tx->execute(
                'UPDATE gc_client SET state=?, terminal=?, next_action_at=?, managed_at=' . $managed . ', updated_at=UTC_TIMESTAMP(), row_version=row_version+1 WHERE id=?',
                array($newState, (int)$outcome['terminal'], $nextAction, $attempt['client_id'])
            );
            if ((int)$outcome['terminal'] === 1) {
                $tx->execute('UPDATE gc_assignment SET assignment_state=\'COMPLETED\', active_client_key=NULL, released_at=UTC_TIMESTAMP(), release_reason=\'TERMINAL_OUTCOME\' WHERE id=?', array($attempt['assignment_id']));
            }
            if ((int)$outcome['advance_to_next'] === 1 || (int)$outcome['terminal'] === 1) {
                $tx->execute('DELETE FROM gc_client_claim WHERE client_id=? AND agent_map_id=?', array($attempt['client_id'], $agentMapId));
            }
            $tx->audit($attempt['client_id'], $actor, 'OUTCOME_SAVED', $attempt['client_state'], $newState, array('attempt_id' => $attemptId, 'outcome_id' => $outcomeId, 'callback_at_utc' => $nextAction), $ip);
            return array('attempt_id' => (int)$attemptId, 'client_state' => $newState, 'terminal' => (bool)$outcome['terminal'], 'advance' => (bool)$outcome['advance_to_next']);
        });
    }

    private function hydrateClient($row)
    {
        $row['custom_data'] = json_decode($row['custom_data_json'], true);
        if (!is_array($row['custom_data'])) {
            $row['custom_data'] = array();
        }
        $row['phones'] = $this->db->fetchAll('SELECT * FROM gc_client_phone WHERE client_id=? ORDER BY sort_order, id', array($row['id']));
        $row['history'] = $this->db->fetchAll('SELECT at.*, o.label AS outcome_label FROM gc_attempt at LEFT JOIN gc_outcome o ON o.id=at.business_outcome_id WHERE at.client_id=? ORDER BY at.requested_at DESC, at.id DESC LIMIT 50', array($row['id']));
        return $row;
    }
}
