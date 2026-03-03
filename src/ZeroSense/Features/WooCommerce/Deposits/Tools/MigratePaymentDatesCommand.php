<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Tools;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;

/**
 * WP-CLI command to migrate payment dates from old keys to new keys
 * 
 * Usage:
 * wp eval-file wp-content/plugins/zero-sense/src/ZeroSense/Features/WooCommerce/Deposits/Tools/MigratePaymentDatesCommand.php
 */
class MigratePaymentDatesCommand
{
    public static function run(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            die('This script can only be run via WP-CLI');
        }

        \WP_CLI::line('Starting payment dates migration...');
        \WP_CLI::line('');

        $migrated_first = 0;
        $migrated_second = 0;
        $skipped_first = 0;
        $skipped_second = 0;
        $errors = 0;

        // Get all orders
        $args = [
            'limit' => -1,
            'type' => 'shop_order',
            'return' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ];

        $order_ids = wc_get_orders($args);
        $total = count($order_ids);

        \WP_CLI::line("Found {$total} orders to process");
        \WP_CLI::line('');

        $progress = \WP_CLI\Utils\make_progress_bar('Migrating orders', $total);

        foreach ($order_ids as $order_id) {
            try {
                $order = wc_get_order($order_id);
                if (!$order instanceof WC_Order) {
                    $errors++;
                    $progress->tick();
                    continue;
                }

                $changed = false;

                // Migrate first payment date
                $old_first = $order->get_meta('zs_deposits_deposit_payment_date', true);
                $new_first = $order->get_meta('zs_first_payment_date', true);
                
                if ($old_first && !$new_first) {
                    $order->update_meta_data('zs_first_payment_date', $old_first);
                    $migrated_first++;
                    $changed = true;
                } elseif ($new_first) {
                    $skipped_first++;
                }

                // Migrate second payment date
                $old_second = $order->get_meta('zs_deposits_balance_payment_date', true);
                $new_second = $order->get_meta('zs_second_payment_date', true);
                
                if ($old_second && !$new_second) {
                    $order->update_meta_data('zs_second_payment_date', $old_second);
                    $migrated_second++;
                    $changed = true;
                } elseif ($new_second) {
                    $skipped_second++;
                }

                // Save if any changes were made
                if ($changed) {
                    $order->save_meta_data();
                }

            } catch (\Throwable $e) {
                $errors++;
                \WP_CLI::warning("Error processing order #{$order_id}: " . $e->getMessage());
            }

            $progress->tick();
        }

        $progress->finish();

        \WP_CLI::line('');
        \WP_CLI::success('Migration complete!');
        \WP_CLI::line('');
        \WP_CLI::line('Results:');
        \WP_CLI::line("  First payment dates:  {$migrated_first} migrated, {$skipped_first} already existed");
        \WP_CLI::line("  Second payment dates: {$migrated_second} migrated, {$skipped_second} already existed");
        
        if ($errors > 0) {
            \WP_CLI::warning("  Errors: {$errors}");
        }
    }
}

// Auto-execute when loaded via wp eval-file
if (defined('WP_CLI') && WP_CLI) {
    MigratePaymentDatesCommand::run();
}
