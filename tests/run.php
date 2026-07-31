#!/usr/bin/env php
<?php

require dirname(__FILE__) . '/bootstrap.php';

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

test_case('call form cannot override the server-side selected seat', function () {
    $template = file_get_contents(GC_PROJECT_ROOT . '/module/gestion_clientes/themes/default/agent_workspace.tpl');
    $matched = preg_match('/<form[^>]*gc-call-form[^>]*>([\s\S]*?)<\/form>/', $template, $parts);
    assert_true($matched === 1, 'Agent workspace call form is missing');
    assert_true(!preg_match('/name=[\'\"][^\'\"]*(seat|extension)[^\'\"]*[\'\"]/i', $parts[1]), 'Call requests must not post a seat/extension that can override the server-side session selection');
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
