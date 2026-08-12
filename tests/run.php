#!/usr/bin/env php
<?php

require dirname(__FILE__) . '/bootstrap.php';

if (session_id() === '') {
    session_id('gc-seat-regression');
}

$tests = array();
$failures = 0;

function test_case($name, $callback)
{
    global $tests;
    $tests[$name] = $callback;
}

function assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true));
    }
}

function assert_true($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_invalid_result($actual, $message)
{
    $invalid = ($actual === false || $actual === null || $actual === '');
    if (is_array($actual)) {
        $invalid = isset($actual['valid']) ? !$actual['valid'] : count($actual) > 0;
    }
    assert_true($invalid, $message . '; received ' . var_export($actual, true));
}

class FakeSeatTakeoverDb
{
    public $sessions = array();
    public $activeAttempts = array();
    private $lastId = 100;

    public function transaction($callback)
    {
        return call_user_func($callback, $this);
    }

    public function fetchOne($sql, $params)
    {
        if (strpos($sql, 'FROM gc_sip_seat') !== false) {
            return $params[0] === '501' ? array('sip_extension'=>'501', 'label'=>'Puesto 501') : null;
        }
        if (strpos($sql, 'FROM gc_agent_map') !== false) {
            return array('id'=>(int)$params[0]);
        }
        if (strpos($sql, 'FROM gc_attempt') !== false) {
            $workSessionId = (int)$params[0];
            return !empty($this->activeAttempts[$workSessionId]) ? array('id'=>900) : null;
        }
        if (strpos($sql, 'FROM gc_work_session') !== false && strpos($sql, 'session_hash=?') !== false) {
            foreach ($this->sessions as $row) {
                if ((int)$row['agent_map_id'] === (int)$params[0] && $row['session_hash'] === $params[1] && empty($row['released_at'])) return $row;
            }
            return null;
        }
        if (strpos($sql, 'FROM gc_work_session') !== false && strpos($sql, 'active_extension=?') !== false) {
            foreach ($this->sessions as $row) {
                if ($row['active_extension'] === $params[0] && empty($row['released_at'])) return $row;
            }
            return null;
        }
        throw new RuntimeException('Unexpected fake query: ' . $sql);
    }

    public function execute($sql, $params)
    {
        if (strpos($sql, 'INSERT INTO gc_work_session') !== false) {
            $this->lastId++;
            $this->sessions[$this->lastId] = array('id'=>$this->lastId, 'agent_map_id'=>(int)$params[0], 'session_hash'=>$params[1], 'sip_extension'=>$params[2], 'active_extension'=>$params[3], 'released_at'=>null);
            return true;
        }
        if (strpos($sql, 'UPDATE gc_work_session') !== false && strpos($sql, 'WHERE id=?') !== false && strpos($sql, 'active_extension=NULL') !== false) {
            $id = (int)$params[count($params) - 1];
            if (isset($this->sessions[$id])) {
                $this->sessions[$id]['active_extension'] = null;
                $this->sessions[$id]['released_at'] = 'now';
            }
            return true;
        }
        if (strpos($sql, 'UPDATE gc_work_session') !== false) return true;
        throw new RuntimeException('Unexpected fake execute: ' . $sql);
    }

    public function pdo() { return $this; }
    public function lastInsertId() { return $this->lastId; }
}

class FakeAssignmentDb
{
    public $hasClaim = false;
    public $hasUnresolvedAttempt = false;
    public $executed = array();
    public $audits = array();

    public function transaction($callback) { return call_user_func($callback, $this); }
    public function fetchOne($sql, $params)
    {
        if (strpos($sql, 'SELECT a.*, c.state') !== false) return array('id'=>12,'campaign_id'=>3,'client_id'=>44,'agent_map_id'=>7,'assignment_state'=>'ACTIVE','state'=>'CALLBACK','terminal'=>0,'campaign_status'=>'ACTIVE');
        if (strpos($sql, 'FROM gc_agent_map') !== false) return array('id'=>(int)$params[0]);
        if (strpos($sql, 'FROM gc_client_claim') !== false) return $this->hasClaim ? array('client_id'=>44) : null;
        if (strpos($sql, 'FROM gc_attempt') !== false) return $this->hasUnresolvedAttempt ? array('id'=>90) : null;
        throw new RuntimeException('Unexpected assignment fake query: ' . $sql);
    }
    public function execute($sql, $params) { $this->executed[] = array('sql'=>$sql,'params'=>$params); return true; }
    public function pdo() { return $this; }
    public function lastInsertId() { return 99; }
    public function audit($clientId, $actor, $type, $previous, $new, $metadata, $ip) { $this->audits[] = array('client_id'=>$clientId,'type'=>$type,'metadata'=>$metadata); }
}

class FakeProtectedClaimDb
{
    public $insertedClaim = false;
    public $existingClaimSql = '';

    public function transaction($callback) { return call_user_func($callback, $this); }
    public function fetchAll($sql, $params)
    {
        if (strpos($sql, 'FROM gc_client_claim cl JOIN gc_client c') !== false) return array();
        if (strpos($sql, 'FROM gc_client_phone') !== false) return array();
        if (strpos($sql, 'FROM (SELECT phone_id') !== false) return array();
        if (strpos($sql, 'FROM gc_attempt at LEFT JOIN') !== false) return array();
        throw new RuntimeException('Unexpected protected-claim fake query: ' . $sql);
    }
    public function fetchOne($sql, $params)
    {
        if (strpos($sql, 'FROM gc_agent_map') !== false) return array('id'=>(int)$params[0]);
        if (strpos($sql, 'FROM gc_client_claim cl JOIN gc_client c') !== false) {
            $this->existingClaimSql = $sql;
            if (strpos($sql, 'cl.expires_at>UTC_TIMESTAMP()') !== false) return null;
            return array('id'=>44,'campaign_id'=>3,'external_key'=>'protected','display_name'=>'Protected client','state'=>'IN_PROGRESS','terminal'=>0,'custom_data_json'=>'{}','assignment_id'=>12,'claim_token'=>'old-token','expires_at'=>'2020-01-01 00:00:00');
        }
        if (strpos($sql, 'FROM gc_assignment a JOIN gc_client c') !== false) return array('id'=>55,'campaign_id'=>3,'external_key'=>'next','display_name'=>'Next client','state'=>'PENDING','terminal'=>0,'custom_data_json'=>'{}','assignment_id'=>13);
        throw new RuntimeException('Unexpected protected-claim fake query: ' . $sql);
    }
    public function execute($sql, $params)
    {
        if (strpos($sql, 'INSERT INTO gc_client_claim') !== false) $this->insertedClaim = true;
        return true;
    }
    public function uuid() { return '123e4567-e89b-42d3-a456-426614174000'; }
    public function audit($clientId, $actor, $type, $previous, $new, $metadata, $ip) {}
}

function fake_ami_originate($frames, $holdOpenSeconds)
{
    gc_require_class('GestionClientesDialer', 'module/gestion_clientes/libs/GestionClientesDialer.class.php');
    require_once GC_PROJECT_ROOT . '/tests/fakes/FakeAmiServer.php';
    $server = new FakeAmiServer($frames, $holdOpenSeconds);
    $dialer = new GestionClientesDialer(array(
        'host'=>'127.0.0.1', 'port'=>$server->port(), 'username'=>'gc-test',
        'secret'=>'gc-test-secret', 'connect_timeout'=>1, 'read_timeout'=>1
    ));
    try {
        $result = $dialer->originate('506', '0991234567', '123e4567-e89b-42d3-a456-426614174000', 'GC-123e4567e89b42d3a');
        $server->finish();
        return $result;
    } catch (Exception $exception) {
        $server->finish();
        throw $exception;
    }
}

test_case('phone normalization accepts formatted international numbers', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    assert_same('+593991234567', GestionClientesValidator::normalizePhone(' +593 (99) 123-4567 '), 'Formatting must be removed without losing the leading plus');
});

