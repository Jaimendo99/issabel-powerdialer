<?php

class GestionClientesAuth
{
    private $db;
    private $config;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function username()
    {
        $keys = array('issabel_user', 'elastix_user', 'username');
        foreach ($keys as $key) {
            if (isset($_SESSION[$key]) && is_string($_SESSION[$key]) && $_SESSION[$key] !== '') {
                return $_SESSION[$key];
            }
        }
        throw new RuntimeException('AUTH_REQUIRED');
    }

    public function isSupervisor($username)
    {
        $users = isset($this->config['supervisor_users']) ? $this->config['supervisor_users'] : array();
        return in_array($username, $users, true);
    }

    public function requireSupervisor()
    {
        $username = $this->username();
        if (!$this->isSupervisor($username)) {
            throw new RuntimeException('FORBIDDEN');
        }
        return $username;
    }

    public function agentMap($forUpdate)
    {
        $username = $this->username();
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $rows = $this->db->fetchAll(
            'SELECT * FROM gc_agent_map WHERE issabel_username=? AND active=1' . $suffix,
            array($username)
        );
        if (count($rows) !== 1) {
            throw new RuntimeException(count($rows) ? 'AMBIGUOUS_AGENT_MAPPING' : 'AGENT_MAPPING_REQUIRED');
        }
        return $rows[0];
    }

    public function csrfToken()
    {
        if (empty($_SESSION['gc_csrf'])) {
            $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(24) : md5(uniqid('', true), true) . md5(mt_rand(), true);
            $_SESSION['gc_csrf'] = bin2hex($bytes);
        }
        return $_SESSION['gc_csrf'];
    }

    public function validateMutation($token)
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('POST_REQUIRED');
        }
        $expected = isset($_SESSION['gc_csrf']) ? $_SESSION['gc_csrf'] : '';
        if ($expected === '' || !self::constantTimeEquals($expected, (string)$token)) {
            throw new RuntimeException('CSRF_INVALID');
        }
        $key = isset($_POST['idempotency_key']) ? $_POST['idempotency_key'] : '';
        if (!GestionClientesValidator::safeIdempotencyKey($key)) {
            throw new RuntimeException('IDEMPOTENCY_REQUIRED');
        }
    }

    private static function constantTimeEquals($a, $b)
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $result === 0;
    }
}
