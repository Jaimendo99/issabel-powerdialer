<?php

require_once dirname(__FILE__) . '/libs/paloSantoGestionClientes.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesValidator.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesAuth.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesImport.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesAssignment.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesDialer.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesWorkflow.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesStats.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesAdmin.class.php';
require_once dirname(__FILE__) . '/libs/GestionClientesSeatSession.class.php';

function _moduleContent(&$smarty, $module_name)
{
    if (session_id() === '') {
        session_start();
    }
    $requestId = gc_request_id();
    try {
        $config = include dirname(__FILE__) . '/configs/default.conf.php';
        $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $db = new paloSantoGestionClientes($pdo);
        $auth = new GestionClientesAuth($db, $config);
        $username = $auth->username();
        $action = gc_param('action', $auth->isSupervisor($username) ? 'campaign_list' : 'workspace');
        $result = gc_dispatch($action, $smarty, $db, $auth, $config, $username, $requestId);
        if (is_array($result) && isset($result['_json'])) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
            return json_encode($result['_json']);
        }
        return $result;
    } catch (Exception $e) {
        $code = preg_match('/^[A-Z0-9_]+$/', $e->getMessage()) ? $e->getMessage() : 'INTERNAL_ERROR';
        error_log('[gestion_clientes][' . $requestId . '] ' . get_class($e) . ': ' . $e->getMessage());
        if (gc_wants_json()) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
            return json_encode(gc_response(false, $code, gc_message($code), array(), $requestId));
        }
        return '<div class="error gc-error">' . htmlspecialchars(gc_message($code), ENT_QUOTES, 'UTF-8') . ' <small>' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</small></div>';
    }
}

