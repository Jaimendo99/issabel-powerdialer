#!/usr/bin/env php
<?php

function gc_cdr_retry_delay($retryCount)
{
    // 30, 60, 120 seconds and so on, capped at one hour. This keeps each run bounded
    // while allowing newer eligible attempts to move through the same index.
    return min(3600, 30 * pow(2, min(7, max(0, (int)$retryCount))));
}

function gc_cdr_row_key($row)
{
    if (!empty($row['uniqueid'])) return 'u:' . $row['uniqueid'];
    return 'r:' . md5(serialize($row));
}

if (defined('GC_RECONCILE_LIBRARY_ONLY')) {
    return;
}
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$options = getopt('', array('config:', 'limit:', 'min-age:', 'max-retries:', 'dry-run', 'help'));
if (isset($options['help'])) {
    echo "Usage: reconcile_cdr.php [--config FILE] [--limit 1..500] [--min-age SECONDS] [--max-retries 1..100] [--dry-run]\n";
    exit(0);
}
$configFile = isset($options['config']) ? $options['config'] : '/etc/issabel/gestion_clientes.conf.php';
$config = is_readable($configFile) ? require $configFile : array();
if (!is_array($config)) { fwrite(STDERR, "Invalid config file\n"); exit(2); }

function gc_env($name, $fallback) { $v = getenv($name); return ($v === false || $v === '') ? $fallback : $v; }
$limit = isset($options['limit']) ? (int)$options['limit'] : (int)gc_env('GC_RECONCILE_LIMIT', 100);
$minAge = isset($options['min-age']) ? (int)$options['min-age'] : (int)gc_env('GC_RECONCILE_MIN_AGE', 120);
$configuredMaxRetries = isset($config['cdr_max_retries']) ? $config['cdr_max_retries'] : 10;
$maxRetries = isset($options['max-retries']) ? (int)$options['max-retries'] : (int)gc_env('GC_RECONCILE_MAX_RETRIES', $configuredMaxRetries);
if ($limit < 1 || $limit > 500 || $minAge < 0 || $maxRetries < 1 || $maxRetries > 100) { fwrite(STDERR, "Invalid bounds\n"); exit(2); }

$app = isset($config['app']) ? $config['app'] : array(
    'dsn' => isset($config['db_dsn']) ? $config['db_dsn'] : null,
    'user' => isset($config['db_user']) ? $config['db_user'] : null,
    'password' => isset($config['db_password']) ? $config['db_password'] : null
);
$cdr = isset($config['cdr']) ? $config['cdr'] : array(
    'dsn' => isset($config['cdr_dsn']) ? $config['cdr_dsn'] : null,
    'user' => isset($config['cdr_user']) ? $config['cdr_user'] : null,
    'password' => isset($config['cdr_password']) ? $config['cdr_password'] : null
);
$appDsn = isset($app['dsn']) ? $app['dsn'] : gc_env('GC_DB_DSN', 'mysql:host=127.0.0.1;dbname=gestion_clientes;charset=utf8');
$cdrDsn = isset($cdr['dsn']) ? $cdr['dsn'] : gc_env('GC_CDR_DSN', 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8');
$cdrTable = isset($config['cdr_table']) ? $config['cdr_table'] : (isset($cdr['table']) ? $cdr['table'] : 'cdr');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $cdrTable)) { fwrite(STDERR, "Invalid CDR table\n"); exit(2); }
$linkedIdColumn = isset($config['cdr_linkedid_column']) ? $config['cdr_linkedid_column'] : 'linkedid';
if ($linkedIdColumn !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $linkedIdColumn)) { fwrite(STDERR, "Invalid linkedid column\n"); exit(2); }
$linkedIdSelect = $linkedIdColumn === '' ? "'' AS linkedid" : '`' . $linkedIdColumn . '` AS linkedid';
$cdrTimezoneName = isset($config['cdr_timezone']) ? $config['cdr_timezone'] : (isset($config['timezone']) ? $config['timezone'] : 'UTC');
try { $cdrTimezone = new DateTimeZone($cdrTimezoneName); }
catch (Exception $e) { fwrite(STDERR, "Invalid CDR timezone\n"); exit(2); }
$utcTimezone = new DateTimeZone('UTC');
$pdoOptions = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
try {
    $db = new PDO($appDsn, isset($app['user']) ? $app['user'] : gc_env('GC_DB_USER', ''), isset($app['password']) ? $app['password'] : gc_env('GC_DB_PASSWORD', ''), $pdoOptions);
    $cdrDb = new PDO($cdrDsn, isset($cdr['user']) ? $cdr['user'] : gc_env('GC_CDR_USER', ''), isset($cdr['password']) ? $cdr['password'] : gc_env('GC_CDR_PASSWORD', ''), $pdoOptions);
} catch (Exception $e) { fwrite(STDERR, "Database connection failed\n"); exit(1); }

