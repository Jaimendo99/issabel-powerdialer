<?php

class paloSantoGestionClientes
{
    private $pdo;

    public function __construct($pdo)
    {
        if (!($pdo instanceof PDO)) {
            throw new InvalidArgumentException('A PDO connection is required');
        }
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo()
    {
        return $this->pdo;
    }

    public function fetchOne($sql, $params)
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll($sql, $params)
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function execute($sql, $params)
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(is_array($params) ? $params : array());
        return $stmt;
    }

    public function transaction($callback)
    {
        $this->pdo->beginTransaction();
        try {
            $result = call_user_func($callback, $this);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function uuid()
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
        } else {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function audit($clientId, $actor, $type, $previous, $new, $metadata, $ip)
    {
        $this->execute(
            'INSERT INTO gc_client_event (client_id, actor_username, event_type, previous_state, new_state, metadata_json, source_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            array($clientId, $actor, $type, $previous, $new, json_encode($metadata), $ip)
        );
    }

    public function idempotentResponse($actor, $action, $key)
    {
        if ($key === '') {
            throw new InvalidArgumentException('IDEMPOTENCY_REQUIRED');
        }
        $row = $this->fetchOne(
            'SELECT response_json FROM gc_idempotency WHERE actor_username=? AND action_name=? AND idempotency_key=?',
            array($actor, $action, $key)
        );
        return $row && $row['response_json'] !== null ? json_decode($row['response_json'], true) : null;
    }

    public function reserveIdempotency($actor, $action, $key)
    {
        $this->execute(
            'INSERT INTO gc_idempotency (actor_username, action_name, idempotency_key, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            array($actor, $action, $key)
        );
    }

    public function completeIdempotency($actor, $action, $key, $response)
    {
        $this->execute(
            'UPDATE gc_idempotency SET response_json=? WHERE actor_username=? AND action_name=? AND idempotency_key=?',
            array(json_encode($response), $actor, $action, $key)
        );
    }
}
