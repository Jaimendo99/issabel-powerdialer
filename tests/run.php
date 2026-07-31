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
