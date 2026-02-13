<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zs_format_event_date_for_admin')) {
    function zs_format_event_date_for_admin($value): string
    {
        if (empty($value) || !is_string($value)) {
            return '-';
        }

        // YYYY-MM-DD → dd/mm/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $value;
    }
}
