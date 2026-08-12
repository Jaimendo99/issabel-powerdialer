#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$options = getopt('', array('config:', 'json', 'heartbeat-max-age:', 'active-max-age:', 'pending-max-age:', 'help'));
if (isset($options['help'])) {
    echo "Usage: health_check.php [--config FILE] [--json] [--heartbeat-max-age SECONDS] [--active-max-age SECONDS] [--pending-max-age SECONDS]\n";
    exit(0);
}

$configFile = isset($options['config']) ? $options['config'] : '/etc/issabel/gestion_clientes.conf.php';
$heartbeatMaxAge = isset($options['heartbeat-max-age']) ? (int)$options['heartbeat-max-age'] : 300;
$activeMaxAge = isset($options['active-max-age']) ? (int)$options['active-max-age'] : 900;
$pendingMaxAge = isset($options['pending-max-age']) ? (int)$options['pending-max-age'] : 900;
if ($heartbeatMaxAge < 60 || $activeMaxAge < 300 || $pendingMaxAge < 120) {
    fwrite(STDERR, "Invalid bounds\n");
    exit(2);
}

$report = array('status'=>'OK', 'checked_at'=>gmdate('c'), 'checks'=>array(), 'metrics'=>array());
$severity = array('OK'=>0, 'WARNING'=>1, 'CRITICAL'=>2);

function gc_health_add(&$report, $name, $status, $message)
{
    global $severity;
    $report['checks'][] = array('name'=>$name, 'status'=>$status, 'message'=>$message);
    if ($severity[$status] > $severity[$report['status']]) $report['status'] = $status;
}