test_case('phone normalization accepts local digits', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    assert_same('+593991234567', GestionClientesValidator::normalizePhone('099 123 4567'), 'Ecuador local numbers must be converted to E.164');
});

test_case('Ecuador E.164 numbers use national format for Issabel outbound routes', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    assert_same('0959599146', GestionClientesValidator::toDialString('+593959599146'), 'Ecuador country code must be converted back to the national zero prefix');
    assert_same('0959599146', GestionClientesValidator::toDialString('0959599146'), 'Already-national numbers must retain their route-compatible format');
});

test_case('phone normalization rejects unsafe values', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    assert_invalid_result(GestionClientesValidator::normalizePhone('555-CALL-NOW'), 'Alphabetic dial strings must be rejected');
    assert_invalid_result(GestionClientesValidator::normalizePhone('12'), 'Implausibly short phone numbers must be rejected');
});

test_case('callback outcome requires callback data', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    $outcome = array('code' => 'CALLBACK', 'active' => 1, 'resulting_client_state' => 'CALLBACK', 'requires_callback' => 1);
    assert_invalid_result(GestionClientesValidator::validateOutcome($outcome, null), 'A callback-required outcome without callback data must be invalid');
});

test_case('complete callback outcome is valid', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    $outcome = array('code' => 'CALLBACK', 'active' => 1, 'resulting_client_state' => 'CALLBACK', 'requires_callback' => 1);
    $callback = array('due_at' => '2030-08-01 14:30:00', 'timezone' => 'America/Guayaquil', 'note' => 'Volver a llamar');
    $actual = GestionClientesValidator::validateOutcome($outcome, $callback);
    $valid = ($actual === true || $actual === null || $actual === array());
    if (is_array($actual) && isset($actual['valid'])) {
        $valid = (bool) $actual['valid'];
    }
    assert_true($valid, 'Complete callback data should validate; received ' . var_export($actual, true));
});

test_case('callback dates are strict and future-only', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    assert_same('2030-08-01 19:30:00', GestionClientesValidator::localToUtc('2030-08-01 14:30', 'America/Guayaquil'), 'Callback local time must convert to UTC');
    try {
        GestionClientesValidator::localToUtc('2030-02-30 14:30', 'America/Guayaquil');
        throw new RuntimeException('Impossible callback date was accepted');
    } catch (InvalidArgumentException $expected) {}
    $outcome = array('active'=>1,'resulting_client_state'=>'CALLBACK','requires_callback'=>1);
    $errors = GestionClientesValidator::validateOutcome($outcome, array('due_at'=>'2020-01-01 10:00','timezone'=>'America/Guayaquil','note'=>'past'));
    assert_true(in_array('CALLBACK_MUST_BE_FUTURE', $errors, true), 'Past callbacks must not be scheduled');
});

test_case('CSV import detects delimiter and maps rows', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    gc_require_class('GestionClientesImport', 'module/gestion_clientes/libs/GestionClientesImport.class.php');
    $import = new GestionClientesImport(null);
    $path = GC_PROJECT_ROOT . '/tests/fixtures/clients-semicolon.csv';
    $mapping = array(
        'external_id' => 'id',
        'display_name' => 'nombre',
        'phones' => array('telefono', 'telefono_alterno'),
        'fields' => array('nombre')
    );
    $preview = $import->preview($path, $mapping, 0);
    assert_same(';', $preview['delimiter'], 'Semicolon delimiter was not detected');
    assert_same(4, $preview['total'], 'Every data row must be counted');
    assert_same(2, $preview['accepted'], 'Two rows should contain unique keys and dialable phones');
    assert_same(2, $preview['rejected'], 'Duplicate-key and no-phone rows should be rejected');
    assert_same(1, $preview['duplicates'], 'Duplicate external keys must be counted');
    assert_same('+593991234567', $preview['sample'][0]['phones'][0]['normalized'], 'Imported phone should use the shared normalizer');
});

test_case('CSV import preserves multiple phone order and deduplicates normalized values', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    gc_require_class('GestionClientesImport', 'module/gestion_clientes/libs/GestionClientesImport.class.php');
    $import = new GestionClientesImport(null);
    $mapping = array(
        'external_id' => 'id',
        'display_name' => 'nombre',
        'phones' => array('telefono_principal', 'telefono_duplicado', 'telefono_alterno')
    );
    $preview = $import->preview(GC_PROJECT_ROOT . '/tests/fixtures/clients-multiple-phones.csv', $mapping, 0);
    assert_same(1, $preview['accepted'], 'The client with multiple valid phones must be accepted');
    assert_same(2, count($preview['sample'][0]['phones']), 'Equivalent formatted numbers must appear only once');
    assert_same('+593991234567', $preview['sample'][0]['phones'][0]['normalized'], 'Primary phone must remain first');
    assert_same('+593987654321', $preview['sample'][0]['phones'][1]['normalized'], 'Distinct alternate phone must remain second');
    assert_same('telefono_principal', $preview['sample'][0]['phones'][0]['type'], 'Deduplication must retain the first field label');
});

test_case('CSV import expands a semicolon phone list into grouped client phones', function () {
    gc_require_class('GestionClientesValidator', 'module/gestion_clientes/libs/GestionClientesValidator.class.php');
    gc_require_class('GestionClientesImport', 'module/gestion_clientes/libs/GestionClientesImport.class.php');
    $import = new GestionClientesImport(null);
    $mapping = array('external_id' => 'cliente', 'display_name' => 'cliente', 'phones' => array('numeros'));
    $preview = $import->preview(GC_PROJECT_ROOT . '/tests/fixtures/clients-phone-list.csv', $mapping, 0);
    assert_same(2, $preview['accepted'], 'One CSV row must remain one grouped client');
    assert_same(2, count($preview['sample'][0]['phones']), 'Equivalent numbers inside the same cell must be deduplicated');
    assert_same('+593991234567', $preview['sample'][0]['phones'][0]['normalized'], 'The first cell number must remain first');
    assert_same('+593987654321', $preview['sample'][0]['phones'][1]['normalized'], 'The second distinct cell number must remain attached to the client');
    assert_same('numeros 1', $preview['sample'][0]['phones'][0]['type'], 'Expanded phone labels must identify their cell position');
    assert_same('numeros 3', $preview['sample'][0]['phones'][1]['type'], 'Deduplication must preserve the original cell position in the label');
});

test_case('schema contains concurrency and idempotency safeguards', function () {
    $sql = file_get_contents(GC_PROJECT_ROOT . '/install/schema.sql');
    $installer = file_get_contents(GC_PROJECT_ROOT . '/install/install.sh');
    assert_true(strpos($sql, 'UNIQUE KEY uq_gc_attempt_idempotency') !== false, 'Attempt idempotency constraint is missing');
    assert_true(strpos($sql, 'UNIQUE KEY uq_gc_claim_token') !== false, 'Claim token constraint is missing');
    assert_true(strpos($sql, 'UNIQUE KEY uq_gc_claim_agent (agent_map_id)') !== false, 'Database must enforce one current client per durable agent');
    assert_true(strpos($sql, 'ENGINE=InnoDB') !== false, 'Transactional InnoDB tables are required');
    assert_true(strpos($sql, 'gc_idempotency') !== false, 'General mutation idempotency table is missing');
    assert_true(strpos($installer, 'GC_DUPLICATE_CLAIMS') !== false && strpos($installer, 'No claim was deleted') !== false, 'Installer must fail clearly before migrating legacy duplicate agent claims');
});

