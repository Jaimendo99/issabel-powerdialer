<?php

class GestionClientesImport
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function detectDelimiter($path)
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('IMPORT_FILE_UNREADABLE');
        }
        $line = fgets($handle);
        fclose($handle);
        $scores = array(',' => 0, ';' => 0, "\t" => 0, '|' => 0);
        foreach ($scores as $delimiter => $unused) {
            $scores[$delimiter] = count(str_getcsv((string)$line, $delimiter));
        }
        arsort($scores);
        reset($scores);
        return key($scores);
    }

    public function readPreview($path, $limit)
    {
        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('IMPORT_FILE_UNREADABLE');
        }
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header || count($header) < 2) {
            fclose($handle);
            throw new RuntimeException('CSV_HEADER_INVALID');
        }
        $header = $this->convertRow($header);
        if (count(array_unique($header)) !== count($header)) {
            fclose($handle);
            throw new RuntimeException('CSV_DUPLICATE_HEADERS');
        }
        $rows = array();
        while (count($rows) < $limit && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $this->associate($header, $this->convertRow($row));
        }
        fclose($handle);
        return array('delimiter' => $delimiter, 'headers' => $header, 'rows' => $rows);
    }

    public function preview($path, $mapping, $limit)
    {
        /* Count the complete stream, but retain only bounded data for rendering. */
        $parsed = $this->parse($path, $mapping, $limit, 20, 100);
        return array(
            'delimiter' => $parsed['delimiter'],
            'total' => $parsed['total'],
            'accepted' => $parsed['accepted_count'],
            'rejected' => $parsed['error_count'],
            'duplicates' => $parsed['duplicates'],
            'sample' => $parsed['accepted'],
            'errors' => $parsed['errors']
        );
    }

    public function commit($campaignId, $path, $originalFilename, $mapping, $actor, $ip)
    {
        $parsed = $this->parse($path, $mapping, 0);
        $hash = hash_file('sha256', $path);
        $db = $this->db;
        return $db->transaction(function ($tx) use ($campaignId, $originalFilename, $mapping, $actor, $ip, $parsed, $hash) {
            $tx->execute(
                'INSERT INTO gc_import_batch (campaign_id, original_filename, file_hash, total_rows, accepted_rows, rejected_rows, duplicate_rows, field_mapping_json, imported_by, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                array($campaignId, basename($originalFilename), $hash, $parsed['total'], count($parsed['accepted']), count($parsed['errors']), $parsed['duplicates'], json_encode($mapping), $actor)
            );
            $batchId = $tx->pdo()->lastInsertId();
            $inserted = 0;
            $existingDuplicates = 0;
            foreach ($parsed['accepted'] as $client) {
                $existing = $tx->fetchOne(
                    'SELECT id FROM gc_client WHERE campaign_id=? AND external_key=?',
                    array($campaignId, $client['external_key'])
                );
                if ($existing) {
                    $existingDuplicates++;
                    continue;
                }
                $tx->execute(
                    'INSERT INTO gc_client (campaign_id, import_batch_id, external_key, display_name, state, terminal, priority, custom_data_json, created_at, updated_at) VALUES (?, ?, ?, ?, \'PENDING\', 0, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    array($campaignId, $batchId, $client['external_key'], $client['display_name'], $client['priority'], json_encode($client['custom']))
                );
                $clientId = $tx->pdo()->lastInsertId();
                $inserted++;
                foreach ($client['phones'] as $order => $phone) {
                    $tx->execute(
                        'INSERT INTO gc_client_phone (client_id, original_value, normalized_value, phone_type, sort_order, state) VALUES (?, ?, ?, ?, ?, \'AVAILABLE\')',
                        array($clientId, $phone['original'], $phone['normalized'], $phone['type'], $order + 1)
                    );
                }
                $tx->audit($clientId, $actor, 'CLIENT_IMPORTED', null, 'PENDING', array('batch_id' => $batchId), $ip);
            }
            foreach ($parsed['errors'] as $error) {
                $tx->execute(
                    'INSERT INTO gc_import_error (batch_id, row_number, field_name, raw_value, error_code, message) VALUES (?, ?, ?, ?, ?, ?)',
                    array($batchId, $error['row'], $error['field'], $error['value'], $error['code'], $error['message'])
                );
            }
            $duplicateTotal = $parsed['duplicates'] + $existingDuplicates;
            $tx->execute('UPDATE gc_import_batch SET accepted_rows=?, duplicate_rows=? WHERE id=?', array($inserted, $duplicateTotal, $batchId));
            return array('batch_id' => (int)$batchId, 'accepted' => $inserted, 'rejected' => count($parsed['errors']), 'duplicates' => $duplicateTotal);
        });
    }

    private function parse($path, $mapping, $limit, $acceptedLimit = null, $errorLimit = null)
    {
        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('IMPORT_FILE_UNREADABLE');
        }
        $header = $this->convertRow(fgetcsv($handle, 0, $delimiter));
        $accepted = array();
        $errors = array();
        $seenKeys = array();
        $duplicates = 0;
        $acceptedCount = 0;
        $errorCount = 0;
        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if ($limit > 0 && $rowNumber > $limit + 1) {
                break;
            }
            $data = $this->associate($header, $this->convertRow($row));
            $keyField = isset($mapping['external_id']) ? $mapping['external_id'] : '';
            $nameField = isset($mapping['display_name']) ? $mapping['display_name'] : '';
            $external = $keyField !== '' && isset($data[$keyField]) ? trim($data[$keyField]) : '';
            if ($external === '') {
                $external = 'AUTO-' . substr(sha1(json_encode($data)), 0, 24);
            }
            $externalHash = sha1($external);
            if (isset($seenKeys[$externalHash])) {
                $duplicates++;
                $errorCount++;
                if ($errorLimit === null || count($errors) < $errorLimit) {
                    $errors[] = $this->error($rowNumber, $keyField, $external, 'DUPLICATE_EXTERNAL_KEY', 'Identificador repetido en el archivo');
                }
                continue;
            }
            $seenKeys[$externalHash] = true;
            $phones = array();
            $phoneFields = isset($mapping['phones']) && is_array($mapping['phones']) ? $mapping['phones'] : array();
            $seenPhones = array();
            foreach ($phoneFields as $phoneField) {
                $original = isset($data[$phoneField]) ? trim($data[$phoneField]) : '';
                $normalized = GestionClientesValidator::normalizePhone($original);
                if ($normalized !== null && !isset($seenPhones[$normalized])) {
                    $seenPhones[$normalized] = true;
                    $phones[] = array('original' => $original, 'normalized' => $normalized, 'type' => $phoneField);
                }
            }
            if (!count($phones)) {
                $errorCount++;
                if ($errorLimit === null || count($errors) < $errorLimit) {
                    $errors[] = $this->error($rowNumber, implode(',', $phoneFields), '', 'NO_DIALABLE_PHONE', 'La fila no contiene un teléfono marcable');
                }
                continue;
            }
            $custom = array();
            $customFields = isset($mapping['fields']) && is_array($mapping['fields']) ? $mapping['fields'] : array_keys($data);
            foreach ($customFields as $field) {
                if (isset($data[$field])) {
                    $custom[$field] = $data[$field];
                }
            }
            $name = $nameField !== '' && isset($data[$nameField]) ? trim($data[$nameField]) : $external;
            $acceptedCount++;
            if ($acceptedLimit === null || count($accepted) < $acceptedLimit) {
                $accepted[] = array('external_key' => $external, 'display_name' => $name === '' ? $external : $name, 'priority' => 0, 'custom' => $custom, 'phones' => $phones);
            }
        }
        fclose($handle);
        return array('delimiter' => $delimiter, 'total' => $rowNumber - 1,
            'accepted' => $accepted, 'errors' => $errors, 'duplicates' => $duplicates,
            'accepted_count' => $acceptedCount, 'error_count' => $errorCount);
    }

    private function convertRow($row)
    {
        $result = array();
        foreach ((array)$row as $value) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
            if (function_exists('mb_detect_encoding') && !mb_detect_encoding($value, 'UTF-8', true)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            }
            $result[] = trim($value);
        }
        return $result;
    }

    private function associate($headers, $row)
    {
        $result = array();
        foreach ($headers as $index => $header) {
            $result[$header] = isset($row[$index]) ? $row[$index] : '';
        }
        return $result;
    }

    private function error($row, $field, $value, $code, $message)
    {
        return array('row' => $row, 'field' => $field, 'value' => $value, 'code' => $code, 'message' => $message);
    }
}
