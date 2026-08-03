<?php

/** Finalizes the agent workflow as soon as the dedicated Dial() returns. */
class GestionClientesCallFinalizer
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function finalize($correlationToken, $dialStatus)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string)$correlationToken)) {
            throw new InvalidArgumentException('Invalid correlation token');
        }
        $technicalState = $this->technicalState($dialStatus);
        return $this->db->transaction(function ($tx) use ($correlationToken, $technicalState) {
            $attempt = $tx->fetchOne(
                'SELECT id,phone_id,technical_state,ended_at FROM gc_attempt WHERE correlation_token=? FOR UPDATE',
                array($correlationToken)
            );
            if (!$attempt || $attempt['ended_at'] !== null) return false;
            if (!in_array($attempt['technical_state'], array('CREATED','ORIGINATED','RINGING','ANSWERED','AMBIGUOUS'), true)) return false;

            $tx->execute(
                'UPDATE gc_attempt SET technical_state=?,ended_at=UTC_TIMESTAMP() WHERE id=? AND ended_at IS NULL',
                array($technicalState, $attempt['id'])
            );
            $phoneState = $technicalState === 'ANSWERED' ? 'ANSWERED' :
                (($technicalState === 'BUSY' || $technicalState === 'NO_ANSWER') ? 'NO_ANSWER' : 'ATTEMPTED');
            $tx->execute(
                'UPDATE gc_client_phone SET state=? WHERE id=? AND state NOT IN (\'INVALID\',\'DO_NOT_CALL\')',
                array($phoneState, $attempt['phone_id'])
            );
            return array('attempt_id'=>(int)$attempt['id'], 'technical_state'=>$technicalState);
        });
    }

    public function technicalState($dialStatus)
    {
        $status = strtoupper(trim((string)$dialStatus));
        if ($status === 'ANSWER') return 'ANSWERED';
        if ($status === 'BUSY') return 'BUSY';
        if ($status === 'NOANSWER') return 'NO_ANSWER';
        if ($status === 'CANCEL') return 'CANCELED';
        if (in_array($status, array('CHANUNAVAIL','CONGESTION','DONTCALL','TORTURE','INVALIDARGS'), true)) return 'FAILED';
        throw new InvalidArgumentException('Invalid Dial status');
    }
}
