<?php

/** Asterisk explicitly rejected Originate; no call was accepted. */
class GestionClientesAmiRejectedException extends RuntimeException {}

/** Originate bytes were sent but no authoritative response was received. */
class GestionClientesAmiUnknownException extends RuntimeException {}

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
            'agent_technology' => 'SIP', 'originate_result_timeout' => 35
        );
        $config = is_array($config) ? $config : array();
        $aliases = array('ami_host'=>'host', 'ami_port'=>'port', 'ami_username'=>'username',
            'ami_timeout_seconds'=>'read_timeout', 'sip_technology'=>'agent_technology',
            'dial_context'=>'context', 'ami_secret_file'=>'secret_file',
            'ami_originate_timeout_seconds'=>'originate_result_timeout');
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
            'Events' => 'call'));
        if (!$this->isSuccess($reply)) {
            $this->close();
            throw new RuntimeException('AMI authentication failed');
        }
        $this->loggedIn = true;
        return true;
    }

    public function originate($agentExtension, $phone, $correlationToken, $accountCode, $context = null)
    {
        if (!preg_match('/^[0-9]{1,20}$/', (string)$agentExtension))
            throw new InvalidArgumentException('Invalid agent extension');
        if (!preg_match('/^[0-9]{1,32}$/', (string)$phone))
            throw new InvalidArgumentException('Invalid destination number');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string)$correlationToken))
            throw new InvalidArgumentException('Invalid correlation token');
        if (!preg_match('/^GC-[0-9a-f]{17}$/i', (string)$accountCode))
            throw new InvalidArgumentException('Invalid CDR account code');
        $dialContext = $context === null ? $this->config['context'] : $context;
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string)$dialContext))
            throw new InvalidArgumentException('Invalid dialplan context');
        $this->connect();
        $actionId = 'gc-' . str_replace('-', '', $correlationToken);
        try {
            $reply = $this->originateAction(array(
                'Action' => 'Originate', 'ActionID' => $actionId,
                'Channel' => $this->config['agent_technology'] . '/' . $agentExtension,
                'Context' => $dialContext, 'Exten' => $phone, 'Priority' => '1',
                'Timeout' => '30000', 'CallerID' => 'Gestion Clientes <' . $agentExtension . '>',
                'Account' => $accountCode,
                'Variable' => '__GC_ATTEMPT_ID=' . $correlationToken,
                'Async' => 'true'
            ), $actionId);
        } catch (GestionClientesAmiUnknownException $e) {
            throw $e;
        } catch (Exception $e) {
            // connect/authentication and a zero-byte write happen before Asterisk
            // can accept Originate, and are therefore safe to classify as rejected.
            throw new GestionClientesAmiRejectedException('Originate was not sent');
        }
        return $reply;
    }

    /** Wait for the correlated async OriginateResponse, ignoring other AMI events. */
    private function originateAction($fields, $actionId)
    {
        $payload = $this->buildPayload($fields);
        $this->writePayload($payload, true);
        $timeout = max(5, min(45, (int)$this->config['originate_result_timeout']));
        $deadline = microtime(true) + $timeout;
        $queued = false;
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            try {
                $message = $this->readMessage($remaining);
            } catch (Exception $e) {
                throw new GestionClientesAmiUnknownException('AMI Originate response unavailable');
            }
            if (!$message) continue;
            $messageActionId = isset($message['actionid']) ? $message['actionid'] : '';
            if (isset($message['event']) && strcasecmp($message['event'], 'OriginateResponse') === 0) {
                if ($messageActionId !== $actionId) continue;
                $reason = isset($message['reason']) ? (int)$message['reason'] : -1;
                $success = isset($message['response']) && strcasecmp($message['response'], 'Success') === 0;
                return array(
                    'accepted' => true,
                    'action_id' => $actionId,
                    'agent_state' => $success ? 'ANSWERED' : $this->stateForOriginateReason($reason),
                    'reason' => $reason,
                    'uniqueid' => isset($message['uniqueid']) && $message['uniqueid'] !== '<null>' ? $message['uniqueid'] : null,
                    'raw_error_code' => $success ? null : $this->errorForOriginateReason($reason)
                );
            }
            if (isset($message['response']) && ($messageActionId === '' || $messageActionId === $actionId)) {
                if (strcasecmp($message['response'], 'Error') === 0) {
                    $text = isset($message['message']) ? $message['message'] : 'Originate rejected';
                    $this->log('AMI originate rejected: ' . $text);
                    throw new GestionClientesAmiRejectedException('Originate rejected');
                }
                if (strcasecmp($message['response'], 'Success') === 0) $queued = true;
            }
        }
        throw new GestionClientesAmiUnknownException($queued ? 'AMI Originate result timeout' : 'AMI Originate response timeout');
    }

    private function stateForOriginateReason($reason)
    {
        if ($reason === 0 || $reason === 1) return 'NO_ANSWER';
        if ($reason === 5) return 'BUSY';
        return 'FAILED';
    }

    private function errorForOriginateReason($reason)
    {
        if ($reason === 0 || $reason === 1) return 'AMI_AGENT_NO_ANSWER';
        if ($reason === 5) return 'AMI_AGENT_BUSY';
        if ($reason === 8) return 'AMI_AGENT_CONGESTION';
        return 'AMI_AGENT_FAILURE_' . (int)$reason;
    }

    private function action($fields, $originate = false)
    {
        if (!$this->socket) throw new RuntimeException('AMI is not connected');
        $payload = $this->buildPayload($fields);
        $this->writePayload($payload, $originate);
        try {
            return $this->readMessage();
        } catch (Exception $e) {
            if ($originate) throw new GestionClientesAmiUnknownException('AMI Originate response unavailable');
            throw $e;
        }
    }

    private function buildPayload($fields)
    {
        $payload = '';
        foreach ($fields as $name => $value) {
            if (preg_match('/[\r\n]/', (string)$name) || preg_match('/[\r\n]/', (string)$value))
                throw new InvalidArgumentException('Invalid AMI field');
            $payload .= $name . ': ' . $value . "\r\n";
        }
        return $payload . "\r\n";
    }

    private function writePayload($payload, $originate)
    {
        if (!$this->socket) throw new RuntimeException('AMI is not connected');
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $count = @fwrite($this->socket, substr($payload, $written));
            if ($count === false || $count === 0) {
                if ($originate && $written > 0) throw new GestionClientesAmiUnknownException('Partial AMI Originate write');
                throw new RuntimeException('AMI write failed');
            }
            $written += $count;
        }
    }

    private function readMessage($timeout = null)
    {
        if ($timeout !== null && is_resource($this->socket)) {
            $seconds = (int)floor($timeout);
            $micros = (int)(($timeout - $seconds) * 1000000);
            if ($seconds < 0) { $seconds = 0; $micros = 1; }
            stream_set_timeout($this->socket, $seconds, $micros);
        }
        $result = array();
        while (is_resource($this->socket) && !feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if ($line === '') break;
            $colon = strpos($line, ':');
            if ($colon !== false) $result[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
        if ((!is_resource($this->socket) || feof($this->socket)) && !$result) throw new RuntimeException('AMI connection closed');
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
