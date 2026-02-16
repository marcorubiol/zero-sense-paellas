<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;

class FieldChangeTracker
{
    private const META_KEY = '_zs_field_changes';

    public static function trackFieldChange(int $orderId, string $fieldKey): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $changes = $order->get_meta(self::META_KEY, true);
        if (!is_array($changes)) {
            $changes = [];
        }

        $changes[$fieldKey] = current_time('mysql');
        
        $order->update_meta_data(self::META_KEY, $changes);
        $order->save_meta_data();
    }

    public static function trackMultipleFieldChanges(int $orderId, array $fieldKeys): void
    {
        if (empty($fieldKeys)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $changes = $order->get_meta(self::META_KEY, true);
        if (!is_array($changes)) {
            $changes = [];
        }

        $timestamp = current_time('mysql');
        foreach ($fieldKeys as $fieldKey) {
            $changes[$fieldKey] = $timestamp;
        }
        
        $order->update_meta_data(self::META_KEY, $changes);
        $order->save_meta_data();
    }

    public static function isFieldRecentlyChanged(int $orderId, string $fieldKey): bool
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return false;
        }

        $eventDate = $order->get_meta('zs_event_date', true);
        if (!is_string($eventDate) || $eventDate === '') {
            return false;
        }

        $changes = $order->get_meta(self::META_KEY, true);
        if (!is_array($changes) || !isset($changes[$fieldKey])) {
            return false;
        }

        $fieldChangeTime = $changes[$fieldKey];
        if (!is_string($fieldChangeTime) || $fieldChangeTime === '') {
            return false;
        }

        $eventTimestamp = strtotime($eventDate . ' 23:59:59');
        $changeTimestamp = strtotime($fieldChangeTime);
        $weekBeforeEvent = strtotime($eventDate . ' 00:00:00') - (7 * 24 * 60 * 60);

        return $changeTimestamp >= $weekBeforeEvent && $changeTimestamp <= $eventTimestamp;
    }

    public static function getFieldChangeTimestamp(int $orderId, string $fieldKey): ?string
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return null;
        }

        $changes = $order->get_meta(self::META_KEY, true);
        if (!is_array($changes) || !isset($changes[$fieldKey])) {
            return null;
        }

        $timestamp = $changes[$fieldKey];
        return is_string($timestamp) && $timestamp !== '' ? $timestamp : null;
    }

    public static function compareAndTrack(int $orderId, string $fieldKey, $oldValue, $newValue): void
    {
        if ($oldValue === $newValue) {
            return;
        }

        if (is_array($oldValue) && is_array($newValue)) {
            if (json_encode($oldValue) === json_encode($newValue)) {
                return;
            }
        }

        self::trackFieldChange($orderId, $fieldKey);
    }
}
