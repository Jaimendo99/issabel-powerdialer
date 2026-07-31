#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$options = getopt('', array('config:', 'limit:', 'min-age:', 'dry-run', 'help'));
if (isset($options['help'])) {
    echo "Usage: reconcile_cdr.php [--config FILE] [--limit 1..500] [--min-age SECONDS] [--dry-run]\n";
    exit(0);
}
$configFile = isset($options['config']) ? $options['config'] : '/etc/issabel/gestion_clientes.conf.php';
$config = is_readable($configFile) ? require $configFile : array();
if (!is_array($config)) { fwrite(STDERR, "Invalid config file\n"); exit(2); }

function gc_env($name, $fallback) { $v = getenv($name); return ($v === false || $v === '') ? $fallback : $v; }
$limit = isset($options['limit']) ? (int)$options['limit'] : (int)gc_env('GC_RECONCILE_LIMIT', 100);
$minAge = isset($options['min-age']) ? (int)$options['min-age'] : (int)gc_env('GC_RECONCILE_MIN_AGE', 120);
if ($limit < 1 || $limit > 500 || $minAge < 0) { fwrite(STDERR, "Invalid bounds\n"); exit(2); }

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
$cdrTimezoneName = isset($config['cdr_timezone']) ? $config['cdr_timezone'] : 'UTC';
try { $cdrTimezone = new DateTimeZone($cdrTimezoneName); }
catch (Exception $e) { fwrite(STDERR, "Invalid CDR timezone\n"); exit(2); }
$utcTimezone = new DateTimeZone('UTC');
$pdoOptions = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC);
try {
    $db = new PDO($appDsn, isset($app['user']) ? $app['user'] : gc_env('GC_DB_USER', ''), isset($app['password']) ? $app['password'] : gc_env('GC_DB_PASSWORD', ''), $pdoOptions);
    $cdrDb = new PDO($cdrDsn, isset($cdr['user']) ? $cdr['user'] : gc_env('GC_CDR_USER', ''), isset($cdr['password']) ? $cdr['password'] : gc_env('GC_CDR_PASSWORD', ''), $pdoOptions);
} catch (Exception $e) { fwrite(STDERR, "Database connection failed\n"); exit(1); }

$cutoff = gmdate('Y-m-d H:i:s', time() - $minAge);
$sql = 'SELECT a.id,a.phone_id,a.cdr_accountcode,p.normalized_value FROM gc_attempt a JOIN gc_client_phone p ON p.id=a.phone_id'
     . ' WHERE a.reconciled_at IS NULL AND a.requested_at<=? ORDER BY a.id ASC LIMIT ' . $limit;
$q = $db->prepare($sql); $q->execute(array($cutoff)); $attempts = $q->fetchAll();
$cdrSql = 'SELECT calldate,dst,dcontext,channel,dstchannel,lastapp,lastdata,duration,billsec,disposition,'
        . 'accountcode,uniqueid,userfield,' . $linkedIdSelect . ',recordingfile FROM `' . $cdrTable . '` WHERE accountcode=? OR userfield=? ORDER BY calldate,uniqueid';
$cdrQ = $cdrDb->prepare($cdrSql);
$update = $db->prepare('UPDATE gc_attempt SET technical_state=?,asterisk_uniqueid=?,linkedid=?,duration_seconds=?,'
    . 'talk_seconds=?,recording_path=?,raw_error_code=?,answered_at=?,ended_at=?,reconciled_at=UTC_TIMESTAMP() WHERE id=? AND reconciled_at IS NULL');
$updatePhone = $db->prepare('UPDATE gc_client_phone SET state=? WHERE id=? AND state NOT IN (\'INVALID\',\'DO_NOT_CALL\')');
$counts = array('selected' => count($attempts), 'updated' => 0, 'waiting' => 0, 'ambiguous' => 0);

foreach ($attempts as $attempt) {
    $cdrQ->execute(array($attempt['cdr_accountcode'], $attempt['cdr_accountcode']));
    $rows = $cdrQ->fetchAll();
    if (!$rows) { $counts['waiting']++; continue; }
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
        } catch (Exception $e) { $db->rollBack(); fwrite(STDERR, 'Attempt ' . $attempt['id'] . " failed\n"); }
    }
}
echo json_encode($counts) . "\n";