test_case('seed outcomes include callback policy', function () {
    $sql = file_get_contents(GC_PROJECT_ROOT . '/install/seed_outcomes.sql');
    assert_true(strpos($sql, 'requires_callback') !== false, 'Outcome seeds must configure callback requirements');
    assert_true((bool) preg_match('/CALLBACK/i', $sql), 'A callback outcome seed is required');
});

test_case('mutation guard enforces POST, CSRF, and constant-time comparison', function () {
    $source = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesAuth.class.php');
    assert_true(strpos($source, "REQUEST_METHOD") !== false && strpos($source, "POST") !== false, 'Mutation authorization must require POST');
    assert_true(strpos($source, 'CSRF_INVALID') !== false, 'Mutation authorization must reject invalid CSRF tokens');
    assert_true(strpos($source, 'constantTimeEquals') !== false, 'CSRF token comparison must be timing resistant');
});

test_case('call origination has durable duplicate and correlation safeguards', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $dialer = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesDialer.class.php');
    assert_true(strpos($workflow, '_idempotent_replay') !== false, 'Existing attempts must never be originated again');
    assert_true(strpos($workflow, "FROM gc_assignment WHERE id=?") !== false && strpos($workflow, 'FOR UPDATE') !== false, 'Assignment must be locked before active-attempt creation');
    assert_true(strpos($workflow, "substr(str_replace('-', '', strtolower(\$token)), 0, 17)") !== false, 'CDR accountcode must fit the production 20-character column');
    assert_true(strpos($dialer, "'Account' => \$accountCode") !== false, 'AMI Originate must tag the agent leg with the compact accountcode');
    assert_true(strpos($workflow, 'ATTEMPT_NOT_FINISHED') !== false, 'Business outcomes must be gated until the technical attempt ends');
});

test_case('agent-first AMI accepts only the correlated answered OriginateResponse', function () {
    if (!function_exists('pcntl_fork')) return;
    $result = fake_ami_originate(array(
        "Event: Newchannel\r\nChannel: SIP/unrelated-0001",
        "Event: OriginateResponse\r\nActionID: gc-wrong-action\r\nResponse: Failure\r\nReason: 5",
        "Event: VarSet\r\nVariable: unrelated\r\nValue: 1",
        "Event: OriginateResponse\r\nActionID: {{ACTION_ID}}\r\nResponse: Success\r\nReason: 4"
    ), 0);
    assert_same('ANSWERED', $result['agent_state'], 'Interleaved events and a wrong ActionID must be ignored until the correlated answered response');
});

test_case('agent-first AMI maps authoritative no-answer busy and failure reasons', function () {
    if (!function_exists('pcntl_fork')) return;
    $cases = array(
        array('reason'=>'0', 'expected'=>'NO_ANSWER'),
        array('reason'=>'5', 'expected'=>'BUSY'),
        array('reason'=>'8', 'expected'=>'FAILED')
    );
    foreach ($cases as $case) {
        $result = fake_ami_originate(array(
            "Event: OriginateResponse\r\nActionID: {{ACTION_ID}}\r\nResponse: Failure\r\nReason: " . $case['reason']
        ), 0);
        assert_same($case['expected'], $result['agent_state'], 'Unexpected state for AMI OriginateResponse Reason ' . $case['reason']);
    }
});

test_case('agent-first AMI timeout or wrong ActionID remains non-authoritative', function () {
    if (!function_exists('pcntl_fork')) return;
    try {
        fake_ami_originate(array(
            "Event: OriginateResponse\r\nActionID: gc-someone-else\r\nResponse: Success\r\nReason: 4"
        ), 6);
        throw new RuntimeException('Wrong ActionID unexpectedly became authoritative');
    } catch (GestionClientesAmiUnknownException $exception) {
        assert_true(true, 'Expected unknown AMI result');
    }
});

test_case('workflow persists authoritative agent result and keeps unknown result non-retryable', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true(strpos($workflow, "['agent_state']") !== false || strpos($workflow, "['agent_state'") !== false, 'Workflow must consume the authoritative agent_state returned by the dialer');
    assert_true((bool) preg_match('/\$amiState\s*=\s*\$agentState\s*===\s*[\'\"]ANSWERED[\'\"]\s*\?\s*[\'\"]ORIGINATED[\'\"]\s*:\s*\$agentState/', $workflow), 'Workflow must preserve authoritative no-answer, busy, and failed agent-leg states');
    assert_true((bool) preg_match('/technical_state=\?[^\'\"]*ended_at=UTC_TIMESTAMP\(\)/', $workflow), 'Authoritative agent-leg failures must close the attempt');
    assert_true(strpos($workflow, "technical_state=\'ORIGINATED\'") !== false, 'Answered agent leg must continue as ORIGINATED while the customer leg runs');
    assert_true(strpos($workflow, "technical_state=\'AMBIGUOUS\', ended_at=NULL") !== false, 'Timeout/non-authoritative response must remain AMBIGUOUS and unresolved');
    assert_true(strpos($workflow, "technical_state IN (\'CREATED\',\'ORIGINATED\',\'RINGING\',\'ANSWERED\',\'AMBIGUOUS\')") !== false, 'AMBIGUOUS attempt must remain in the duplicate-call guard');
});

test_case('agent-only failures do not count as customer calls', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $stats = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesStats.class.php');
    assert_true((bool)preg_match('/LEFT\(raw_error_code,10\)[^\r\n]{0,40}AMI_AGENT_/', $workflow), 'Agent-only failures must not become the phone last-call record');
    $statFilters = preg_match_all('/LEFT\((?:at\.)?raw_error_code,10\)[^\r\n]{0,40}AMI_AGENT_/', $stats, $matches);
    assert_true($statFilters >= 2, 'Customer call and outcome statistics must exclude calls that never left the agent leg');
});

test_case('agent-only failure UI permits a safe retry without requesting an outcome', function () {
    $javascript = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/js/gestion_clientes.js');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    assert_true((bool)preg_match('/agent_only_failure[\s\S]{0,700}input\[name=idempotency_key\][\s\S]{0,80}idempotencyKey\(\)/', $javascript), 'An authoritative agent-leg failure must renew the call idempotency key before retry');
    assert_true(strpos($javascript, "enabledButtons.prop('disabled', false)") !== false, 'Only call buttons that were eligible before submission may be re-enabled');
    assert_true(strpos($javascript, '!window.FormData') === false, 'Serialized AJAX calls must not depend on the unrelated FormData API');
    assert_true(strpos($javascript, "[data-gc-outcome-form]').hide()") !== false, 'The outcome form must be hidden when the customer was never called');
    assert_true(strpos($template, 'La llamada anterior no llegó al cliente') !== false, 'A reload must retain a clear agent-leg failure explanation');
});

test_case('agent cannot start another call before resolving the previous attempt', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    assert_true(strpos($workflow, "technical_state IN (\\'CREATED\\',\\'ORIGINATED\\',\\'RINGING\\',\\'ANSWERED\\',\\'AMBIGUOUS\\')") !== false, 'Active and uncertain attempts must block another call inside the transaction');
    assert_true(strpos($workflow, "previousFinished['business_outcome_id'] === null") !== false && strpos($workflow, 'OUTCOME_REQUIRED_BEFORE_CALL') !== false, 'The transactional workflow must require a disposition for the immediately preceding ended attempt');
    assert_true(strpos($index, 'OUTCOME_REQUIRED_BEFORE_CALL') !== false, 'The API must expose a stable pending-outcome error');
});

