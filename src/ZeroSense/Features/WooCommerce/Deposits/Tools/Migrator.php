<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Tools;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class Migrator
{
    public const MIGRATION_FLAG = 'zs_deposits_migrated_v3';

    /**
     * Migrate a single order's legacy zs_wd_* meta to v3 zs_deposits_*.
     *
     * @param WC_Order $order
     * @param array{dry_run?:bool,force?:bool} $opts
     * @return array{changed:int,skipped:int,errors:int,notes:array}
     */
    public static function migrateOrder(WC_Order $order, array $opts = []): array
    {
        $dryRun = !empty($opts['dry_run']);
        $force  = !empty($opts['force']);
        $notes  = [];

        // Idempotency
        $already = $order->get_meta(self::MIGRATION_FLAG, true);
        if ($already && !$force) {
            return ['changed' => 0, 'skipped' => 1, 'errors' => 0, 'notes' => ['already_migrated' => $already]];
        }

        $changed = 0;
        $errors  = 0;

        // Map keys we care about
        $keys = [
            MetaKeys::HAS_DEPOSIT,
            MetaKeys::DEPOSIT_AMOUNT,
            MetaKeys::REMAINING_AMOUNT,
            MetaKeys::IS_MANUAL_OVERRIDE,
            MetaKeys::DEPOSIT_PERCENTAGE,
            MetaKeys::IS_DEPOSIT_PAID,
            MetaKeys::FIRST_PAYMENT_DATE,
            MetaKeys::IS_BALANCE_PAID,
            MetaKeys::SECOND_PAYMENT_DATE,
            MetaKeys::PAYMENT_FLOW,
            MetaKeys::IS_CANCELLED,
            MetaKeys::CANCELLED_DATE,
            MetaKeys::IS_FAILED,
            MetaKeys::FAILED_CODE,
            MetaKeys::FAILED_DATE,
        ];

        // Read legacy values explicitly from legacy keys
        $legacyValues = [];
        foreach ($keys as $k) {
            $legacyKey = MetaKeys::getLegacyKey($k);
            if (!$legacyKey) { continue; }
            $legacyValues[$k] = $order->get_meta($legacyKey, true);
        }

        // Determine if there is anything to migrate
        $hasLegacy = false;
        foreach ($legacyValues as $val) {
            if ($val !== '' && $val !== null) { $hasLegacy = true; break; }
        }
        if (!$hasLegacy && !$force) {
            return ['changed' => 0, 'skipped' => 1, 'errors' => 0, 'notes' => ['no_legacy_found' => true]];
        }

        // Compute values to write
        $orderTotal = (float) $order->get_total();
        $depositAmount = self::toFloat($legacyValues[MetaKeys::DEPOSIT_AMOUNT] ?? $order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true));
        $remainingAmount = self::toFloat($legacyValues[MetaKeys::REMAINING_AMOUNT] ?? $order->get_meta(MetaKeys::REMAINING_AMOUNT, true));

        if ($remainingAmount <= 0.0 && $depositAmount > 0.0) {
            $computedRemaining = max(0.0, $orderTotal - $depositAmount);
            if ($computedRemaining > 0.0) {
                $remainingAmount = $computedRemaining;
                $notes['computed_remaining'] = $computedRemaining;
            }
        }

        // Prepare writes (only if value differs or missing)
        $writes = [];
        $writes[MetaKeys::HAS_DEPOSIT] = self::normalizeFlag($legacyValues[MetaKeys::HAS_DEPOSIT] ?? $order->get_meta(MetaKeys::HAS_DEPOSIT, true));
        if ($depositAmount > 0.0) {
            $writes[MetaKeys::DEPOSIT_AMOUNT] = $depositAmount;
        }
        if ($remainingAmount > 0.0) {
            $writes[MetaKeys::REMAINING_AMOUNT] = $remainingAmount;
        }
        $copyKeys = [
            MetaKeys::IS_MANUAL_OVERRIDE,
            MetaKeys::DEPOSIT_PERCENTAGE,
            MetaKeys::IS_DEPOSIT_PAID,
            MetaKeys::FIRST_PAYMENT_DATE,
            MetaKeys::IS_BALANCE_PAID,
            MetaKeys::SECOND_PAYMENT_DATE,
            MetaKeys::PAYMENT_FLOW,
            MetaKeys::IS_CANCELLED,
            MetaKeys::CANCELLED_DATE,
            MetaKeys::IS_FAILED,
            MetaKeys::FAILED_CODE,
            MetaKeys::FAILED_DATE,
        ];
        foreach ($copyKeys as $ck) {
            $val = $legacyValues[$ck] ?? null;
            if ($val !== null && $val !== '') {
                $writes[$ck] = $val;
            }
        }

        if (!$dryRun) {
            foreach ($writes as $k => $v) {
                if ($k === MetaKeys::HAS_DEPOSIT) {
                    // Expect 'yes'/'no'
                    $order->update_meta_data($k, $v ? 'yes' : 'no');
                } else {
                    $order->update_meta_data($k, $v);
                }
                $changed++;
            }
            $order->update_meta_data(self::MIGRATION_FLAG, date('c'));
            $order->save();

            try {
                Logs::add($order, 'migration', [
                    'action' => 'migrated_to_v3',
                    'changed' => $changed,
                    'notes' => $notes,
                ]);
            } catch (\Throwable $e) { /* no-op */ }
        }

        return ['changed' => $changed, 'skipped' => 0, 'errors' => $errors, 'notes' => $notes];
    }

    /**
     * Find candidate orders that likely need migration.
     * This simple finder returns recent orders optionally with status filtering.
     * For precise selection, run without filters and let per-order routine decide.
     *
     * @return int[] order IDs
     */
    public static function findOrdersNeedingMigration(int $limit = 200, int $offset = 0, array $statuses = []): array
    {
        $args = [
            'type'   => 'shop_order',
            'limit'  => $limit,
            'offset' => $offset,
            'return' => 'ids',
            'orderby'=> 'date',
            'order'  => 'DESC',
        ];
        if ($statuses) { $args['status'] = $statuses; }

        if (!function_exists('wc_get_orders')) { return []; }
        $ids = wc_get_orders($args);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    private static function toFloat($val): float
    {
        if ($val === '' || $val === null) { return 0.0; }
        return (float) $val;
    }

    private static function normalizeFlag($val): bool
    {
        return in_array($val, ['yes', '1', 1, true], true);
    }
}
