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
    assert_true(strpos($sql, 'UNIQUE KEY uq_gc_attempt_idempotency') !== false, 'Attempt idempotency constraint is missing');
    assert_true(strpos($sql, 'UNIQUE KEY uq_gc_claim_token') !== false, 'Claim token constraint is missing');
    assert_true(strpos($sql, 'ENGINE=InnoDB') !== false, 'Transactional InnoDB tables are required');
    assert_true(strpos($sql, 'gc_idempotency') !== false, 'General mutation idempotency table is missing');
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

test_case('queue does not immediately reclaim follow-up outcome states', function () {
    $workflow = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/libs/GestionClientesWorkflow.class.php');
    assert_true(strpos($workflow, "c.state IN (\\'PENDING\\',\\'NO_CONTACT\\',\\'CALLBACK\\')") !== false, 'Claim queue must only include immediately actionable client states');
    assert_true(strpos($workflow, "agent_note IS NULL OR agent_note=\\'\\'") !== false, 'An idempotent outcome retry must be able to restore a note omitted by the first browser request');
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

test_case('CDR reconciliation has a safe production cron definition', function () {
    $cron = file_get_contents(GC_PROJECT_ROOT . '/install/gestion-clientes.cron');
    assert_true(strpos($cron, '* * * * * root') !== false, 'CDR reconciliation must run every minute');
    assert_true(strpos($cron, '/usr/bin/flock -n') !== false, 'Concurrent reconciliation runs must be prevented');
    assert_true(strpos($cron, '--min-age 120') !== false, 'Cron must allow linked CDR legs to settle');
    assert_true(strpos($cron, '/usr/local/sbin/gestion-clientes-reconcile-cdr') !== false, 'Cron must use the stable production command');
});

test_case('dialplan finalizes the workflow immediately after Dial returns', function () {
    $dialplan = file_get_contents(GC_PROJECT_ROOT . '/asterisk/extensions_gestion_clientes.conf');
    $dial = strpos($dialplan, 'Dial(Local/${EXTEN}@gestion-clientes-route/n,60)');
    $finalize = strpos($dialplan, 'AGI(gestion-clientes-finalize-call,${GC_ATTEMPT_ID},${GC_DIAL_STATUS})');
    assert_true($dial !== false && $finalize !== false && $finalize > $dial, 'Real-time finalization must run after the customer Dial finishes');
});

test_case('real-time finalizer maps Asterisk Dial statuses safely', function () {
    gc_require_class('GestionClientesCallFinalizer', 'module/gestion_clientes/libs/GestionClientesCallFinalizer.class.php');
    $finalizer = new GestionClientesCallFinalizer(null);
    assert_same('ANSWERED', $finalizer->technicalState('ANSWER'), 'Answered calls must settle immediately');
    assert_same('NO_ANSWER', $finalizer->technicalState('NOANSWER'), 'Unanswered calls must settle immediately');
    assert_same('BUSY', $finalizer->technicalState('BUSY'), 'Busy calls must settle immediately');
    assert_same('CANCELED', $finalizer->technicalState('CANCEL'), 'Canceled calls must settle immediately');
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

test_case('workspace phone view exposes per-number state and attempt history', function () {
    $index = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/index.php');
    assert_true((bool) preg_match('/function\s+gc_client_view[\s\S]*?[\'\"]state[\'\"]\s*=>\s*\$p\s*\[[\'\"]state[\'\"]/', $index), 'Each rendered phone must retain its current state');
    assert_true(strpos($index, "'attempt_count'=>(int)\$p['attempt_count']") !== false, 'Each rendered phone must expose its attempt count');
    assert_true(strpos($index, "'last_call_at'=>\$lastCall") !== false && strpos($index, "'last_attempt'=>\$p['last_attempt']") !== false, 'Each rendered phone must expose its localized last-attempt timestamp and summary');
});

test_case('workspace renders one prominent call action bound to each phone id', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $javascript = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/js/gestion_clientes.js');
    $matched = preg_match('/\{foreach\s+from=\$client\.phones[^}]*item=p\}(.*?)\{\/foreach\}/s', $template, $parts);
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