test_case('campaign status is an enforceable dialing stop', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $assignment = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesAssignment.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    assert_true(substr_count($workflow, "camp.status=\'ACTIVE\'") >= 2, 'Only active campaigns may be claimed or called');
    assert_true(strpos($workflow, 'CAMPAIGN_NOT_ACTIVE') !== false, 'A paused or closed campaign must return an explicit call error');
    assert_true(strpos($assignment, 'CAMPAIGN_NOT_ASSIGNABLE') !== false, 'Closed campaigns must reject new assignment commits');
    assert_true(strpos($index, "'CAMPAIGN_NOT_ACTIVE'") !== false, 'Agents need a clear paused-campaign message');
});

test_case('uncertain AMI responses remain unresolved instead of triggering a duplicate retry', function () {
    $dialer = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesDialer.class.php');
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true(strpos($dialer, 'GestionClientesAmiUnknownException') !== false, 'AMI must distinguish an unknown post-write result from an explicit rejection');
    assert_true(strpos($workflow, "technical_state=\\'AMBIGUOUS\\', ended_at=NULL") !== false, 'An unknown AMI result must remain unresolved');
    assert_true(strpos($workflow, 'AMI_RESULT_UNKNOWN') !== false, 'The workflow must return a stable unknown-result error');
    $catchEnd = strpos($workflow, "throw new RuntimeException('AMI_ORIGINATE_FAILED');");
    $originatedUpdate = strpos($workflow, "technical_state=\\'ORIGINATED\\'");
    assert_true($catchEnd !== false && $originatedUpdate !== false && $originatedUpdate > $catchEnd, 'A database failure after accepted Originate must not be misclassified as an AMI rejection');
});

test_case('queue only auto-claims untouched clients or due callbacks', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true(strpos($workflow, "c.state=\\'PENDING\\' AND NOT EXISTS") !== false, 'Called informational outcomes must not immediately re-enter the automatic queue');
    assert_true(strpos($workflow, 'called.assignment_id=a.id') !== false, 'A new reassignment must be eligible once without erasing earlier call history');
    assert_true(strpos($workflow, "c.state=\\'CALLBACK\\'") !== false && strpos($workflow, "due_cb.due_at_utc<=UTC_TIMESTAMP()") !== false, 'Only due callbacks should automatically return after a call');
    assert_true(strpos($workflow, "agent_note IS NULL OR agent_note=\\'\\'") !== false, 'An idempotent outcome retry must be able to restore a note omitted by the first browser request');
});

test_case('expired untouched claims return safely to the queue', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $cleanup = file_get_contents(GC_PROJECT_ROOT . '/bin/cleanup_claims.php');
    assert_true(strpos($workflow, 'function releaseExpiredClaims') !== false && strpos($workflow, 'CLAIM_EXPIRED') !== false, 'Expired claims must have a single audited release path');
    assert_true(strpos($workflow, "NOT EXISTS (SELECT 1 FROM gc_attempt") !== false && strpos($workflow, 'business_outcome_id IS NULL') !== false, 'Claims with an active call or pending outcome must not be released');
    assert_true(strpos($workflow, "\$newState = \$row['callback_due_at'] !== null ? 'CALLBACK' : 'PENDING'") !== false, 'Released clients must restore callback or pending queue state');
    assert_true(substr_count($workflow, 'releaseExpiredClaims($tx, $agentMapId)') >= 3, 'Current, next, and reopen workflows must all clean safe expired claims for the current agent');
    assert_true(strpos($cleanup, 'CLAIM_EXPIRED') !== false && strpos($cleanup, 'NOT EXISTS (SELECT 1 FROM gc_attempt') !== false, 'Background cleanup must preserve the same active-call and pending-outcome safeguards');
});

test_case('protected expired claim remains current and blocks claiming another client', function () {
    gc_require_class('GestionClientesWorkflow', 'module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $db = new FakeProtectedClaimDb();
    $workflow = new GestionClientesWorkflow($db, null, 900);
    $client = $workflow->claimNext(7, 'agent', '127.0.0.1');
    assert_same(44, (int)$client['id'], 'Agent must remain on the client whose expired claim is protected by unresolved work');
    assert_true(strpos($db->existingClaimSql, 'cl.expires_at>UTC_TIMESTAMP()') === false, 'Protected claim lookup must not ignore an expired row retained for safety');
    assert_true(!$db->insertedClaim, 'A protected claim must prevent insertion of a second client claim');
    $workflowSource = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true(substr_count($workflowSource, 'SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE') >= 3, 'Current, next, and reopen operations must serialize claim ownership on the durable agent row');
});

test_case('non-callback outcomes are informational client metadata only', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $seed = file_get_contents(GC_PROJECT_ROOT . '/install/seed_outcomes.sql');
    $migration = file_get_contents(GC_PROJECT_ROOT . '/install/migrations/005_informational_outcomes.sql');
    assert_true(strpos($workflow, "UPDATE gc_client SET state=?, terminal=0") !== false, 'Saving a label must never terminally close the client');
    assert_true(strpos($workflow, "UPDATE gc_client_phone SET state=\\'INVALID\\'") === false, 'An informational label must not invalidate a phone');
    assert_true(strpos($workflow, "assignment_state=\\'COMPLETED\\'") === false, 'An informational label must not complete ownership assignment');
    assert_true(strpos($workflow, "if ((int)\$outcome['requires_callback'] === 1)") !== false, 'Callback scheduling must remain the explicit operational exception');
    assert_true(strpos($seed, "'NOT_INTERESTED','No interesado',40,'PENDING',0") !== false, 'Fresh outcome catalog must keep No interesado informational');
    assert_true(strpos($migration, 'terminal=0') !== false && strpos($migration, 'VALUES (5, UTC_TIMESTAMP())') !== false, 'Existing outcome catalogs must migrate to informational semantics');
});

test_case('agents have an owned called-client history with guarded reopen', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/called_clients.tpl');
    assert_true(strpos($workflow, 'function calledClients') !== false && strpos($workflow, 'WHERE agent_map_id=?') !== false, 'History visibility must use permanent attempt ownership');
    assert_true(strpos($workflow, 'function reopenClient') !== false && strpos($workflow, 'CLIENT_ASSIGNED_TO_OTHER_AGENT') !== false, 'Reopen must not steal a reassigned client');
    assert_true(strpos($workflow, 'CLIENT_HISTORY_REQUIRED') !== false && strpos($workflow, 'OUTCOME_REQUIRED_BEFORE_CALL') !== false, 'Reopen must require call history and no unresolved disposition');
    assert_true(strpos($index, "\$action === 'called_clients'") !== false && strpos($index, "\$action === 'reopen_client'") !== false, 'Agent history routes must be available before supervisor-only routing');
    assert_true((bool)preg_match('/<form[^>]*method="post"[^>]*action="\{\$reopen_url/', $template), 'Reopen must be a guarded POST form');
    assert_true(strpos($template, 'name="csrf_token"') !== false && strpos($template, 'name="idempotency_key"') !== false, 'Reopen must carry CSRF and idempotency controls');
});

test_case('callback lifecycle supports guarded reschedule and cancellation', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/callbacks.tpl');
    assert_true(strpos($workflow, 'function manageCallback') !== false && strpos($workflow, 'CALLBACK_OWNERSHIP_INVALID') !== false, 'Only the owning agent or supervisor may manage a callback');
    assert_true(strpos($workflow, "CALLBACK_RESCHEDULED") !== false && strpos($workflow, "CALLBACK_CANCELED") !== false, 'Callback lifecycle changes must be audited');
    assert_true(strpos($workflow, 'CALLBACK_CLIENT_ACTIVE') !== false, 'Active clients or calls must block callback changes');
    assert_true(strpos($index, "\$action === 'callback_manage'") !== false && strpos($index, 'validateMutation') !== false, 'Callback mutations require a guarded endpoint');
    assert_true(strpos($template, 'name="callback_action" value="RESCHEDULE"') !== false && strpos($template, 'name="callback_action" value="CANCEL"') !== false, 'Callback UI must expose both lifecycle actions');
});

