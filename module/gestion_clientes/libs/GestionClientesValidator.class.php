<?php

class GestionClientesValidator
{
    private static $states = array(
        'PENDING', 'IN_PROGRESS', 'NO_CONTACT', 'CALLBACK', 'INTERESTED',
        'SALE', 'NOT_INTERESTED', 'INVALID', 'EXHAUSTED', 'CLOSED_OTHER'
    );

    public static function normalizePhone($value)
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $hasPlus = substr($raw, 0, 1) === '+';
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }
        if (substr($digits, 0, 3) === '593') {
            $normalized = '+' . $digits;
        } elseif (substr($digits, 0, 1) === '0' && strlen($digits) === 10) {
            $normalized = '+593' . substr($digits, 1);
        } elseif (substr($digits, 0, 1) === '0' && strlen($digits) === 9) {
            $normalized = '+593' . substr($digits, 1);
        } elseif ($hasPlus && strlen($digits) >= 8 && strlen($digits) <= 15) {
            $normalized = '+' . $digits;
        } else {
            return null;
        }
        $length = strlen(substr($normalized, 1));
        return $length >= 8 && $length <= 15 ? $normalized : null;
    }

    /** Convert stored E.164 Ecuador numbers to the national format used by Issabel routes. */
    public static function toDialString($value)
    {
        $normalized = self::normalizePhone($value);
        if ($normalized === false) return false;
        if (strpos($normalized, '+593') === 0) {
            return '0' . substr($normalized, 4);
        }
        return ltrim($normalized, '+');
    }

    public static function validateCampaign($values)
    {
        $errors = array();
        if (!isset($values['name']) || trim($values['name']) === '') {
            $errors['name'] = 'CAMPAIGN_NAME_REQUIRED';
        }
        $timezone = isset($values['timezone']) ? $values['timezone'] : '';
        if (!self::validTimezone($timezone)) {
            $errors['timezone'] = 'INVALID_TIMEZONE';
        }
        $context = isset($values['outbound_context']) ? $values['outbound_context'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $context)) {
            $errors['outbound_context'] = 'INVALID_CONTEXT';
        }
        return $errors;
    }

    public static function validateOutcome($outcome, $callback)
    {
        $errors = array();
        if (!is_array($outcome) || empty($outcome['active'])) {
            $errors[] = 'OUTCOME_INACTIVE';
            return $errors;
        }
        if (!in_array($outcome['resulting_client_state'], self::$states, true)) {
            $errors[] = 'INVALID_CLIENT_STATE';
        }
        if (!empty($outcome['requires_callback'])) {
            if (!is_array($callback) || empty($callback['due_at']) || empty($callback['timezone']) || trim(isset($callback['note']) ? $callback['note'] : '') === '') {
                $errors[] = 'CALLBACK_REQUIRED';
            } elseif (!self::validTimezone($callback['timezone'])) {
                $errors[] = 'INVALID_TIMEZONE';
            }
        }
        return $errors;
    }

    public static function validTimezone($timezone)
    {
        if (!is_string($timezone) || $timezone === '') {
            return false;
        }
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function localToUtc($value, $timezone)
    {
        if (!self::validTimezone($timezone)) {
            throw new InvalidArgumentException('INVALID_TIMEZONE');
        }
        $date = new DateTime($value, new DateTimeZone($timezone));
        $date->setTimezone(new DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s');
    }

    public static function safeIdempotencyKey($key)
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key);
    }
}
