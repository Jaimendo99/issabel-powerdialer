<?php

class GestionClientesStats
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function campaignSummary($campaignId, $fromUtc, $toUtc)
    {
        $clients = $this->db->fetchOne(
            'SELECT COUNT(*) AS imported, SUM(CASE WHEN terminal=1 THEN 1 ELSE 0 END) AS managed, SUM(CASE WHEN terminal=0 AND (next_action_at IS NULL OR next_action_at<=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN state=\'SALE\' THEN 1 ELSE 0 END) AS sales, SUM(CASE WHEN state=\'INTERESTED\' THEN 1 ELSE 0 END) AS interested FROM gc_client WHERE campaign_id=?',
            array($campaignId)
        );
        $assignments = $this->db->fetchOne('SELECT COUNT(*) AS assigned FROM gc_assignment WHERE campaign_id=? AND assignment_state=\'ACTIVE\'', array($campaignId));
        $calls = $this->db->fetchOne(
            'SELECT COUNT(*) AS total_calls, SUM(CASE WHEN technical_state=\'FAILED\' THEN 1 ELSE 0 END) AS rejected, SUM(CASE WHEN technical_state=\'ANSWERED\' THEN 1 ELSE 0 END) AS answered, SUM(CASE WHEN technical_state IN (\'NO_ANSWER\',\'BUSY\') THEN 1 ELSE 0 END) AS not_answered, COALESCE(SUM(talk_seconds),0) AS talk_seconds FROM gc_attempt WHERE campaign_id=? AND requested_at>=? AND requested_at<? AND (raw_error_code IS NULL OR LEFT(raw_error_code,10)<>\'AMI_AGENT_\')',
            array($campaignId, $fromUtc, $toUtc)
        );
        $callbacks = $this->db->fetchOne(
            'SELECT SUM(CASE WHEN due_at_utc>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS future, SUM(CASE WHEN due_at_utc<=UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS due FROM gc_callback cb JOIN gc_assignment a ON a.id=cb.assignment_id WHERE a.campaign_id=? AND cb.status=\'OPEN\'',
            array($campaignId)
        );
        return array_merge($clients, $assignments, $calls, $callbacks);
    }

    public function agentProgress($campaignId)
    {
        return $this->db->fetchAll(
            'SELECT am.id, am.issabel_username, am.agent_number, COUNT(a.id) AS assigned_total, SUM(CASE WHEN a.assignment_state=\'COMPLETED\' THEN 1 ELSE 0 END) AS managed, CASE WHEN COUNT(a.id)=0 THEN 0 ELSE ROUND(100 * SUM(CASE WHEN a.assignment_state=\'COMPLETED\' THEN 1 ELSE 0 END) / COUNT(a.id), 1) END AS progress_percent FROM gc_agent_map am LEFT JOIN gc_assignment a ON a.agent_map_id=am.id AND a.campaign_id=? GROUP BY am.id, am.issabel_username, am.agent_number ORDER BY am.agent_number',
            array($campaignId)
        );
    }

    public function outcomeBreakdown($campaignId, $fromUtc, $toUtc)
    {
        return $this->db->fetchAll(
            'SELECT COALESCE(o.label, \'Sin resultado\') AS label, COUNT(*) AS total FROM gc_attempt at LEFT JOIN gc_outcome o ON o.id=at.business_outcome_id WHERE at.campaign_id=? AND at.requested_at>=? AND at.requested_at<? AND (at.raw_error_code IS NULL OR LEFT(at.raw_error_code,10)<>\'AMI_AGENT_\') GROUP BY o.id, o.label ORDER BY total DESC',
            array($campaignId, $fromUtc, $toUtc)
        );
    }
}