test_case('supervisor reassignment preserves history and callback ownership safely', function () {
    $assignment = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesAssignment.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/assignment_manage.tpl');
    assert_true(strpos($assignment, 'REASSIGNMENT_CLIENT_ACTIVE') !== false && strpos($assignment, 'gc_client_claim') !== false && strpos($assignment, 'business_outcome_id IS NULL') !== false, 'Open clients, active calls, and pending outcomes must block reassignment');
    assert_true(strpos($assignment, 'SELECT id FROM gc_agent_map WHERE id=? AND active=1 FOR UPDATE') !== false, 'The destination must be an enabled agent');
    assert_true(strpos($assignment, 'UPDATE gc_callback SET assignment_id=?') !== false, 'Open callbacks must follow the new assignment');
    assert_true(strpos($assignment, "'CLIENT_REASSIGNED'") !== false, 'Every transfer must be audited');
    assert_true(strpos($index, "\$action === 'assignment_manage'") !== false && strpos($index, "'assignment_reassign'") !== false, 'The supervisor route must use the standard idempotent mutation path');
    assert_true(strpos($template, 'name="csrf_token"') !== false && strpos($template, 'name="idempotency_key"') !== false && strpos($template, 'name="reason"') !== false, 'Reassignment UI must carry CSRF, idempotency, and a reason');
});

test_case('reassignment transaction moves an open callback and rejects in-use clients', function () {
    gc_require_class('GestionClientesAssignment', 'module/gestion_clientes/libs/GestionClientesAssignment.class.php');
    $db = new FakeAssignmentDb();
    $newAssignmentId = (new GestionClientesAssignment($db))->reassign(12, 8, 'supervisor', 'Cambio de turno', '127.0.0.1');
    assert_same(99, $newAssignmentId, 'Reassignment must return the durable new assignment ID');
    $movedCallback = false;
    foreach ($db->executed as $statement) {
        if (strpos($statement['sql'], 'UPDATE gc_callback SET assignment_id=?') !== false && $statement['params'] === array(99,44,12)) $movedCallback = true;
    }
    assert_true($movedCallback, 'Open callback must reference the new assignment in the same transaction');
    assert_same('CLIENT_REASSIGNED', $db->audits[0]['type'], 'Successful reassignment must emit an audit event');

    $blocked = new FakeAssignmentDb();
    $blocked->hasUnresolvedAttempt = true;
    try {
        (new GestionClientesAssignment($blocked))->reassign(12, 8, 'supervisor', 'Cambio de turno', '127.0.0.1');
        throw new RuntimeException('Unresolved attempt was reassigned');
    } catch (RuntimeException $expected) {
        assert_same('REASSIGNMENT_CLIENT_ACTIVE', $expected->getMessage(), 'Unresolved call must block reassignment');
    }
});

test_case('production reports include agent technical and detailed CSV data', function () {
    $stats = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesStats.class.php');
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    assert_true(strpos($stats, 'function agentPerformance') !== false && strpos($stats, 'clients_called') !== false && strpos($stats, 'talk_seconds') !== false, 'Agent report must expose operational call metrics');
    assert_true(strpos($stats, 'function technicalBreakdown') !== false, 'Technical results must be reportable separately from business labels');
    assert_true(strpos($stats, 'function attemptExport') !== false && strpos($stats, 'LIMIT ' . "' . \$limit") !== false, 'Detailed exports must be bounded');
    assert_true(strpos($index, 'gc_csv_cell') !== false && strpos($index, "[=+\\-@]") !== false, 'CSV exports must neutralize spreadsheet formulas, including after leading whitespace');
    assert_true(strpos($index, 'attempt_id,requested_at,answered_at') !== false, 'CSV export must contain call-level detail');
});

test_case('Asterisk 11 dialplan derives its compact CDR key from the attempt UUID', function () {
    $dialplan = file_get_contents(GC_PROJECT_ROOT . '/asterisk/extensions_gestion_clientes.conf');
    assert_true(strpos($dialplan, 'FILTER(0-9a-fA-F,${GC_ATTEMPT_ID})') !== false, 'Dialplan must derive the compact CDR key from the validated attempt UUID');
    assert_true(strpos($dialplan, 'Set(CDR(accountcode)=${GC_ACCOUNT_CODE})') !== false, 'Dialplan must explicitly set accountcode on the answered agent leg');
    assert_true(strpos($dialplan, 'REGEX("^GC-[0-9a-fA-F]{17}$" ${CDR(accountcode)})') === false, 'Dialplan must not depend on AMI Account being visible through CDR(accountcode)');
});

test_case('CDR reconciliation supports local timestamps and missing linkedid', function () {
    $source = file_get_contents(GC_PROJECT_ROOT . '/bin/reconcile_cdr.php');
    assert_true(strpos($source, 'cdr_linkedid_column') !== false, 'Linked ID column must be configurable');
    assert_true(strpos($source, 'cdr_timezone') !== false && strpos($source, "new DateTimeZone") !== false, 'CDR local time must be converted through a configured timezone');
    assert_true(strpos($source, "isset(\$config['timezone'])") !== false, 'Reconciler must inherit the installation timezone when a dedicated CDR timezone is omitted');
});

test_case('CDR reconciliation backs off unmatched attempts without starving newer calls', function () {
    $source = file_get_contents(GC_PROJECT_ROOT . '/bin/reconcile_cdr.php');
    $migration = file_get_contents(GC_PROJECT_ROOT . '/install/migrations/003_cdr_retry_schedule.sql');
    assert_true(strpos($source, 'cdr_next_retry_at<=UTC_TIMESTAMP()') !== false, 'Only eligible retry rows should enter a bounded reconciliation batch');
    assert_true(strpos($source, 'gc_cdr_retry_delay') !== false, 'Unmatched attempts must be rescheduled with bounded backoff');
    assert_true(strpos($migration, 'cdr_next_retry_at') !== false && strpos($migration, 'idx_gc_attempt_unreconciled') !== false, 'The retry schedule requires an indexed migration');
    assert_true(strpos($migration, 'VALUES (3, UTC_TIMESTAMP())') !== false, 'Migration 003 must be recorded in the schema ledger');
});

test_case('CDR reconciliation is bounded and operationally observable', function () {
    $source = file_get_contents(GC_PROJECT_ROOT . '/bin/reconcile_cdr.php');
    $schema = file_get_contents(GC_PROJECT_ROOT . '/install/schema.sql');
    $migration = file_get_contents(GC_PROJECT_ROOT . '/install/migrations/006_production_operations.sql');
    assert_true(strpos($source, "'max-retries:'") !== false && strpos($source, 'cdr_exhausted_at') !== false, 'Reconciliation must stop retrying permanently unmatched calls');
    assert_true(strpos($source, "raw_error_code NOT LIKE \\'AMI_AGENT_%\\'") !== false, 'Agent-only failures must never enter customer CDR reconciliation');
    assert_true(strpos($source, 'gc_operational_status') !== false && strpos($source, 'heartbeatCompleted') !== false, 'Every reconciler run must leave a durable success or failure heartbeat');
    assert_true(strpos($schema, 'CREATE TABLE IF NOT EXISTS gc_operational_status') !== false, 'Fresh installations need the operational heartbeat table');
    assert_true(strpos($migration, 'DIALPLAN_CORRELATION_REJECTED') !== false && strpos($migration, 'VALUES (6, UTC_TIMESTAMP())') !== false, 'Migration must close known no-CDR legacy failures and record schema version 6');
    assert_true(strpos($migration, "c.state='IN_PROGRESS'") !== false && strpos($migration, 'cl.client_id IS NULL') !== false, 'Migration must repair safely identifiable clients stranded by old claim expiry behavior');
});