function gc_dispatch($action, &$smarty, $db, $auth, $config, $username, $requestId)
{
    $admin = new GestionClientesAdmin($db, $config);
    $base = '?menu=gestion_clientes';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $csrf = $auth->csrfToken();
    $seatSessions = new GestionClientesSeatSession($db, isset($config['seat_session_ttl_seconds']) ? $config['seat_session_ttl_seconds'] : 43200);

    if ($action === 'called_clients') {
        $agent = $auth->agentMap(false);
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $rows = $workflow->calledClients($agent['id'], 250);
        foreach ($rows as &$row) {
            $timezone = $row['timezone'] ? $row['timezone'] : $config['timezone'];
            $row['called_at'] = gc_utc_to_local($row['requested_at'], $timezone);
            $row['callback_at'] = $row['callback_due_at'] ? gc_utc_to_local($row['callback_due_at'], $row['callback_timezone'] ? $row['callback_timezone'] : $timezone) : null;
            $row['reopen_idempotency_key'] = $db->uuid();
        }
        gc_assign($smarty, array('title'=>'Mis clientes llamados','clients'=>$rows,'csrf_token'=>$csrf,'reopen_url'=>$base.'&action=reopen_client','workspace_url'=>$base.'&action=workspace'));
        return gc_render($smarty, 'called_clients.tpl');
    }
    if ($action === 'reopen_client') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        $clientId = (int)gc_param('client_id', 0);
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        gc_once($db, $username, 'reopen_client', gc_param('idempotency_key', ''), function () use ($workflow, $agent, $clientId, $username, $ip) {
            return $workflow->reopenClient($agent['id'], $clientId, $username, $ip);
        });
        return gc_redirect_or_json($base.'&action=workspace', 'CLIENT_REOPENED', 'Cliente abierto nuevamente.', array('client_id'=>$clientId), $requestId);
    }

    if ($action === 'seat_select') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        gc_once($db, $username, 'seat_select', gc_param('idempotency_key', ''), function () use ($seatSessions, $agent) {
            return $seatSessions->select($agent['id'], gc_param('sip_extension', ''));
        });
        return gc_redirect_or_json($base . '&action=workspace', 'SEAT_SELECTED', 'Extensión seleccionada.', array(), $requestId);
    }
    if ($action === 'seat_release') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        gc_once($db, $username, 'seat_release', gc_param('idempotency_key', ''), function () use ($seatSessions, $agent) {
            return $seatSessions->release($agent['id']);
        });
        return gc_redirect_or_json($base . '&action=workspace', 'SEAT_RELEASED', 'Extensión liberada.', array(), $requestId);
    }

    if ($action === 'api_claim_next') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $client = $workflow->claimNext($agent['id'], $username, $ip);
        return array('_json' => gc_response(true, $client ? 'CLIENT_CLAIMED' : 'QUEUE_EMPTY', $client ? 'Cliente asignado.' : 'No hay clientes pendientes.', $client, $requestId));
    }
    if ($action === 'api_current_client') {
        $agent = $auth->agentMap(false);
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        return array('_json' => gc_response(true, 'CURRENT_CLIENT', 'Cliente actual.', $workflow->currentClient($agent['id']), $requestId));
    }
    if ($action === 'api_start_call') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        $seat = $seatSessions->current($agent['id'], true);
        if (!$seat) { throw new RuntimeException('SEAT_SELECTION_REQUIRED'); }
        $latestAttempt = $db->fetchOne('SELECT id, technical_state, ended_at, business_outcome_id, raw_error_code FROM gc_attempt WHERE agent_map_id=? ORDER BY id DESC LIMIT 1', array($agent['id']));
        if ($latestAttempt && empty($latestAttempt['ended_at']) && in_array($latestAttempt['technical_state'], array('CREATED','ORIGINATED','RINGING','ANSWERED','AMBIGUOUS'), true)) {
            throw new RuntimeException('ACTIVE_ATTEMPT_EXISTS');
        }
        $agentOnlyFailure = $latestAttempt && strpos((string)$latestAttempt['raw_error_code'], 'AMI_AGENT_') === 0;
        if ($latestAttempt && !empty($latestAttempt['ended_at']) && $latestAttempt['business_outcome_id'] === null && !$agentOnlyFailure) {
            throw new RuntimeException('OUTCOME_REQUIRED_BEFORE_CALL');
        }
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $attempt = $workflow->startCall($agent, $seat['id'], (int)gc_param('client_id', 0), (int)gc_param('phone_id', 0), gc_param('claim_token', ''), gc_param('idempotency_key', ''), $username, $ip);
        $agentOnlyFailure = !empty($attempt['agent_only_failure']);
        return array('_json' => gc_response(true, $agentOnlyFailure ? 'AGENT_LEG_FAILED' : 'CALL_ACCEPTED', $agentOnlyFailure ? 'No se pudo conectar con la extensión. El cliente no fue llamado y puede intentar nuevamente.' : 'La extensión contestó; llamando al cliente.', $attempt, $requestId));
    }
    if ($action === 'api_attempt_status') {
        $agent = $auth->agentMap(false);
        $attempt = $db->fetchOne('SELECT id, phone_id, technical_state, business_outcome_id, originated_at, answered_at, ended_at, duration_seconds, talk_seconds, raw_error_code FROM gc_attempt WHERE id=? AND agent_map_id=?', array((int)gc_param('attempt_id', 0), $agent['id']));
        if (!$attempt) { throw new RuntimeException('ATTEMPT_OWNERSHIP_INVALID'); }
        $attempt['agent_only_failure'] = strpos((string)$attempt['raw_error_code'], 'AMI_AGENT_') === 0;
        $attempt['outcome_required'] = !empty($attempt['ended_at']) && $attempt['business_outcome_id'] === null && !$attempt['agent_only_failure'];
        return array('_json' => gc_response(true, 'ATTEMPT_STATUS', 'Estado actualizado.', $attempt, $requestId));
    }
    if ($action === 'api_client_history') {
        $agent = $auth->agentMap(false);
        $history = $db->fetchAll('SELECT at.id, at.phone_id, at.requested_at, at.technical_state, at.duration_seconds, at.talk_seconds, at.agent_note, o.code AS outcome_code, o.label AS outcome_label FROM gc_attempt at JOIN gc_assignment a ON a.id=at.assignment_id LEFT JOIN gc_outcome o ON o.id=at.business_outcome_id WHERE at.client_id=? AND a.agent_map_id=? ORDER BY at.id DESC LIMIT 100', array((int)gc_param('client_id',0), $agent['id']));
        return array('_json' => gc_response(true, 'CLIENT_HISTORY', 'Historial del cliente.', $history, $requestId));
    }
    if ($action === 'api_save_outcome') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        $callback = array('due_at' => gc_param('callback_at', ''), 'timezone' => gc_param('callback_timezone', ''), 'note' => gc_param('note', ''));
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $saved = $workflow->saveOutcome($agent['id'], (int)gc_param('attempt_id', 0), (int)gc_param('outcome_id', 0), gc_param('note', ''), $callback, $username, $ip);
        return array('_json' => gc_response(true, 'OUTCOME_SAVED', 'Resultado guardado.', $saved, $requestId));
    }
    if ($action === 'api_schedule_callback') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $agent = $auth->agentMap(true);
        $attempt = $db->fetchOne('SELECT campaign_id FROM gc_attempt WHERE id=? AND agent_map_id=?', array((int)gc_param('attempt_id',0), $agent['id']));
        if (!$attempt) { throw new RuntimeException('ATTEMPT_OWNERSHIP_INVALID'); }
        $outcome = $db->fetchOne('SELECT id FROM gc_outcome WHERE code=\'CALLBACK\' AND active=1 AND (campaign_id=? OR campaign_id IS NULL) ORDER BY campaign_id DESC LIMIT 1', array($attempt['campaign_id']));
        if (!$outcome) { throw new RuntimeException('CALLBACK_OUTCOME_MISSING'); }
        $callback = array('due_at'=>gc_param('callback_at',''),'timezone'=>gc_param('callback_timezone',''),'note'=>gc_param('note',''));
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $saved = $workflow->saveOutcome($agent['id'], (int)gc_param('attempt_id',0), $outcome['id'], gc_param('note',''), $callback, $username, $ip);
        return array('_json'=>gc_response(true,'CALLBACK_SCHEDULED','Callback programado.',$saved,$requestId));
    }
    if ($action === 'callback_manage') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $supervisor = $auth->isSupervisor($username);
        $agentId = null;
        if (!$supervisor) { $agentId = $auth->agentMap(true)['id']; }
        $callbackId = (int)gc_param('callback_id', 0);
        $callbackAction = strtoupper(gc_param('callback_action', ''));
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        $managed = gc_once($db, $username, 'callback_manage', gc_param('idempotency_key', ''), function () use ($workflow, $callbackId, $agentId, $supervisor, $callbackAction, $username, $ip) {
            return $workflow->manageCallback($callbackId, $agentId, $supervisor, $callbackAction, gc_param('callback_at', ''), gc_param('callback_timezone', ''), gc_param('note', ''), $username, $ip);
        });
        return gc_redirect_or_json($base . '&action=callbacks', $callbackAction === 'CANCEL' ? 'CALLBACK_CANCELED' : 'CALLBACK_RESCHEDULED', $callbackAction === 'CANCEL' ? 'Callback cancelado.' : 'Callback reprogramado.', $managed, $requestId);
    }

    if ($action === 'workspace') {
        $agent = $auth->agentMap(false);
        $selectedSeat = $seatSessions->current($agent['id'], true);
        $workflow = new GestionClientesWorkflow($db, new GestionClientesDialer($config), $config['claim_ttl_seconds']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $workflow->claimNext($agent['id'], $username, $ip);
        }
        $client = $workflow->currentClient($agent['id']);
        $attempt = $client ? $db->fetchOne('SELECT * FROM gc_attempt WHERE client_id=? AND agent_map_id=? ORDER BY id DESC LIMIT 1', array($client['id'], $agent['id'])) : array();
        $campaignId = $client ? $client['campaign_id'] : 0;
        $campaign = $campaignId ? $db->fetchOne('SELECT id, timezone, status FROM gc_campaign WHERE id=?', array($campaignId)) : array('timezone' => $config['timezone'], 'status'=>'ACTIVE');
        if (!$campaign) { $campaign = array('timezone' => $config['timezone'], 'status'=>'ACTIVE'); }
        $viewClient = $client ? gc_client_view($client, $campaign['timezone']) : array();
        $attemptActive = $attempt && empty($attempt['ended_at']) && in_array($attempt['technical_state'], array('CREATED','ORIGINATED','RINGING','ANSWERED','AMBIGUOUS'), true);
        $agentOnlyFailure = $attempt && strpos((string)$attempt['raw_error_code'], 'AMI_AGENT_') === 0;
        $outcomeRequired = $attempt && !empty($attempt['ended_at']) && $attempt['business_outcome_id'] === null && !$agentOnlyFailure;
        $outcomes = $db->fetchAll('SELECT * FROM gc_outcome WHERE active=1 AND (campaign_id IS NULL OR campaign_id=?) ORDER BY campaign_id DESC, display_order, id', array($campaignId));
        gc_assign($smarty, array('title' => 'Mi cartera', 'csrf_token' => $csrf, 'agent' => array('name' => $agent['issabel_username'], 'number' => $agent['agent_number'], 'mapped' => 1), 'seats' => $seatSessions->activeSeats(), 'selected_seat' => $selectedSeat ? $selectedSeat : array(), 'seat_select_url' => $base . '&action=seat_select', 'seat_release_url' => $base . '&action=seat_release', 'seat_idempotency_key' => $db->uuid(), 'seat_release_idempotency_key' => $db->uuid(), 'client' => $viewClient, 'attempt' => $attempt, 'campaign' => $campaign, 'attempt_active' => $attemptActive ? 1 : 0, 'agent_only_failure' => $agentOnlyFailure ? 1 : 0, 'outcome_required' => $outcomeRequired ? 1 : 0, 'outcomes' => $outcomes, 'claim_url' => $base . '&action=workspace', 'history_url' => $base . '&action=called_clients', 'start_call_url' => $base . '&action=api_start_call&rawmode=yes', 'save_outcome_url' => $base . '&action=api_save_outcome&rawmode=yes', 'status_url' => $base . '&action=api_attempt_status&rawmode=yes', 'call_idempotency_key' => $db->uuid(), 'outcome_idempotency_key' => $db->uuid(), 'claim_idempotency_key' => $db->uuid(), 'can_call' => $campaign['status'] === 'ACTIVE' && $selectedSeat && !$attemptActive && !$outcomeRequired ? 1 : 0, 'can_disposition' => $outcomeRequired ? 1 : 0));
        return gc_render($smarty, 'agent_workspace.tpl');
    }

    if ($action === 'callbacks') {
        $agentId = null;
        if (!$auth->isSupervisor($username)) { $agentId = $auth->agentMap(false)['id']; }
        $rows = $admin->callbacks($agentId);
        foreach ($rows as &$row) { $row['scheduled_at'] = gc_utc_to_local($row['due_at_utc'], $row['timezone']); $row['agent_name'] = $row['agent_number']; $row['can_open'] = $agentId !== null; $row['reopen_idempotency_key'] = $db->uuid(); $row['manage_idempotency_key'] = $db->uuid(); }
        gc_assign($smarty, array('callbacks' => $rows, 'csrf_token'=>$csrf, 'reopen_url'=>$base.'&action=reopen_client', 'manage_url'=>$base.'&action=callback_manage'));
        return gc_render($smarty, 'callbacks.tpl');
    }

    $auth->requireSupervisor();

    if ($action === 'seat_admin') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $extension = gc_param('sip_extension', '');
            $label = gc_param('label', '');
            if (!preg_match('/^[0-9]{1,20}$/', $extension)) throw new RuntimeException('SEAT_INVALID');
            if ($label === '' || strlen($label) > 80) throw new RuntimeException('SEAT_LABEL_INVALID');
            $active = gc_param('active', '') === '1' ? 1 : 0;
            gc_once($db, $username, 'seat_admin', gc_param('idempotency_key', ''), function () use ($db, $extension, $label, $active) {
                $db->execute('INSERT INTO gc_sip_seat (sip_extension, label, active, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE label=VALUES(label), active=VALUES(active), updated_at=UTC_TIMESTAMP()', array($extension, $label, $active));
                return array('sip_extension'=>$extension, 'active'=>$active);
            });
        }
        gc_assign($smarty, array('seats'=>$db->fetchAll('SELECT * FROM gc_sip_seat ORDER BY (sip_extension+0), sip_extension', array()), 'csrf_token'=>$csrf, 'idempotency_key'=>$db->uuid(), 'action_url'=>$base.'&action=seat_admin'));
        return gc_render($smarty, 'seat_admin.tpl');
    }

    if ($action === 'campaign_create' || $action === 'campaign_edit') {
        $campaign = array('timezone' => $config['timezone'], 'outbound_context' => $config['outbound_context'], 'status' => 'DRAFT', 'dialing_mode' => 'MANUAL');
        $id = (int)gc_param('id', 0);
        if ($id) { $campaign = $db->fetchOne('SELECT * FROM gc_campaign WHERE id=?', array($id)); if (!$campaign) throw new RuntimeException('CAMPAIGN_NOT_FOUND'); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $id = gc_once($db, $username, 'campaign_save', gc_param('idempotency_key',''), function () use ($admin, $username) { return $admin->saveCampaign($_POST, $username); });
            return gc_redirect_or_json($base . '&action=campaign_edit&id=' . $id, 'CAMPAIGN_SAVED', 'Campaña guardada.', array('id' => $id), $requestId);
        }
        gc_assign($smarty, array('campaign' => $campaign, 'csrf_token' => $csrf, 'idempotency_key' => $db->uuid(), 'action_url' => $base . '&action=' . $action . ($id ? '&id=' . $id : ''), 'statuses' => gc_statuses()));
        return gc_render($smarty, 'campaign_form.tpl');
    }

    if ($action === 'import_upload') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $token = gc_store_upload($config);
            $mapping = array('external_id' => gc_param('external_id', ''), 'display_name' => gc_param('display_name', ''), 'phones' => array_values(array_filter(array_map('trim', explode(',', gc_param('phones', ''))))));
            $_SESSION['gc_imports'][$token['token']] = array('path' => $token['path'], 'name' => $token['name'], 'campaign_id' => (int)gc_param('campaign_id', 0), 'mapping' => $mapping);
            $import = new GestionClientesImport($db);
            $preview = $import->preview($token['path'], $mapping, 0);
            gc_assign($smarty, array('summary' => array('valid' => $preview['accepted'], 'invalid' => $preview['rejected'], 'duplicates' => $preview['duplicates']), 'rows' => gc_import_rows($preview), 'csrf_token' => $csrf, 'import_token' => $token['token'], 'idempotency_key' => $db->uuid(), 'commit_url' => $base . '&action=import_commit', 'cancel_url' => $base . '&action=import_upload'));
            return gc_render($smarty, 'import_preview.tpl');
        }
        gc_assign($smarty, array('campaigns' => $admin->campaigns('', ''), 'csrf_token' => $csrf, 'idempotency_key' => $db->uuid(), 'action_url' => $base . '&action=import_upload'));
        return gc_render($smarty, 'import_upload.tpl');
    }
    if ($action === 'import_commit') {
        $auth->validateMutation(gc_param('csrf_token', ''));
        $token = gc_param('import_token', '');
        if (empty($_SESSION['gc_imports'][$token])) { throw new RuntimeException('IMPORT_TOKEN_INVALID'); }
        $pending = $_SESSION['gc_imports'][$token];
        $importService = new GestionClientesImport($db);
        $result = gc_once($db, $username, 'import_commit', gc_param('idempotency_key',''), function () use ($importService, $pending, $username, $ip) { return $importService->commit($pending['campaign_id'], $pending['path'], $pending['name'], $pending['mapping'], $username, $ip); });
        if (is_file($pending['path'])) { unlink($pending['path']); }
        unset($_SESSION['gc_imports'][$token]);
        return gc_redirect_or_json($base . '&action=campaign_list', 'IMPORT_COMMITTED', 'Importación completada.', $result, $requestId);
    }
    if ($action === 'assignment_preview' || $action === 'assignment_commit') {
        $assignment = new GestionClientesAssignment($db);
        $preview = array();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $agentIds = isset($_POST['agent_ids']) && is_array($_POST['agent_ids']) ? $_POST['agent_ids'] : array();
            if (count($agentIds) !== 1) { throw new InvalidArgumentException('SELECT_ONE_AGENT'); }
            if (gc_param('mode', 'preview') === 'commit' || $action === 'assignment_commit') {
                $count = gc_once($db, $username, 'assignment_commit', gc_param('idempotency_key',''), function () use ($assignment, $agentIds, $username, $ip) { return $assignment->assign((int)gc_param('campaign_id', 0), (int)$agentIds[0], (int)gc_param('quantity', 1), $username, $ip); });
                return gc_redirect_or_json($base . '&action=assignment_preview', 'ASSIGNMENT_COMMITTED', $count . ' clientes asignados.', array('assigned' => $count), $requestId);
            }
            $preview = array('total' => count($assignment->preview((int)gc_param('campaign_id', 0), (int)gc_param('quantity', 1))));
        }
        $agents = $db->fetchAll('SELECT id, issabel_username AS name FROM gc_agent_map WHERE active=1 ORDER BY issabel_username', array());
        gc_assign($smarty, array('campaigns' => $admin->campaigns('', ''), 'agents' => $agents, 'preview' => $preview, 'csrf_token' => $csrf, 'idempotency_key' => $db->uuid(), 'action_url' => $base . '&action=assignment_preview'));
        return gc_render($smarty, 'assignment.tpl');
    }
    if ($action === 'assignment_manage') {
        $assignment = new GestionClientesAssignment($db);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $newAssignmentId = gc_once($db, $username, 'assignment_reassign', gc_param('idempotency_key',''), function () use ($assignment, $username, $ip) {
                return $assignment->reassign((int)gc_param('assignment_id', 0), (int)gc_param('new_agent_map_id', 0), $username, gc_param('reason', ''), $ip);
            });
            return gc_redirect_or_json($base . '&action=assignment_manage', 'CLIENT_REASSIGNED', 'Cliente reasignado.', array('assignment_id'=>$newAssignmentId), $requestId);
        }
        $campaignId = (int)gc_param('campaign_id', 0);
        $agentMapId = (int)gc_param('agent_map_id', 0);
        $query = gc_param('q', '');
        $agents = $db->fetchAll('SELECT id,issabel_username AS name FROM gc_agent_map WHERE active=1 ORDER BY issabel_username,id', array());
        $rows = $assignment->activeAssignments($campaignId, $agentMapId, $query);
        foreach ($rows as &$row) {
            $row['can_reassign'] = $row['campaign_status'] !== 'CLOSED' && in_array($row['state'], array('PENDING','NO_CONTACT','CALLBACK'), true) && !(int)$row['has_claim'] && !(int)$row['has_unresolved_attempt'];
            $row['callback_at'] = $row['callback_due_at'] ? gc_utc_to_local($row['callback_due_at'], $row['callback_timezone']) : null;
            $row['idempotency_key'] = $db->uuid();
        }
        unset($row);
        gc_assign($smarty, array('assignments'=>$rows, 'campaigns'=>$admin->campaigns('', ''), 'agents'=>$agents, 'filters'=>array('campaign_id'=>$campaignId,'agent_map_id'=>$agentMapId,'q'=>$query), 'csrf_token'=>$csrf, 'filter_url'=>$base, 'action_url'=>$base.'&action=assignment_manage'));
        return gc_render($smarty, 'assignment_manage.tpl');
    }
    if ($action === 'agent_mapping') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateMutation(gc_param('csrf_token', ''));
            $issabelUsername = gc_param('issabel_username', '');
            $active = gc_param('active', '') === '1';
            $agentMapId = gc_once($db, $username, 'agent_access', gc_param('idempotency_key',''), function () use ($admin, $issabelUsername, $active) { return $admin->setAgentAccess($issabelUsername, $active); });
            return gc_redirect_or_json($base . '&action=agent_mapping', $active ? 'AGENT_ACCESS_ENABLED' : 'AGENT_ACCESS_DISABLED', $active ? 'Acceso del agente activado.' : 'Acceso del agente desactivado.', array('id'=>$agentMapId, 'active'=>$active), $requestId);
        }
        $accessUsers = $admin->agentAccessList();
        foreach ($accessUsers as &$accessUser) { $accessUser['idempotency_key'] = $db->uuid(); }
        unset($accessUser);
        gc_assign($smarty, array('users' => $accessUsers, 'csrf_token' => $csrf, 'action_url' => $base . '&action=agent_mapping'));
        return gc_render($smarty, 'agent_mapping.tpl');
    }
    if ($action === 'outcome_catalog') {
        gc_assign($smarty, array('outcomes' => $db->fetchAll('SELECT * FROM gc_outcome ORDER BY campaign_id, display_order, id', array())));
        return gc_render($smarty, 'outcome_catalog.tpl');
    }
    if ($action === 'dashboard' || $action === 'export_csv') {
        $campaignId = (int)gc_param('campaign_id', 0);
        if (!$campaignId) { $first = $db->fetchOne('SELECT id FROM gc_campaign ORDER BY id LIMIT 1', array()); $campaignId = $first ? $first['id'] : 0; }
        $reportCampaign = $campaignId ? $db->fetchOne('SELECT timezone FROM gc_campaign WHERE id=?', array($campaignId)) : null;
        $reportTimezone = $reportCampaign && $reportCampaign['timezone'] ? $reportCampaign['timezone'] : $config['timezone'];
        $localToday = new DateTime('now', new DateTimeZone($reportTimezone));
        $fromDate = gc_param('from', $localToday->format('Y-m-01'));
        $localToday->modify('+1 day');
        $toDate = gc_param('to', $localToday->format('Y-m-d'));
        $from = gc_local_date_to_utc($fromDate, $reportTimezone);
        $to = gc_local_date_to_utc($toDate, $reportTimezone);
        $stats = new GestionClientesStats($db);
        $summary = $stats->campaignSummary($campaignId, $from, $to);
        if ($action === 'export_csv') { return gc_csv_export($summary, $stats->attemptExport($campaignId, $from, $to, 50000)); }
        $metrics = array(); foreach ($summary as $key => $value) { $metrics[] = array('label' => ucfirst(str_replace('_', ' ', $key)), 'value' => (int)$value, 'scope' => in_array($key, array('total_calls','answered','not_answered','talk_seconds','rejected'), true) ? 'Período' : 'Actual'); }
        gc_assign($smarty, array('campaign_id' => $campaignId, 'metrics' => $metrics, 'filters' => array('from' => $fromDate, 'to' => $toDate), 'timezone' => $reportTimezone, 'export_url' => $base . '&action=export_csv&campaign_id=' . $campaignId . '&from=' . urlencode($fromDate) . '&to=' . urlencode($toDate), 'tables' => array(array('title' => 'Actividad por agente', 'columns' => array(array('key'=>'agent','label'=>'Agente'),array('key'=>'clients_called','label'=>'Clientes'),array('key'=>'total_calls','label'=>'Llamadas'),array('key'=>'answered','label'=>'Contestadas'),array('key'=>'not_answered','label'=>'No contestadas'),array('key'=>'failed','label'=>'Fallidas'),array('key'=>'outcomes_saved','label'=>'Resultados'),array('key'=>'talk_seconds','label'=>'Segundos hablados')), 'rows' => $stats->agentPerformance($campaignId,$from,$to)), array('title'=>'Resultados comerciales','columns'=>array(array('key'=>'label','label'=>'Resultado'),array('key'=>'total','label'=>'Total')), 'rows'=>$stats->outcomeBreakdown($campaignId,$from,$to)), array('title'=>'Resultados técnicos','columns'=>array(array('key'=>'result','label'=>'Estado'),array('key'=>'total','label'=>'Total')), 'rows'=>$stats->technicalBreakdown($campaignId,$from,$to)))));
        return gc_render($smarty, 'dashboard.tpl');
    }
    if ($action === 'audit_view') {
        $events = $admin->audit(gc_param('user',''), gc_param('event',''));
        foreach ($events as &$event) { $event['actor']=$event['actor_username']; $event['event']=$event['event_type']; $event['entity']='client'; $event['entity_id']=$event['client_id']; $event['request_id']=''; }
        gc_assign($smarty, array('events'=>$events, 'filters'=>array('user'=>gc_param('user',''),'event'=>gc_param('event',''))));
        return gc_render($smarty, 'audit.tpl');
    }

    $campaigns = $admin->campaigns(gc_param('q', ''), gc_param('status', ''));
    foreach ($campaigns as &$campaign) { $campaign['total']=$campaign['client_count']; $campaign['managed']=$db->fetchOne('SELECT COUNT(*) AS n FROM gc_client WHERE campaign_id=? AND terminal=1', array($campaign['id']))['n']; $campaign['progress']=$campaign['total'] ? round(100*$campaign['managed']/$campaign['total'],1).'%' : '0%'; $campaign['edit_url']=$base.'&action=campaign_edit&id='.$campaign['id']; $campaign['workspace_url']=$base.'&action=dashboard&campaign_id='.$campaign['id']; }
    gc_assign($smarty, array('campaigns'=>$campaigns, 'filters'=>array('q'=>gc_param('q',''),'status'=>gc_param('status','')), 'statuses'=>gc_statuses(), 'create_url'=>$base.'&action=campaign_create', 'import_url'=>$base.'&action=import_upload', 'assignment_url'=>$base.'&action=assignment_preview', 'reassignment_url'=>$base.'&action=assignment_manage', 'mapping_url'=>$base.'&action=agent_mapping', 'seats_url'=>$base.'&action=seat_admin', 'callbacks_url'=>$base.'&action=callbacks', 'audit_url'=>$base.'&action=audit_view'));
    return gc_render($smarty, 'campaign_list.tpl');
}