if (!isset($options['dry-run'])) {
    $heartbeat = $db->prepare('INSERT INTO gc_operational_status (component,last_started_at,last_completed_at,last_status,last_message,details_json,updated_at)'
        . ' VALUES (\'cdr_reconciler\',UTC_TIMESTAMP(),NULL,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE'
        . ' last_started_at=UTC_TIMESTAMP(),last_completed_at=NULL,last_status=VALUES(last_status),last_message=VALUES(last_message),details_json=VALUES(details_json),updated_at=UTC_TIMESTAMP()');
    $heartbeat->execute(array('RUNNING', 'Reconciliation started', '{}'));
    $heartbeatCompleted = false;
    register_shutdown_function(function () use ($db, &$heartbeatCompleted) {
        if ($heartbeatCompleted) return;
        try {
            $failed = $db->prepare('UPDATE gc_operational_status SET last_completed_at=UTC_TIMESTAMP(),last_status=\'ERROR\',last_message=\'Reconciliation terminated unexpectedly\',details_json=\'{}\',updated_at=UTC_TIMESTAMP() WHERE component=\'cdr_reconciler\'');
            $failed->execute();
        } catch (Exception $ignored) {}
    });
}

$cutoff = gmdate('Y-m-d H:i:s', time() - $minAge);
$sql = 'SELECT a.id,a.phone_id,a.correlation_token,a.cdr_accountcode,a.cdr_retry_count,p.normalized_value FROM gc_attempt a JOIN gc_client_phone p ON p.id=a.phone_id'
     . ' WHERE a.reconciled_at IS NULL AND a.cdr_exhausted_at IS NULL AND (a.raw_error_code IS NULL OR a.raw_error_code NOT LIKE \'AMI_AGENT_%\')'
     . ' AND a.requested_at<=? AND (a.cdr_next_retry_at IS NULL OR a.cdr_next_retry_at<=UTC_TIMESTAMP())'
     . ' ORDER BY COALESCE(a.cdr_next_retry_at,a.requested_at) ASC,a.id ASC LIMIT ' . $limit;
$q = $db->prepare($sql); $q->execute(array($cutoff)); $attempts = $q->fetchAll();
$cdrSql = 'SELECT calldate,dst,dcontext,channel,dstchannel,lastapp,lastdata,duration,billsec,disposition,'
        . 'accountcode,uniqueid,userfield,' . $linkedIdSelect . ',recordingfile FROM `' . $cdrTable . '` WHERE accountcode=? OR userfield=? ORDER BY calldate,uniqueid';
$cdrQ = $cdrDb->prepare($cdrSql);
$update = $db->prepare('UPDATE gc_attempt SET technical_state=?,asterisk_uniqueid=?,linkedid=?,duration_seconds=?,'
    . 'talk_seconds=?,recording_path=?,raw_error_code=?,answered_at=?,ended_at=?,cdr_last_checked_at=UTC_TIMESTAMP(),cdr_last_error=NULL,reconciled_at=UTC_TIMESTAMP() WHERE id=? AND reconciled_at IS NULL');