test_case('CDR retry delay is bounded and monotonic', function () {
    define('GC_RECONCILE_LIBRARY_ONLY', true);
    ob_start();
    require_once GC_PROJECT_ROOT . '/bin/reconcile_cdr.php';
    ob_end_clean();
    assert_same(30, gc_cdr_retry_delay(0), 'First missing CDR retry must wait 30 seconds');
    assert_same(60, gc_cdr_retry_delay(1), 'Second missing CDR retry must back off');
    assert_same(3600, gc_cdr_retry_delay(99), 'Retry delay must cap at one hour');
});

test_case('production operations provide health backup and alert tooling', function () {
    $health = file_get_contents(GC_PROJECT_ROOT . '/bin/health_check.php');
    $backup = file_get_contents(GC_PROJECT_ROOT . '/bin/backup.sh');
    $verify = file_get_contents(GC_PROJECT_ROOT . '/bin/verify_backup.sh');
    $install = file_get_contents(GC_PROJECT_ROOT . '/install/install-operations.sh');
    $cron = file_get_contents(GC_PROJECT_ROOT . '/install/gestion-clientes.cron');
    assert_true(strpos($health, 'stale_active_attempts') !== false && strpos($health, 'exhausted_reconciliation') !== false && strpos($health, 'ambiguous_attempts') !== false && strpos($health, 'overdue_outcomes') !== false && strpos($health, 'agents_with_multiple_claims') !== false, 'Health check must surface states that can make dialing unsafe');
    assert_true(strpos($backup, '--single-transaction') !== false && strpos($backup, 'umask 077') !== false && strpos($backup, 'MANIFEST.sha256') !== false, 'Backup must be consistent, private, and checksummed');
    assert_true(strpos($verify, 'sha256sum -c') !== false, 'Backup verification must validate the checksum manifest');
    assert_true(strpos($backup, '.backup') !== false && strpos($backup, '| sqlite3 "$sqlite_db"') !== false && strpos($backup, 'PRAGMA integrity_check') !== false && strpos($verify, 'PRAGMA integrity_check') !== false, 'Live Issabel SQLite files require legacy-compatible online backup and integrity verification');
    assert_true(strpos($backup, 'BACKUP.meta') !== false && strpos($verify, 'BACKUP.meta') !== false, 'Backup verification must use artifact metadata rather than hard-coded database options');
    assert_true(strpos($install, '--install-cron') !== false, 'Operational installer must not replace production cron without explicit authorization');
    assert_true(strpos($install, 'SELECT MAX(version_num) FROM gc_schema_version') !== false && strpos($install, 'version>=6') !== false, 'Operational binaries must not be published before schema migration 6');
    assert_true(strpos($install, '/var/lib/asterisk/agi-bin/gestion-clientes-finalize-call') !== false, 'Operational installer must publish the real-time finalizer used by the approved dialplan');
    assert_true(strpos($cron, 'gestion-clientes-health-alert') !== false && strpos($cron, 'gestion-clientes-reconcile.log') !== false, 'Cron must preserve reconciler output and emit health alerts');
    assert_true(strpos($cron, 'gestion-clientes-cleanup-claims') !== false && strpos($cron, '/usr/bin/flock -n') !== false, 'Cron must release safe expired claims without overlapping runs');
    $cleanup = file_get_contents(GC_PROJECT_ROOT . '/bin/cleanup_claims.php');
    assert_true(strpos($cleanup, '/var/www/html/modules/gestion_clientes') !== false && strpos($cleanup, "dirname(__FILE__) . '/../module") === false, 'Installed cleanup must load the deployed module rather than a repository-relative path');
});

test_case('CDR reconciliation has a safe production cron definition', function () {
    $cron = file_get_contents(GC_PROJECT_ROOT . '/install/gestion-clientes.cron');
    assert_true(strpos($cron, '* * * * * root') !== false, 'CDR reconciliation must run every minute');
    assert_true(strpos($cron, '/usr/bin/flock -n') !== false, 'Concurrent reconciliation runs must be prevented');
    assert_true(strpos($cron, '--min-age 120') !== false, 'Cron must allow linked CDR legs to settle');
    assert_true(strpos($cron, '--max-retries 10') !== false, 'Cron must enforce a bounded retry policy');
    assert_true(strpos($cron, '/usr/local/sbin/gestion-clientes-reconcile-cdr') !== false, 'Cron must use the stable production command');
});

test_case('dialplan finalizes the workflow immediately after Dial returns', function () {
    $dialplan = file_get_contents(GC_PROJECT_ROOT . '/asterisk/extensions_gestion_clientes.conf');
    assert_true(strpos($dialplan, 'exten => h,1') !== false, 'Real-time finalization must use the hangup extension when Dial cannot advance');
    assert_true(strpos($dialplan, 'AGI(gestion-clientes-finalize-call,${GC_ATTEMPT_ID},${GC_DIAL_STATUS})') !== false, 'The hangup extension must invoke the real-time finalizer');
    assert_true(strpos($dialplan, 'GC_DIAL_STATUS=CANCEL') !== false, 'Caller teardown before DIALSTATUS must have a safe terminal fallback');
});

test_case('real-time finalizer maps Asterisk Dial statuses safely', function () {
    gc_require_class('GestionClientesCallFinalizer', 'module/gestion_clientes/libs/GestionClientesCallFinalizer.class.php');
    $finalizer = new GestionClientesCallFinalizer(null);
    assert_same('ANSWERED', $finalizer->technicalState('ANSWER'), 'Answered calls must settle immediately');
    assert_same('NO_ANSWER', $finalizer->technicalState('NOANSWER'), 'Unanswered calls must settle immediately');
    assert_same('BUSY', $finalizer->technicalState('BUSY'), 'Busy calls must settle immediately');
    assert_same('CANCELED', $finalizer->technicalState('CANCEL'), 'Canceled calls must settle immediately');
    assert_same('CANCELED', $finalizer->technicalState(''), 'A caller hangup before Dial returns must still settle immediately');
    assert_same('FAILED', $finalizer->technicalState('CHANUNAVAIL'), 'Unavailable routes must settle as failed');
    try {
        $finalizer->technicalState('ANSWER;touch /tmp/bad');
        throw new RuntimeException('Unsafe Dial status was accepted');
    } catch (InvalidArgumentException $expected) {}
});

test_case('dynamic seat keeps permanent agent identity for ownership and statistics', function () {
    $schema = file_get_contents(GC_PROJECT_ROOT . '/install/schema.sql');
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    $stats = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesStats.class.php');
    assert_true((bool) preg_match('/CREATE TABLE IF NOT EXISTS gc_attempt[\s\S]*?agent_map_id BIGINT UNSIGNED NOT NULL[\s\S]*?FOREIGN KEY \(agent_map_id\) REFERENCES gc_agent_map\(id\)/', $schema), 'Attempts must retain permanent agent_map_id ownership');
    assert_true(strpos($workflow, 'agent_map_id=?') !== false, 'Workflow ownership checks must continue using agent_map_id');
    assert_true(strpos($stats, 'agent_map_id') !== false, 'Agent statistics must continue grouping attempts/assignments by permanent agent identity');
});