try {
    if (!is_readable($configFile)) throw new RuntimeException('Configuration is not readable');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Configuration is invalid');
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], array(
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ));
    $pdo->query('SELECT 1')->fetch();
    gc_health_add($report, 'database', 'OK', 'Application database is reachable');

    $schema = $pdo->query('SELECT MAX(version_num) AS version_num FROM gc_schema_version')->fetch();
    $version = $schema && $schema['version_num'] !== null ? (int)$schema['version_num'] : 0;
    $report['metrics']['schema_version'] = $version;
    gc_health_add($report, 'schema', $version >= 6 ? 'OK' : 'CRITICAL', 'Schema version ' . $version . ' (required: 6)');

    $heartbeat = $pdo->query("SELECT last_started_at,last_completed_at,last_status,last_message,TIMESTAMPDIFF(SECOND,updated_at,UTC_TIMESTAMP()) AS age_seconds FROM gc_operational_status WHERE component='cdr_reconciler'")->fetch();
    if (!$heartbeat) {
        gc_health_add($report, 'cdr_reconciler', 'CRITICAL', 'No reconciliation heartbeat exists');
    } else {
        $age = (int)$heartbeat['age_seconds'];
        $report['metrics']['reconciler_age_seconds'] = $age;
        if ($age > $heartbeatMaxAge || $heartbeat['last_completed_at'] === null) {
            gc_health_add($report, 'cdr_reconciler', 'CRITICAL', 'Reconciler heartbeat is stale or incomplete');
        } elseif ($heartbeat['last_status'] !== 'OK') {
            gc_health_add($report, 'cdr_reconciler', 'WARNING', 'Last reconciler status: ' . $heartbeat['last_status']);
        } else {
            gc_health_add($report, 'cdr_reconciler', 'OK', 'Reconciler completed recently');
        }
    }

    $activeSql = 'SELECT COUNT(*) FROM gc_attempt WHERE ended_at IS NULL AND requested_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMaxAge . ' SECOND)';
    $staleActive = (int)$pdo->query($activeSql)->fetchColumn();
    $report['metrics']['stale_active_attempts'] = $staleActive;
    gc_health_add($report, 'active_attempts', $staleActive ? 'CRITICAL' : 'OK', $staleActive . ' stale active attempt(s)');

    $pendingSql = 'SELECT COUNT(*) FROM gc_attempt WHERE reconciled_at IS NULL AND cdr_exhausted_at IS NULL AND ended_at IS NOT NULL AND requested_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $pendingMaxAge . ' SECOND) AND (raw_error_code IS NULL OR raw_error_code NOT LIKE \'AMI_AGENT_%\')';
    $pending = (int)$pdo->query($pendingSql)->fetchColumn();
    $report['metrics']['overdue_reconciliation'] = $pending;
    gc_health_add($report, 'pending_reconciliation', $pending ? 'WARNING' : 'OK', $pending . ' overdue reconciliation attempt(s)');

    $exhausted = (int)$pdo->query('SELECT COUNT(*) FROM gc_attempt WHERE cdr_exhausted_at IS NOT NULL AND reconciled_at IS NULL')->fetchColumn();
    $report['metrics']['exhausted_reconciliation'] = $exhausted;
    gc_health_add($report, 'exhausted_reconciliation', $exhausted ? 'WARNING' : 'OK', $exhausted . ' attempt(s) require supervisor review');

    $ambiguous = (int)$pdo->query("SELECT COUNT(*) FROM gc_attempt WHERE technical_state='AMBIGUOUS'")->fetchColumn();
    $report['metrics']['ambiguous_attempts'] = $ambiguous;
    gc_health_add($report, 'ambiguous_attempts', $ambiguous ? 'WARNING' : 'OK', $ambiguous . ' ambiguous attempt(s) require supervisor review');

    $missingOutcomes = (int)$pdo->query("SELECT COUNT(*) FROM gc_attempt WHERE ended_at IS NOT NULL AND business_outcome_id IS NULL AND ended_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) AND (raw_error_code IS NULL OR raw_error_code NOT LIKE 'AMI_AGENT_%')")->fetchColumn();
    $report['metrics']['overdue_outcomes'] = $missingOutcomes;
    gc_health_add($report, 'overdue_outcomes', $missingOutcomes ? 'WARNING' : 'OK', $missingOutcomes . ' completed call(s) await an outcome');

    $orphanClients = (int)$pdo->query("SELECT COUNT(*) FROM gc_client c LEFT JOIN gc_client_claim cl ON cl.client_id=c.id WHERE c.state='IN_PROGRESS' AND c.terminal=0 AND cl.client_id IS NULL")->fetchColumn();
    $report['metrics']['orphan_in_progress_clients'] = $orphanClients;
    gc_health_add($report, 'orphan_clients', $orphanClients ? 'WARNING' : 'OK', $orphanClients . ' in-progress client(s) have no claim');

    $duplicateClaims = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT agent_map_id FROM gc_client_claim GROUP BY agent_map_id HAVING COUNT(*)>1) duplicate_agents')->fetchColumn();
    $report['metrics']['agents_with_multiple_claims'] = $duplicateClaims;
    gc_health_add($report, 'multiple_claims', $duplicateClaims ? 'CRITICAL' : 'OK', $duplicateClaims . ' agent(s) have multiple current clients');

    $dueCallbacks = (int)$pdo->query("SELECT COUNT(*) FROM gc_callback WHERE status='OPEN' AND due_at_utc<=UTC_TIMESTAMP()")->fetchColumn();
    $report['metrics']['due_callbacks'] = $dueCallbacks;
    gc_health_add($report, 'callbacks', 'OK', $dueCallbacks . ' callback(s) currently due');
} catch (Exception $e) {
    gc_health_add($report, 'runtime', 'CRITICAL', $e->getMessage());
}

if (isset($options['json'])) {
    echo json_encode($report) . "\n";
} else {
    echo 'Gestion Clientes health: ' . $report['status'] . "\n";
    foreach ($report['checks'] as $check) {
        echo '[' . $check['status'] . '] ' . $check['name'] . ': ' . $check['message'] . "\n";
    }
}
exit($report['status'] === 'OK' ? 0 : ($report['status'] === 'WARNING' ? 1 : 2));