function gc_param($name, $default) { return isset($_REQUEST[$name]) && !is_array($_REQUEST[$name]) ? trim((string)$_REQUEST[$name]) : $default; }
function gc_wants_json() { return gc_param('rawmode','') === 'yes' || strpos(gc_param('action',''), 'api_') === 0; }
function gc_request_id() { $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(16) : md5(uniqid('', true), true); return bin2hex($bytes); }
function gc_response($ok,$code,$message,$data,$requestId) { return array('ok'=>(bool)$ok,'code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>$requestId); }
function gc_message($code) { $messages=array('AUTH_REQUIRED'=>'Debe iniciar sesión en Issabel.','FORBIDDEN'=>'No tiene permiso para esta acción.','CSRF_INVALID'=>'La sesión expiró. Recargue la página.','AGENT_MAPPING_REQUIRED'=>'Su usuario no está habilitado para Gestión de Clientes.','INVALID_ISSABEL_USER'=>'El usuario Issabel no es válido.','ISSABEL_USER_NOT_FOUND'=>'El usuario ya no existe en Issabel.','ISSABEL_USER_SOURCE_UNAVAILABLE'=>'No se pudo leer la lista de usuarios de Issabel.','AMBIGUOUS_AGENT_MAPPING'=>'El usuario tiene más de un registro de acceso activo.','AGENT_HAS_ACTIVE_CALL'=>'No puede desactivar al agente mientras tiene una llamada activa.','SEAT_SELECTION_REQUIRED'=>'Seleccione la extensión de este puesto antes de llamar.','SEAT_INVALID'=>'La extensión seleccionada no es válida.','SEAT_LABEL_INVALID'=>'Ingrese un nombre de puesto válido.','SEAT_NOT_ALLOWED'=>'La extensión no está habilitada para Gestión de Clientes.','SEAT_IN_USE'=>'La extensión ya está siendo usada por otro agente.','SEAT_HAS_ACTIVE_CALL'=>'No puede cambiar de extensión durante una llamada activa.','ACTIVE_ATTEMPT_EXISTS'=>'Ya existe una llamada activa. Espere a que finalice.','OUTCOME_REQUIRED_BEFORE_CALL'=>'Guarde el resultado de la llamada anterior antes de iniciar otra.','OUTCOME_NOT_APPLICABLE'=>'La llamada no llegó al cliente y no requiere resultado.','CAMPAIGN_NOT_ACTIVE'=>'La campaña está pausada o cerrada. No se pueden iniciar llamadas.','CAMPAIGN_NOT_ASSIGNABLE'=>'La campaña cerrada no admite nuevas asignaciones.','CURRENT_CLIENT_EXISTS'=>'Finalice el cliente actual antes de abrir otro.','CLIENT_ASSIGNED_TO_OTHER_AGENT'=>'Este cliente está asignado actualmente a otro agente.','CLIENT_ALREADY_CLAIMED'=>'Este cliente está abierto actualmente por otro agente.','CLIENT_NOT_OWNED'=>'Este cliente ya no pertenece a su cartera.','CLIENT_HISTORY_REQUIRED'=>'Solo puede reabrir clientes que haya llamado.','REASSIGNMENT_INVALID'=>'La reasignación no es válida.','REASSIGNMENT_REASON_INVALID'=>'Ingrese un motivo de reasignación.','REASSIGNMENT_NOT_ELIGIBLE'=>'El cliente no se puede reasignar en su estado actual.','REASSIGNMENT_SAME_AGENT'=>'Seleccione un agente diferente.','REASSIGNMENT_CLIENT_ACTIVE'=>'No puede reasignar un cliente abierto, en llamada o con resultado pendiente.','CALLBACK_ACTION_INVALID'=>'La acción del callback no es válida.','CALLBACK_NOT_OPEN'=>'El callback ya no está pendiente.','CALLBACK_OWNERSHIP_INVALID'=>'El callback pertenece a otro agente.','CALLBACK_CLIENT_ACTIVE'=>'No puede modificar el callback mientras el cliente está abierto o en llamada.','CALLBACK_DATE_INVALID'=>'Ingrese una fecha y hora válida.','CALLBACK_MUST_BE_FUTURE'=>'El callback debe programarse en el futuro.','CALLBACK_NOTE_INVALID'=>'Ingrese una nota válida para el callback.','AMI_RESULT_UNKNOWN'=>'Asterisk recibió la solicitud, pero su estado aún no está confirmado. No vuelva a llamar hasta que se resuelva.','AMI_ORIGINATE_FAILED'=>'Asterisk rechazó la llamada.','INTERNAL_ERROR'=>'Ocurrió un error interno.'); return isset($messages[$code])?$messages[$code]:str_replace('_',' ',strtolower($code)); }
function gc_assign(&$smarty,$values) { foreach($values as $key=>$value){ $smarty->assign($key,$value); } }
function gc_render(&$smarty,$template) { $assets='<link rel="stylesheet" href="modules/gestion_clientes/themes/default/css/gestion_clientes.css" /><script src="modules/gestion_clientes/themes/default/js/gestion_clientes.js"></script>'; return $assets.$smarty->fetch(dirname(__FILE__).'/themes/default/'.$template); }
function gc_statuses() { return array(array('value'=>'DRAFT','label'=>'Borrador'),array('value'=>'ACTIVE','label'=>'Activa'),array('value'=>'PAUSED','label'=>'Pausada'),array('value'=>'CLOSED','label'=>'Cerrada')); }
function gc_redirect_or_json($url,$code,$message,$data,$requestId) { if(gc_wants_json()) return array('_json'=>gc_response(true,$code,$message,$data,$requestId)); if(!headers_sent()) header('Location: '.$url); return '<meta http-equiv="refresh" content="0;url='.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">'; }
function gc_client_view($client,$timezone) { $phones=array(); $fields=$client['custom_data']; foreach($client['phones'] as $p){$lastCall=$p['last_call_at']?gc_utc_to_local($p['last_call_at'],$timezone):null;$phones[]=array('id'=>$p['id'],'label'=>$p['phone_type'],'number'=>$p['original_value'],'masked'=>$p['original_value'],'state'=>$p['state'],'attempt_count'=>(int)$p['attempt_count'],'last_call_at'=>$lastCall,'last_technical_state'=>$p['last_technical_state'],'last_business_outcome_id'=>$p['last_business_outcome_id'],'last_business_outcome_code'=>$p['last_business_outcome_code'],'last_business_outcome_label'=>$p['last_business_outcome_label'],'last_attempt'=>$p['last_attempt'],'usable'=>!in_array($p['state'],array('INVALID','DO_NOT_CALL'),true));if($p['phone_type']!==null&&array_key_exists($p['phone_type'],$fields))unset($fields[$p['phone_type']]);} return array('id'=>$client['id'],'external_id'=>$client['external_key'],'name'=>$client['display_name'],'state'=>$client['state'],'fields'=>$fields,'phones'=>$phones,'claim_token'=>$client['claim_token']); }
function gc_store_upload($config) { if(empty($_FILES['csv_file']) || $_FILES['csv_file']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('UPLOAD_FAILED'); if($_FILES['csv_file']['size']>$config['max_upload_bytes']) throw new RuntimeException('UPLOAD_TOO_LARGE'); $name=basename($_FILES['csv_file']['name']); if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv') throw new RuntimeException('UPLOAD_TYPE_INVALID'); if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);$mime=finfo_file($f,$_FILES['csv_file']['tmp_name']);finfo_close($f);if(!in_array($mime,array('text/plain','text/csv','application/csv','application/vnd.ms-excel','application/octet-stream'),true))throw new RuntimeException('UPLOAD_TYPE_INVALID');} $dir=$config['upload_dir']; if(!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('UPLOAD_DIR_FAILED'); $bytes=function_exists('openssl_random_pseudo_bytes')?openssl_random_pseudo_bytes(16):md5(uniqid('',true),true); $token=bin2hex($bytes); $path=$dir.'/'.$token.'.csv'; if(!move_uploaded_file($_FILES['csv_file']['tmp_name'],$path)) throw new RuntimeException('UPLOAD_FAILED'); chmod($path,0600); return array('token'=>$token,'path'=>$path,'name'=>$name); }
function gc_import_rows($preview) { $rows=array(); $n=0; foreach($preview['sample'] as $c){$n++;$numbers=array();foreach($c['phones'] as $p)$numbers[]=$p['original'];$rows[]=array('number'=>$n,'external_id'=>$c['external_key'],'name'=>$c['display_name'],'phones'=>implode(', ',$numbers),'valid'=>1,'message'=>'Válido');} foreach($preview['errors'] as $e){$rows[]=array('number'=>$e['row'],'external_id'=>'','name'=>'','phones'=>'','valid'=>0,'message'=>$e['message']);} return $rows; }
function gc_csv_cell($value) { $value=(string)$value;if(preg_match('/^[\x00-\x20]*[=+\-@]/',$value))$value="'".$value;return '"'.str_replace('"','""',$value).'"'; }
function gc_csv_export($summary,$attempts=array()) { if(!headers_sent()){header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="gestion-clientes.csv"');} $out="\xEF\xBB\xBFsection,metric,value\r\n";foreach($summary as $k=>$v)$out.="summary,".gc_csv_cell($k).','.(int)$v."\r\n";$out.="\r\nattempt_id,requested_at,answered_at,ended_at,external_id,client,phone,agent,extension,technical_state,outcome,duration_seconds,talk_seconds,note,recording\r\n";foreach($attempts as $row){$values=array($row['attempt_id'],$row['requested_at'],$row['answered_at'],$row['ended_at'],$row['external_key'],$row['display_name'],$row['phone'],$row['agent'],$row['agent_sip_extension'],$row['technical_state'],$row['outcome'],$row['duration_seconds'],$row['talk_seconds'],$row['agent_note'],$row['recording_path']);$escaped=array();foreach($values as $value)$escaped[]=gc_csv_cell($value);$out.=implode(',',$escaped)."\r\n";}return $out; }
function gc_utc_to_local($value,$timezone) { try{$date=new DateTime($value,new DateTimeZone('UTC'));$date->setTimezone(new DateTimeZone($timezone));return $date->format('Y-m-d H:i');}catch(Exception $e){return $value;} }
function gc_local_date_to_utc($value,$timezone) { if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))throw new InvalidArgumentException('INVALID_DATE'); try{$date=DateTime::createFromFormat('!Y-m-d',$value,new DateTimeZone($timezone));if(!$date || $date->format('Y-m-d')!==$value)throw new Exception('invalid');$date->setTimezone(new DateTimeZone('UTC'));return $date->format('Y-m-d H:i:s');}catch(Exception $e){throw new InvalidArgumentException('INVALID_DATE');} }
function gc_once($db,$actor,$action,$key,$callback) { $cached=$db->idempotentResponse($actor,$action,$key); if($cached!==null)return $cached; try{$db->reserveIdempotency($actor,$action,$key);}catch(Exception $e){$cached=$db->idempotentResponse($actor,$action,$key);if($cached!==null)return $cached;throw new RuntimeException('REQUEST_IN_PROGRESS');} try{$result=call_user_func($callback);$db->completeIdempotency($actor,$action,$key,$result);return $result;}catch(Exception $e){$db->execute('DELETE FROM gc_idempotency WHERE actor_username=? AND action_name=? AND idempotency_key=? AND response_json IS NULL',array($actor,$action,$key));throw $e;} }
