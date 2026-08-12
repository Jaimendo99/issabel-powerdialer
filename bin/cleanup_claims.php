#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
$options = getopt('', array('config:', 'module-root:', 'limit:', 'help'));
if (isset($options['help'])) { echo "Usage: cleanup_claims.php [--config FILE] [--module-root DIR] [--limit 1..1000]\n"; exit(0); }
$configFile = isset($options['config']) ? $options['config'] : '/etc/issabel/gestion_clientes.conf.php';
$moduleRoot = isset($options['module-root']) ? $options['module-root'] : getenv('GC_MODULE_ROOT');
if (!$moduleRoot) $moduleRoot = '/var/www/html/modules/gestion_clientes';
$limit = isset($options['limit']) ? (int)$options['limit'] : 250;
if ($limit < 1 || $limit > 1000) { fwrite(STDERR, "Invalid limit\n"); exit(2); }

try {
    if (!is_readable($configFile)) throw new RuntimeException('Configuration is not readable');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Configuration is invalid');
    $library = rtrim($moduleRoot, '/') . '/libs/paloSantoGestionClientes.class.php';
    if (!is_readable($library)) throw new RuntimeException('Deployed module library is not readable');
    require_once $library;
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
    $db = new paloSantoGestionClientes($pdo);
    $released = $db->transaction(function ($tx) use ($limit) {
        $rows = $tx->fetchAll(
            'SELECT cl.client_id,c.state,(SELECT MIN(cb.due_at_utc) FROM gc_callback cb WHERE cb.client_id=cl.client_id AND cb.status=\'OPEN\') AS callback_due_at'
            . ' FROM gc_client_claim cl JOIN gc_client c ON c.id=cl.client_id WHERE cl.expires_at<=UTC_TIMESTAMP()'
            . ' AND NOT EXISTS (SELECT 1 FROM gc_attempt at WHERE at.assignment_id=cl.assignment_id AND (at.ended_at IS NULL OR (at.business_outcome_id IS NULL AND (at.raw_error_code IS NULL OR at.raw_error_code NOT LIKE \'AMI_AGENT_%\'))))'
            . ' ORDER BY cl.expires_at,cl.client_id LIMIT ' . $limit . ' FOR UPDATE',
            array()
        );
        foreach ($rows as $row) {
            $newState = $row['callback_due_at'] !== null ? 'CALLBACK' : 'PENDING';
            $tx->execute('UPDATE gc_client SET state=?,next_action_at=?,updated_at=UTC_TIMESTAMP(),row_version=row_version+1 WHERE id=? AND terminal=0', array($newState, $row['callback_due_at'], $row['client_id']));
            $tx->execute('DELETE FROM gc_client_claim WHERE client_id=? AND expires_at<=UTC_TIMESTAMP()', array($row['client_id']));
            $tx->audit($row['client_id'], 'system', 'CLAIM_EXPIRED', $row['state'], $newState, array(), null);
        }
        return count($rows);
    });
    echo json_encode(array('released'=>$released)) . "\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Claim cleanup failed\n");
    exit(1);
}