test_case('attempt snapshots the selected session seat before origination', function () {
    $schema = file_get_contents(GC_PROJECT_ROOT . '/install/schema.sql');
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true((bool) preg_match('/gc_attempt[\s\S]*?agent_sip_extension VARCHAR\([0-9]+\)/', $schema), 'gc_attempt must persist the seat extension used for that call');
    assert_true((bool) preg_match('/function\s+startCall\s*\([^)]*(seat|extension)/i', $workflow), 'startCall must receive a selected seat separately from the permanent agent mapping');
    assert_true((bool) preg_match('/INSERT INTO gc_attempt\s*\([^)]*agent_sip_extension/i', $workflow), 'The attempt INSERT must snapshot agent_sip_extension in its transaction');
    assert_true((bool) preg_match('/originate\s*\(\s*\$attempt\s*\[\s*[\'\"]agent_sip_extension[\'\"]\s*\]/', $workflow), 'AMI must originate from the extension snapshotted on the attempt, not mutable agent-map data');
});

test_case('seat selection is session-scoped and validated server-side', function () {
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    $session = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesSeatSession.class.php');
    $combined = $index . "\n" . $session;
    assert_true(strpos($session, 'session_id()') !== false && strpos($session, 'session_hash') !== false, 'Selected seat must be bound server-side to the Issabel PHP session');
    assert_true((bool) preg_match('/action[^\n]{0,100}(select|set|clear|change)[^\n]{0,60}(seat|extension)|(seat|extension)[^\n]{0,60}(select|set|clear|change)/i', $index), 'A dedicated seat selection endpoint/action is required');
    assert_true(strpos($index, 'validateMutation') !== false, 'Seat changes must use the standard POST/CSRF/idempotency mutation guard');
    assert_true((bool) preg_match('/preg_match\s*\([^\n]*(seat|extension)|function\s+[^\s(]*(seat|extension)[^\{]*\{[\s\S]{0,800}preg_match/i', $combined), 'Server-side seat validation must restrict extension syntax');
});

test_case('same agent may recover an inactive occupied seat but other agents and active calls may not', function () {
    gc_require_class('GestionClientesSeatSession', 'module/gestion_clientes/libs/GestionClientesSeatSession.class.php');
    $currentHash = hash('sha256', 'gestion_clientes|' . session_id());

    $recoverable = new FakeSeatTakeoverDb();
    $recoverable->sessions[10] = array('id'=>10, 'agent_map_id'=>7, 'session_hash'=>str_repeat('a', 64), 'sip_extension'=>'501', 'active_extension'=>'501', 'released_at'=>null);
    $service = new GestionClientesSeatSession($recoverable, 1800);
    $selected = $service->select(7, '501');
    assert_same('501', $selected['sip_extension'], 'Same agent must recover its stale seat in a new browser session');
    assert_true($recoverable->sessions[10]['active_extension'] === null, 'Recovered prior work session must release its active-extension lock');
    assert_true($recoverable->sessions[$selected['id']]['session_hash'] === $currentHash, 'Recovered seat must bind to the current PHP session');

    $otherAgent = new FakeSeatTakeoverDb();
    $otherAgent->sessions[20] = array('id'=>20, 'agent_map_id'=>8, 'session_hash'=>str_repeat('b', 64), 'sip_extension'=>'501', 'active_extension'=>'501', 'released_at'=>null);
    try {
        (new GestionClientesSeatSession($otherAgent, 1800))->select(7, '501');
        throw new RuntimeException('Different agent unexpectedly recovered an occupied seat');
    } catch (RuntimeException $exception) {
        assert_same('SEAT_IN_USE', $exception->getMessage(), 'A seat held by another agent must remain unavailable');
    }

    $activeCall = new FakeSeatTakeoverDb();
    $activeCall->sessions[30] = array('id'=>30, 'agent_map_id'=>7, 'session_hash'=>str_repeat('c', 64), 'sip_extension'=>'501', 'active_extension'=>'501', 'released_at'=>null);
    $activeCall->activeAttempts[30] = true;
    try {
        (new GestionClientesSeatSession($activeCall, 1800))->select(7, '501');
        throw new RuntimeException('Seat with an active call was unexpectedly recovered');
    } catch (RuntimeException $exception) {
        assert_same('SEAT_HAS_ACTIVE_CALL', $exception->getMessage(), 'Same-agent takeover must be blocked specifically while its previous session has an active call');
    }
});

test_case('call form cannot override the server-side selected seat', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $matched = preg_match('/<form[^>]*gc-call-form[^>]*>([\s\S]*?)<\/form>/', $template, $parts);
    assert_true($matched === 1, 'Agent workspace call form is missing');
    assert_true(!preg_match('/name=[\'\"][^\'\"]*(seat|extension)[^\'\"]*[\'\"]/i', $parts[1]), 'Call requests must not post a seat/extension that can override the server-side session selection');
});

test_case('workspace uses a compact header seat control', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $css = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/css/gestion_clientes.css');
    assert_true(strpos($template, 'gc-seat-panel') === false, 'The large session-extension panel must be hidden from the workspace');
    assert_true(substr_count($template, 'gc-extension-chip') === 1, 'Llamar desde must appear only once in the compact header');
    $headingEnd = strpos($template, '</div></div>');
    $heading = $headingEnd === false ? '' : substr($template, 0, $headingEnd);
    assert_true(strpos($heading, 'Mis clientes llamados') !== false && strpos($heading, 'gc-seat-compact') !== false, 'History and compact seat controls must share the workspace header');
    assert_true(strpos($template, 'name="sip_extension"') !== false && strpos($template, 'seat_release_url') !== false, 'Compact control must retain change and release operations');
    assert_true(strpos($css, '.gc-seat-popover') !== false, 'Compact seat selector requires a hidden popover');
    assert_true(strpos($css, '.gc-workspace-tools{align-items:center;display:flex;flex-wrap:nowrap') !== false, 'Desktop workspace actions and agent identity must remain on one row');
});

test_case('workspace phone view exposes per-number state and attempt history', function () {
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    assert_true((bool) preg_match('/function\s+gc_client_view[\s\S]*?[\'\"]state[\'\"]\s*=>\s*\$p\s*\[[\'\"]state[\'\"]/', $index), 'Each rendered phone must retain its current state');
    assert_true(strpos($index, "'attempt_count'=>(int)\$p['attempt_count']") !== false, 'Each rendered phone must expose its attempt count');
    assert_true(strpos($index, "'last_call_at'=>\$lastCall") !== false && strpos($index, "'last_attempt'=>\$p['last_attempt']") !== false, 'Each rendered phone must expose its localized last-attempt timestamp and summary');
});

test_case('workspace renders one prominent call action bound to each phone id', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $javascript = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/js/gestion_clientes.js');
    $matched = preg_match('/\{foreach\s+from=\$client\.phones[^}]*item=p[^}]*\}(.*?)\{\/foreach\}/s', $template, $parts);
    assert_true($matched === 1, 'Workspace must iterate over every client phone');
    $phoneBlock = $parts[1];
    assert_true(strpos($phoneBlock, '{$p.id') !== false, 'Every phone action must be bound to that iteration phone ID');
    assert_true((bool) preg_match('/<input\b(?=[^>]*name=[\'\"]phone_id[\'\"])(?=[^>]*value=[\'\"]\{\$p\.id)[^>]*>/i', $phoneBlock), 'Every phone card must serialize its own hidden phone_id; submit-button values are omitted by jQuery serialize()');
    assert_true((bool) preg_match('/<button\b/i', $phoneBlock), 'Every phone must have a directly visible call button');
    assert_true(!preg_match('/<select\b[^>]*name=[\'\"]phone_id[\'\"]/i', $template), 'Phone calls must not be hidden behind a single shared selector');
    assert_true(strpos($phoneBlock, '{$p.state') !== false, 'Phone card must display its state');
    assert_true(strpos($phoneBlock, '{$p.attempt_count') !== false, 'Phone card must display its attempt count');
    assert_true((bool) preg_match('/\{\$p\.(last_attempt_at|last_call_at|last_attempt)/', $phoneBlock), 'Phone card must display its last attempt');
    assert_true(strpos($javascript, 'form.serialize()') !== false, 'AJAX call submission must serialize only the clicked phone form');
});

