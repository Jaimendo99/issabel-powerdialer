<?php

/** Small forked AMI server used only by transport/parser regression tests. */
class FakeAmiServer
{
    private $pid;
    private $port;

    public function __construct($eventFrames, $holdOpenSeconds)
    {
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('PCNTL_REQUIRED');
        }
        $errno = 0;
        $error = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$server) throw new RuntimeException('Fake AMI bind failed');
        $address = stream_socket_get_name($server, false);
        $this->port = (int)substr(strrchr($address, ':'), 1);
        $this->pid = pcntl_fork();
        if ($this->pid === -1) {
            fclose($server);
            throw new RuntimeException('Fake AMI fork failed');
        }
        if ($this->pid === 0) {
            $this->serve($server, $eventFrames, $holdOpenSeconds);
            exit(0);
        }
        fclose($server);
    }

    public function port() { return $this->port; }

    public function finish()
    {
        if ($this->pid) {
            pcntl_waitpid($this->pid, $status);
            $this->pid = null;
        }
    }

    private function serve($server, $eventFrames, $holdOpenSeconds)
    {
        $client = @stream_socket_accept($server, 5);
        fclose($server);
        if (!$client) return;
        fwrite($client, "Asterisk Call Manager/1.3\r\n");
        $this->readFrame($client);
        fwrite($client, "Response: Success\r\nMessage: Authentication accepted\r\n\r\n");
        $originate = $this->readFrame($client);
        $actionId = isset($originate['actionid']) ? $originate['actionid'] : '';
        fwrite($client, "Response: Success\r\nActionID: " . $actionId . "\r\nMessage: Originate successfully queued\r\n\r\n");
        foreach ($eventFrames as $frame) {
            $frame = str_replace('{{ACTION_ID}}', $actionId, $frame);
            fwrite($client, rtrim($frame, "\r\n") . "\r\n\r\n");
        }
        if ($holdOpenSeconds > 0) sleep($holdOpenSeconds);
        fclose($client);
    }

    private function readFrame($stream)
    {
        $fields = array();
        while (($line = fgets($stream, 4096)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') break;
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $fields[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        return $fields;
    }
}

