<?php

/** Minimal AMI client for agent-first Gestion de Clientes originates. */
class GestionClientesDialer
{
    private $config;
    private $socket;
    private $loggedIn = false;
    private $logger;

    public function __construct($config, $logger = null)
    {
        $defaults = array(
            'host' => '127.0.0.1', 'port' => 5038, 'connect_timeout' => 3,
            'read_timeout' => 5, 'context' => 'gestion-clientes-outbound',
            'agent_technology' => 'SIP'
        );
        $config = is_array($config) ? $config : array();
        $aliases = array('ami_host'=>'host', 'ami_port'=>'port', 'ami_username'=>'username',
            'ami_timeout_seconds'=>'read_timeout', 'sip_technology'=>'agent_technology',
            'dial_context'=>'context', 'ami_secret_file'=>'secret_file');
        foreach ($aliases as $source => $target) {
            if (isset($config[$source]) && !isset($config[$target])) $config[$target] = $config[$source];
        }
        $this->config = array_merge($defaults, $config);
        $this->logger = $logger;
    }

    public function connect()
    {
        if ($this->socket) return true;
        if (empty($this->config['secret']) && !empty($this->config['secret_file'])) {
            if (!is_readable($this->config['secret_file'])) throw new RuntimeException('AMI secret file is not readable');
            $this->config['secret'] = trim(file_get_contents($this->config['secret_file']));
        }
        if (empty($this->config['username']) || empty($this->config['secret'])) {
            throw new RuntimeException('AMI credentials are not configured');
        }
        $errno = 0; $error = '';
        $this->socket = @fsockopen($this->config['host'], (int)$this->config['port'],
            $errno, $error, (float)$this->config['connect_timeout']);
        if (!$this->socket) throw new RuntimeException('AMI connection failed (' . $errno . ')');
        stream_set_timeout($this->socket, (int)$this->config['read_timeout']);
        $banner = fgets($this->socket, 4096);
        if ($banner === false || strpos($banner, 'Asterisk Call Manager/') !== 0) {
            $this->close();
            throw new RuntimeException('Invalid AMI banner');
        }
        $reply = $this->action(array('Action' => 'Login',
            'Username' => $this->config['username'], 'Secret' => $this->config['secret'],
            'Events' => 'off'));
        if (!$this->isSuccess($reply)) {
            $this->close();
            throw new RuntimeException('AMI authentication failed');
        }
        $this->loggedIn = true;
        return true;
    }

    public function originate($agentExtension, $phone, $correlationToken, $context = null)
    {
        if (!preg_match('/^[0-9]{1,20}$/', (string)$agentExtension))
            throw new InvalidArgumentException('Invalid agent extension');
        if (!preg_match('/^[0-9]{1,32}$/', (string)$phone))
            throw new InvalidArgumentException('Invalid destination number');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string)$correlationToken))
            throw new InvalidArgumentException('Invalid correlation token');
        $dialContext = $context === null ? $this->config['context'] : $context;
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string)$dialContext))
            throw new InvalidArgumentException('Invalid dialplan context');
        $this->connect();
        $actionId = 'gc-' . str_replace('-', '', $correlationToken);
        $reply = $this->action(array(
            'Action' => 'Originate', 'ActionID' => $actionId,
            'Channel' => $this->config['agent_technology'] . '/' . $agentExtension,
            'Context' => $dialContext, 'Exten' => $phone, 'Priority' => '1',
            'Timeout' => '30000', 'CallerID' => 'Gestion Clientes <' . $agentExtension . '>',
            'Variable' => '__GC_ATTEMPT_ID=' . $correlationToken,
            'Async' => 'true'
        ));
        if (!$this->isSuccess($reply)) {
            $message = isset($reply['message']) ? $reply['message'] : 'Originate rejected';
            $this->log('AMI originate rejected: ' . $message);
            throw new RuntimeException('Call could not be originated');
        }
        return array('accepted' => true, 'action_id' => $actionId);
    }

    private function action($fields)
    {
        if (!$this->socket) throw new RuntimeException('AMI is not connected');
        $payload = '';
        foreach ($fields as $name => $value) {
            if (preg_match('/[\r\n]/', (string)$name) || preg_match('/[\r\n]/', (string)$value))
                throw new InvalidArgumentException('Invalid AMI field');
            $payload .= $name . ': ' . $value . "\r\n";
        }
        if (@fwrite($this->socket, $payload . "\r\n") === false)
            throw new RuntimeException('AMI write failed');
        return $this->readMessage();
    }

    private function readMessage()
    {
        $result = array();
        while (is_resource($this->socket) && !feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if ($line === '') break;
            $colon = strpos($line, ':');
            if ($colon !== false) $result[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
        $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : array();
        if (!empty($meta['timed_out'])) throw new RuntimeException('AMI response timeout');
        return $result;
    }

    private function isSuccess($reply) { return isset($reply['response']) && strcasecmp($reply['response'], 'Success') === 0; }
    private function log($message) { if (is_callable($this->logger)) call_user_func($this->logger, $message); }

    public function close()
    {
        if (is_resource($this->socket)) {
            if ($this->loggedIn) { @fwrite($this->socket, "Action: Logoff\r\n\r\n"); }
            @fclose($this->socket);
        }
        $this->socket = null; $this->loggedIn = false;
    }

    public function __destruct() { $this->close(); }
}