test_case('workspace selects one phone with side-by-side disposition', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $javascript = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/js/gestion_clientes.js');
    $css = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/css/gestion_clientes.css');
    assert_true(strpos($template, 'class="gc-phone-tabs"') !== false && strpos($template, 'data-gc-phone-select="1"') !== false, 'All numbers must render in a horizontal selector');
    assert_true(strpos($template, 'gc-phone-detail-active') !== false, 'Exactly one phone detail must be selected initially');
    assert_true(strpos($template, 'gc-outcome-side') !== false && strpos($template, 'gc-contact-workarea') !== false, 'Result feedback must sit beside the selected number');
    assert_true(strpos($javascript, 'function selectPhone') !== false && strpos($javascript, "'[data-gc-phone-select]'") !== false, 'Number tabs must switch the selected detail without a reload');
    assert_true(strpos($css, '.gc-contact-workarea') !== false && strpos($css, '.gc-phone-tabs{display:flex') !== false, 'Desktop phone detail and horizontal selector layout must be styled');
});

test_case('workspace uses the polished client and phone card hierarchy', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $css = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/css/gestion_clientes.css');
    assert_true(strpos($template, 'gc-client-avatar') !== false && strpos($template, 'gc-client-state') !== false, 'Current client header must expose the avatar and state landmarks');
    assert_true(strpos($template, 'gc-phone-summary') !== false && strpos($template, 'gc-history-line') !== false, 'Selected phone must group status and call history clearly');
    assert_true(strpos($template, 'gc-outcome-fields') !== false && strpos($template, 'gc-note-field') !== false, 'Outcome and note controls must share the result panel');
    assert_true(strpos($template, 'gc-phone-tab-check') !== false, 'Selected phone tab must expose a visible selection marker');
    assert_true(strpos($css, '.gc-phone-detail .gc-phone-call-form .gc-call{border-radius:50%') !== false, 'Selected phone call action must use the compact circular treatment');
    assert_true(strpos($css, '.gc-phone-heading .gc-section-icon,.gc-phone-main .gc-phone-icon,.gc-phone-tab .gc-phone-icon{display:none}') !== false, 'Repeated phone decoration must be hidden from the contact heading and number cards');
    assert_true(strpos($css, '.gc-outcome-fields{display:grid;gap:14px 18px;grid-template-columns:minmax(0,1fr) minmax(0,1fr);height:auto}') !== false, 'Outcome controls must use two columns without overlapping');
    assert_true((bool) preg_match('/gc-note-field[\s\S]*<\/label><button type="submit"/', $template), 'The note must appear before the save action in the outcome form');
});

test_case('workspace does not display internal agent identity', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    assert_true(strpos($template, '{$agent.name') === false && strpos($template, '{$agent.number') === false, 'Internal Issabel agent identity must not appear in the workspace header');
});

test_case('agent access is independent from permanent SIP extension', function () {
    $schema = file_get_contents(GC_PROJECT_ROOT . '/install/schema.sql');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_mapping.tpl');
    assert_true(stripos($template, 'sip_extension') === false && stripos($template, 'Extensión SIP') === false, 'Agent access UI must neither show nor request a permanent SIP extension');
    assert_true(!preg_match('/<input\b[^>]*name=[\'\"]sip_extension[\'\"][^>]*required/i', $template), 'Permanent extension must not be required to grant agent access');
    assert_true((bool) preg_match('/sip_extension\s+VARCHAR\([0-9]+\)\s+(NULL|NOT NULL\s+DEFAULT\s+[\'\"]{2})/i', $schema), 'Fresh schema must permit an agent identity without a permanent extension');
});

test_case('agent access activation is idempotent and preserves stable identity', function () {
    $admin = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesAdmin.class.php');
    assert_true((bool) preg_match('/function\s+[^\s(]*(agent|access)[^\s(]*(activate|access|status|enabled)|function\s+[^\s(]*(activate|access|status|enabled)[^\s(]*(agent|access)/i', $admin), 'Admin service must expose a dedicated agent-access operation');
    assert_true((bool) preg_match('/SELECT[\s\S]{0,400}FROM gc_agent_map[\s\S]{0,300}issabel_username=\?/i', $admin), 'Activation must look up and reuse the stable agent identity');
    assert_true((bool) preg_match('/UPDATE gc_agent_map SET[\s\S]{0,300}active=\?/i', $admin), 'Activation/deactivation must update access status in place');
    assert_true(!preg_match('/DELETE\s+FROM\s+gc_agent_map/i', $admin), 'Agent access changes must never delete identity, assignments, or history');
});

test_case('agent access deactivation is blocked by active attempts', function () {
    $admin = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesAdmin.class.php');
    assert_true((bool) preg_match('/gc_attempt[\s\S]{0,500}(CREATED|ORIGINATED|RINGING|ANSWERED)/i', $admin), 'Deactivation must check technical attempts that are still active');
    assert_true((bool) preg_match('/AGENT[^\'\"]*ACTIVE[^\'\"]*(ATTEMPT|CALL)|(ATTEMPT|CALL)[^\'\"]*ACTIVE[^\'\"]*AGENT/i', $admin), 'Active-attempt deactivation must return a specific domain error');
});

test_case('agent access UI lists Issabel users and uses guarded POST buttons', function () {
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_mapping.tpl');
    assert_true((bool) preg_match('/agent_mapping[\s\S]{0,1800}[\'\"]users[\'\"]\s*=>|agent_mapping[\s\S]{0,1800}agentAccessUsers/i', $index), 'Agent access page must receive a list of existing Issabel users');
    $listed = preg_match('/\{foreach\s+from=\$(users|issabel_users)[^}]*\}(.*?)\{\/foreach\}/is', $template, $parts);
    assert_true($listed === 1, 'Agent access UI must render the Issabel user list');
    $userBlock = $parts[2];
    assert_true((bool) preg_match('/<form\b[^>]*method=[\'\"]post[\'\"][^>]*>/i', $userBlock), 'Each user activation/deactivation control must submit a POST form');
    assert_true((bool) preg_match('/<input\b[^>]*name=[\'\"]csrf_token[\'\"][^>]*>/i', $userBlock), 'Every user access mutation form must carry a CSRF token');
    assert_true((bool) preg_match('/<input\b[^>]*name=[\'\"]idempotency_key[\'\"][^>]*>/i', $userBlock), 'Every user access mutation form must carry an idempotency key');
    assert_true((bool) preg_match('/<input\b[^>]*name=[\'\"]issabel_username[\'\"][^>]*>/i', $userBlock), 'Each mutation must identify the listed Issabel user server-side');
    assert_true((bool) preg_match('/<button\b[^>]*type=[\'\"]submit[\'\"]/i', $userBlock), 'Each listed user must have an explicit activation/deactivation submit button');
    assert_true(strpos($index, 'validateMutation') !== false && strpos($index, 'gc_once') !== false, 'Access mutations must use POST/CSRF and idempotency guards');
});

foreach ($tests as $name => $callback) {
    try {
        call_user_func($callback);
        fwrite(STDOUT, "ok - " . $name . "\n");
    } catch (Exception $exception) {
        $failures++;
        fwrite(STDOUT, "not ok - " . $name . "\n  " . str_replace("\n", "\n  ", $exception->getMessage()) . "\n");
    }
}

fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