$updatePhone = $db->prepare('UPDATE gc_client_phone SET state=? WHERE id=? AND state NOT IN (\'INVALID\',\'DO_NOT_CALL\')');
$scheduleRetry = $db->prepare('UPDATE gc_attempt SET cdr_retry_count=cdr_retry_count+1,cdr_next_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),cdr_last_checked_at=UTC_TIMESTAMP(),cdr_last_error=? WHERE id=? AND reconciled_at IS NULL AND cdr_exhausted_at IS NULL');
$exhaustRetry = $db->prepare('UPDATE gc_attempt SET cdr_retry_count=cdr_retry_count+1,cdr_next_retry_at=NULL,cdr_last_checked_at=UTC_TIMESTAMP(),cdr_exhausted_at=UTC_TIMESTAMP(),cdr_last_error=? WHERE id=? AND reconciled_at IS NULL AND cdr_exhausted_at IS NULL');
$counts = array('selected' => count($attempts), 'updated' => 0, 'waiting' => 0, 'ambiguous' => 0, 'exhausted' => 0, 'errors' => 0);

foreach ($attempts as $attempt) {
    $fullUserfield = 'GC-' . $attempt['correlation_token'];
    $cdrQ->execute(array($attempt['cdr_accountcode'], $fullUserfield));
    $rows = $cdrQ->fetchAll();
    if (!$rows) {
        $counts['waiting']++;
        if (!isset($options['dry-run'])) {
            if ((int)$attempt['cdr_retry_count'] + 1 >= $maxRetries) {
                $exhaustRetry->execute(array('CDR_NOT_FOUND', $attempt['id']));
                $counts['exhausted']++;
                fwrite(STDERR, 'Attempt ' . $attempt['id'] . " exhausted: CDR_NOT_FOUND\n");
            } else {
                $scheduleRetry->execute(array(gc_cdr_retry_delay($attempt['cdr_retry_count']), 'CDR_NOT_FOUND', $attempt['id']));
            }
        }
        continue;
    }
    // Correlation tags may be present only on the agent/Local leg. Expand to all
    // finalized CDR rows sharing its linked ID before selecting the customer leg.
    if ($linkedIdColumn !== '') {
        $linkedValues = array();
        foreach ($rows as $row) if (!empty($row['linkedid'])) $linkedValues[$row['linkedid']] = true;
        if ($linkedValues) {
            $placeholders = implode(',', array_fill(0, count($linkedValues), '?'));
            $linkedSql = 'SELECT calldate,dst,dcontext,channel,dstchannel,lastapp,lastdata,duration,billsec,disposition,'
                       . 'accountcode,uniqueid,userfield,' . $linkedIdSelect . ',recordingfile FROM `' . $cdrTable . '` WHERE `'
                       . $linkedIdColumn . '` IN (' . $placeholders . ') ORDER BY calldate,uniqueid';
            $linkedQ = $cdrDb->prepare($linkedSql);
            $linkedQ->execute(array_keys($linkedValues));
            $allRows = array();
            foreach (array_merge($rows, $linkedQ->fetchAll()) as $row) $allRows[gc_cdr_row_key($row)] = $row;
            $rows = array_values($allRows);
        }
    }
    $linked = array();
    foreach ($rows as $row) if (!empty($row['linkedid'])) $linked[$row['linkedid']] = true;
    $ambiguous = count($linked) > 1;
    $candidates = array();
    $normalizedDigits = preg_replace('/\D+/', '', $attempt['normalized_value']);
    foreach ($rows as $row) {
        $digits = preg_replace('/\D+/', '', isset($row['dst']) ? $row['dst'] : '');
        if ($digits === $normalizedDigits || (strlen($digits) >= 9 && substr($digits, -9) === substr($normalizedDigits, -9))) $candidates[] = $row;
    }
    if (!$candidates && count($rows) > 1) $ambiguous = true;
    $source = $candidates ? $candidates : $rows;
    $state = 'FAILED'; $duration = 0; $talk = 0; $uniqueid = null; $linkedid = null; $recording = null; $answeredAt = null; $endedAt = null;
    $rank = array('FAILED'=>1, 'NO_ANSWER'=>2, 'BUSY'=>3, 'ANSWERED'=>4);
    foreach ($source as $row) {
        $disp = strtoupper(str_replace(' ', '_', $row['disposition']));
        $candidateState = $disp === 'ANSWERED' ? 'ANSWERED' : ($disp === 'BUSY' ? 'BUSY' : (($disp === 'NO_ANSWER' || $disp === 'NOANSWER') ? 'NO_ANSWER' : 'FAILED'));
        if ($rank[$candidateState] >= $rank[$state]) { $state = $candidateState; $uniqueid = $row['uniqueid']; $linkedid = $row['linkedid']; }
        $duration = max($duration, (int)$row['duration']); $talk = max($talk, (int)$row['billsec']);
        $rowStart = new DateTime($row['calldate'], $cdrTimezone);
        $rowStart->setTimezone($utcTimezone);
        $rowStartEpoch = $rowStart->getTimestamp();
        $rowEnd = gmdate('Y-m-d H:i:s', $rowStartEpoch + (int)$row['duration']);
        if ($endedAt === null || $rowEnd > $endedAt) $endedAt = $rowEnd;
        if ($candidateState === 'ANSWERED' && $answeredAt === null) $answeredAt = gmdate('Y-m-d H:i:s', $rowStartEpoch + max(0, (int)$row['duration'] - (int)$row['billsec']));
        if (!empty($row['recordingfile'])) $recording = $row['recordingfile'];
    }
    if ($ambiguous) { $state = 'AMBIGUOUS'; $counts['ambiguous']++; }
    if (!isset($options['dry-run'])) {
        $db->beginTransaction();
        try {
            $update->execute(array($state, $uniqueid, $linkedid, $duration, $talk, $recording,
                $ambiguous ? 'CDR_MATCH_AMBIGUOUS' : null, $answeredAt, $endedAt, $attempt['id']));
            if (!$ambiguous) {
                $phoneState = $state === 'ANSWERED' ? 'ANSWERED' : (($state === 'NO_ANSWER' || $state === 'BUSY') ? 'NO_ANSWER' : 'ATTEMPTED');
                $updatePhone->execute(array($phoneState, $attempt['phone_id']));
            }
            $counts['updated'] += $update->rowCount(); $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            $counts['errors']++;
            $errorCode = 'CDR_UPDATE_FAILED';
            if ((int)$attempt['cdr_retry_count'] + 1 >= $maxRetries) {
                $exhaustRetry->execute(array($errorCode, $attempt['id']));
                $counts['exhausted']++;
            } else {
                $scheduleRetry->execute(array(gc_cdr_retry_delay($attempt['cdr_retry_count']), $errorCode, $attempt['id']));
            }
            fwrite(STDERR, 'Attempt ' . $attempt['id'] . " failed\n");
        }
    }
}
$status = ($counts['exhausted'] > 0 || $counts['errors'] > 0 || $counts['ambiguous'] > 0) ? 'WARNING' : 'OK';
$message = $status === 'OK' ? 'Reconciliation completed' : 'Reconciliation requires attention';
$details = json_encode($counts);
if (!isset($options['dry-run'])) {
    $finished = $db->prepare('UPDATE gc_operational_status SET last_completed_at=UTC_TIMESTAMP(),last_status=?,last_message=?,details_json=?,updated_at=UTC_TIMESTAMP() WHERE component=\'cdr_reconciler\'');
    $finished->execute(array($status, $message, $details));
    $heartbeatCompleted = true;
}
echo $details . "\n";
