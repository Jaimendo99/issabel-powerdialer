<?php

class GestionClientesAdmin
{
    private $db;
    private $config;

    public function __construct($db, $config = array())
    {
        $this->db = $db;
        $this->config = is_array($config) ? $config : array();
    }

    public function campaigns($query, $status)
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM gc_client cl WHERE cl.campaign_id=c.id) AS client_count FROM gc_campaign c WHERE 1=1';
        $params = array();
        if ($query !== '') {
            $sql .= ' AND c.name LIKE ?';
            $params[] = '%' . $query . '%';
        }
        if (in_array($status, array('DRAFT','ACTIVE','PAUSED','CLOSED'), true)) {
            $sql .= ' AND c.status=?';
            $params[] = $status;
        }
        return $this->db->fetchAll($sql . ' ORDER BY c.updated_at DESC, c.id DESC', $params);
    }

    public function saveCampaign($values, $actor)
    {
        $errors = GestionClientesValidator::validateCampaign($values);
        if (count($errors)) {
            throw new InvalidArgumentException(implode(',', $errors));
        }
        $status = isset($values['status']) && in_array($values['status'], array('DRAFT','ACTIVE','PAUSED','CLOSED'), true) ? $values['status'] : 'DRAFT';
        $mode = isset($values['dialing_mode']) && $values['dialing_mode'] === 'AUTO' ? 'AUTO' : 'MANUAL';
        $id = isset($values['id']) ? (int)$values['id'] : 0;
        if ($id > 0) {
            $this->db->execute('UPDATE gc_campaign SET name=?, description=?, status=?, timezone=?, outbound_context=?, dialing_mode=?, updated_at=UTC_TIMESTAMP() WHERE id=?', array(trim($values['name']), trim(isset($values['description']) ? $values['description'] : ''), $status, $values['timezone'], $values['outbound_context'], $mode, $id));
            return $id;
        }
        $this->db->execute('INSERT INTO gc_campaign (name, description, status, timezone, outbound_context, dialing_mode, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', array(trim($values['name']), trim(isset($values['description']) ? $values['description'] : ''), $status, $values['timezone'], $values['outbound_context'], $mode, $actor));
        return (int)$this->db->pdo()->lastInsertId();
    }

    /** Return real Issabel users merged with their durable local access record. */
    public function agentAccessList()
    {
        $users = $this->readIssabelUsers();
        $maps = $this->db->fetchAll('SELECT am.id,am.issabel_user_id,am.issabel_username,am.agent_number,am.active,am.verified_at,(SELECT ws.sip_extension FROM gc_work_session ws WHERE ws.agent_map_id=am.id AND ws.released_at IS NULL AND ws.expires_at>UTC_TIMESTAMP() ORDER BY ws.last_seen_at DESC,ws.id DESC LIMIT 1) AS current_extension FROM gc_agent_map am ORDER BY am.id', array());
        $byId = array();
        $byName = array();
        foreach ($maps as $map) {
            if ($map['issabel_user_id'] !== null && $map['issabel_user_id'] !== '') $byId[(string)$map['issabel_user_id']] = $map;
            $byName[$map['issabel_username']] = $map;
        }
        foreach ($users as &$user) {
            $map = isset($byId[(string)$user['user_id']]) ? $byId[(string)$user['user_id']] : (isset($byName[$user['username']]) ? $byName[$user['username']] : null);
            $user['agent_map_id'] = $map ? (int)$map['id'] : null;
            $user['active'] = $map ? (int)$map['active'] : 0;
            $user['verified_at'] = $map ? $map['verified_at'] : null;
            $user['current_extension'] = $map ? $map['current_extension'] : null;
        }
        unset($user);
        return $users;
    }

    /** Enable/disable access without replacing agent_map_id or historical data. */
    public function setAgentAccess($issabelUsername, $active)
    {
        $issabelUsername = trim((string)$issabelUsername);
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $issabelUsername)) throw new InvalidArgumentException('INVALID_ISSABEL_USER');
        $selected = null;
        foreach ($this->readIssabelUsers() as $user) {
            if ($user['username'] === $issabelUsername) { $selected = $user; break; }
        }
        if (!$selected) throw new RuntimeException('ISSABEL_USER_NOT_FOUND');
        $enabled = $active ? 1 : 0;
        $db = $this->db;
        return $db->transaction(function ($tx) use ($selected, $enabled) {
            $map = $tx->fetchOne('SELECT * FROM gc_agent_map WHERE issabel_user_id=? FOR UPDATE', array($selected['user_id']));
            if (!$map) $map = $tx->fetchOne('SELECT * FROM gc_agent_map WHERE issabel_user_id IS NULL AND issabel_username=? ORDER BY id LIMIT 1 FOR UPDATE', array($selected['username']));
            if ($map) {
                $duplicate = $tx->fetchOne('SELECT id FROM gc_agent_map WHERE id<>? AND (issabel_user_id=? OR (issabel_username=? AND active=1)) LIMIT 1 FOR UPDATE', array($map['id'], $selected['user_id'], $selected['username']));
                if ($duplicate) throw new RuntimeException('AMBIGUOUS_AGENT_MAPPING');
                if (!$enabled) {
                    $activeCall = $tx->fetchOne('SELECT id FROM gc_attempt WHERE agent_map_id=? AND ended_at IS NULL AND technical_state IN (\'CREATED\',\'ORIGINATED\',\'RINGING\',\'ANSWERED\',\'AMBIGUOUS\') LIMIT 1 FOR UPDATE', array($map['id']));
                    if ($activeCall) throw new RuntimeException('AGENT_HAS_ACTIVE_CALL');
                }
                $tx->execute('UPDATE gc_agent_map SET issabel_user_id=?,issabel_username=?,agent_number=CASE WHEN agent_number=\'\' THEN ? ELSE agent_number END,active=?,verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?', array($selected['user_id'], $selected['username'], $selected['username'], $enabled, $map['id']));
                if (!$enabled) $tx->execute('UPDATE gc_work_session SET active_extension=NULL,released_at=UTC_TIMESTAMP() WHERE agent_map_id=? AND released_at IS NULL', array($map['id']));
                return (int)$map['id'];
            }
            if (!$enabled) return 0;
            $tx->execute('INSERT INTO gc_agent_map (issabel_user_id,issabel_username,callcenter_agent_id,agent_number,sip_extension,active,verified_at,created_at,updated_at) VALUES (?,?,NULL,?,NULL,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())', array($selected['user_id'], $selected['username'], $selected['username']));
            return (int)$tx->pdo()->lastInsertId();
        });
    }

    private function readIssabelUsers()
    {
        $path = isset($this->config['issabel_user_db_path']) ? $this->config['issabel_user_db_path'] : '/var/www/db/acl.db';
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) throw new RuntimeException('ISSABEL_USER_SOURCE_UNAVAILABLE');
        $table = $this->safeIdentifier(isset($this->config['issabel_user_table']) ? $this->config['issabel_user_table'] : 'acl_user');
        $idColumn = $this->safeIdentifier(isset($this->config['issabel_user_id_column']) ? $this->config['issabel_user_id_column'] : 'id');
        $nameColumn = $this->safeIdentifier(isset($this->config['issabel_username_column']) ? $this->config['issabel_username_column'] : 'name');
        $labelColumn = $this->safeIdentifier(isset($this->config['issabel_user_label_column']) ? $this->config['issabel_user_label_column'] : 'description');
        if (!class_exists('SQLite3') || !defined('SQLITE3_OPEN_READONLY')) throw new RuntimeException('ISSABEL_USER_SOURCE_UNAVAILABLE');
        try {
            $sqlite = new SQLite3($realPath, SQLITE3_OPEN_READONLY);
            $sql = 'SELECT "' . $idColumn . '" AS user_id,"' . $nameColumn . '" AS username,"' . $labelColumn . '" AS display_name FROM "' . $table . '" ORDER BY "' . $nameColumn . '"';
            $result = $sqlite->query($sql);
            if (!$result) throw new RuntimeException('ISSABEL_USER_SOURCE_UNAVAILABLE');
            $rows = array();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
            $sqlite->close();
        } catch (Exception $e) {
            throw new RuntimeException('ISSABEL_USER_SOURCE_UNAVAILABLE');
        }
        $users = array();
        foreach ($rows as $row) {
            $id = trim((string)$row['user_id']);
            $name = trim((string)$row['username']);
            if (!preg_match('/^[A-Za-z0-9_.:@-]{1,80}$/', $id) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $name)) continue;
            $users[] = array('user_id'=>$id,'username'=>$name,'display_name'=>trim((string)$row['display_name']));
        }
        return $users;
    }

    private function safeIdentifier($value)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string)$value)) throw new RuntimeException('ISSABEL_USER_SOURCE_INVALID');
        return (string)$value;
    }

    public function callbacks($agentMapId)
    {
        $sql = 'SELECT cb.*, c.display_name AS client_name, am.agent_number, CASE WHEN cb.due_at_utc<UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue FROM gc_callback cb JOIN gc_client c ON c.id=cb.client_id JOIN gc_assignment a ON a.id=cb.assignment_id JOIN gc_agent_map am ON am.id=a.agent_map_id WHERE cb.status=\'OPEN\'';
        $params = array();
        if ($agentMapId !== null) {
            $sql .= ' AND a.agent_map_id=?';
            $params[] = $agentMapId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY cb.due_at_utc ASC LIMIT 500', $params);
    }

    public function audit($user, $event)
    {
        $sql = 'SELECT * FROM gc_client_event WHERE 1=1';
        $params = array();
        if ($user !== '') { $sql .= ' AND actor_username=?'; $params[] = $user; }
        if ($event !== '') { $sql .= ' AND event_type=?'; $params[] = $event; }
        return $this->db->fetchAll($sql . ' ORDER BY created_at DESC, id DESC LIMIT 500', $params);
    }
}
