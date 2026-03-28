<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Support;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;

class MetaKeys
{
    // Configuration
    public const HAS_DEPOSIT = 'zs_deposits_has_deposit';
    public const DEPOSIT_AMOUNT = 'zs_deposits_deposit_amount';
    public const DEPOSIT_PERCENTAGE = 'zs_deposits_deposit_percentage';
    public const IS_MANUAL_OVERRIDE = 'zs_deposits_is_manual_override';
    
    // Dynamic amounts
    /** Remaining amount to pay right now (becomes 0 when fully paid) */
    public const REMAINING_AMOUNT = 'zs_deposits_remaining_amount';
    /** Mathematical difference total - deposit (updates with order changes until fully paid) */
    public const BALANCE_AMOUNT = 'zs_deposits_balance_amount';
    
    // Deposit payment status
    public const IS_DEPOSIT_PAID = 'zs_deposits_is_deposit_paid';
    
    // Payment dates (new naming)
    public const FIRST_PAYMENT_DATE = 'zs_first_payment_date';
    public const SECOND_PAYMENT_DATE = 'zs_second_payment_date';
    
    // Legacy payment date keys (deprecated but maintained for compatibility)
    /** @deprecated Use FIRST_PAYMENT_DATE instead */
    public const DEPOSIT_PAYMENT_DATE = 'zs_deposits_deposit_payment_date';
    
    // Balance payment status
    public const IS_BALANCE_PAID = 'zs_deposits_is_balance_paid';
    
    /** @deprecated Use SECOND_PAYMENT_DATE instead */
    public const BALANCE_PAYMENT_DATE = 'zs_deposits_balance_payment_date';
    
    // Payment flow metadata
    public const PAYMENT_FLOW = 'zs_deposits_payment_flow';
    
    // Error and cancellation tracking
    public const IS_CANCELLED = 'zs_deposits_is_cancelled';
    public const CANCELLED_DATE = 'zs_deposits_cancelled_date';
    public const IS_FAILED = 'zs_deposits_is_failed';
    public const FAILED_CODE = 'zs_deposits_failed_code';
    public const FAILED_DATE = 'zs_deposits_failed_date';

    /**
     * Cache previously fetched meta values to avoid duplicate lookups within a request.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $cache = [];

    public static function get(WC_Order $order, string $key, bool $single = true)
    {
        $orderId = $order->get_id();

        if ($single && isset(self::$cache[$orderId][$key])) {
            return self::$cache[$orderId][$key];
        }

        $value = $order->get_meta($key, $single);

        // Auto-migrate from legacy keys if current value is empty
        if ($single && ($value === '' || $value === null)) {
            $value = self::tryLegacyMigration($order, $key);
        }

        if ($single) {
            self::remember($orderId, $key, $value);
        }

        return $value;
    }

    public static function isEnabled(WC_Order $order, string $key): bool
    {
        return self::normalizeFlag(self::get($order, $key)) === true;
    }

    public static function update(WC_Order $order, string $key, $value): void
    {
        $order->update_meta_data($key, $value);

        self::remember($order->get_id(), $key, $value);

        Utils::invalidateDepositInfoCache($order);
    }

    public static function delete(WC_Order $order, string $key): void
    {
        $order->delete_meta_data($key);

        self::forget($order->get_id(), $key);

        Utils::invalidateDepositInfoCache($order);
    }

    private static function normalizeFlag($value): bool
    {
        return in_array($value, ['yes', '1', 1, true], true);
    }

    private static function remember(int $orderId, string $key, $value): void
    {
        if (!isset(self::$cache[$orderId])) {
            self::$cache[$orderId] = [];
        }

        self::$cache[$orderId][$key] = $value;
    }

    private static function forget(int $orderId, string $key): void
    {
        if (isset(self::$cache[$orderId][$key])) {
            unset(self::$cache[$orderId][$key]);

            if (self::$cache[$orderId] === []) {
                unset(self::$cache[$orderId]);
            }
        }
    }

    /**
     * Clear the in-process meta cache for a specific order.
     * Used in callback handlers to ensure fresh DB reads.
     */
    public static function clearCacheForOrder(int $orderId): void
    {
        unset(self::$cache[$orderId]);
    }

    /**
     * Try to migrate value from legacy key if it exists.
     * Returns the migrated value or null if no legacy value found.
     */
    private static function tryLegacyMigration(WC_Order $order, string $key)
    {
        $registry = \ZeroSense\Core\MetaFieldRegistry::getInstance();
        $legacyKeys = $registry->getLegacyAliases($key);

        if (empty($legacyKeys)) {
            return null;
        }

        foreach ($legacyKeys as $legacyKey) {
            $legacyValue = $order->get_meta($legacyKey, true);
            
            if ($legacyValue !== '' && $legacyValue !== null) {
                // Migrate the value to the new key
                self::update($order, $key, $legacyValue);
                $order->save_meta_data();
                
                return $legacyValue;
            }
        }

        return null;
    }
}
