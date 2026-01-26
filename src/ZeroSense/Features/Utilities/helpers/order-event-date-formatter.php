<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zs_format_event_date_for_admin')) {
    function zs_format_event_date_for_admin($value): string
    {
        if (empty($value)) {
            return '-';
        }

        if (is_numeric($value) && (int) $value == $value) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return date_i18n('d/m/Y', $timestamp);
            }
        }

        if (is_string($value)) {
            try {
                $date = new DateTime($value);
                return $date->format('d/m/Y');
            } catch (Exception $exception) {
                if (preg_match('/^(\d{2}\/\d{2}\/\d{4})/', $value, $matches)) {
                    return $matches[1];
                }
            }
        }

        return '-';
    }
}
