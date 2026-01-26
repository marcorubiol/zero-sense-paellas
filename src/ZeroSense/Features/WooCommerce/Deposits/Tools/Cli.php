<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Tools;

if (!defined('ABSPATH')) { exit; }

class Cli
{
    public function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) { return; }
        \WP_CLI::add_command('zs-deposits migrate', [$this, 'cmdMigrate']);
    }

    /**
     * WP-CLI: Migrate legacy deposits meta to v3.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Do not write changes, only report what would change.
     *
     * [--force]
     * : Re-run migration even if an order has the migration flag.
     *
     * [--limit=<number>]
     * : Number of orders to process per batch. Default 200.
     *
     * [--offset=<number>]
     * : Offset for pagination. Default 0.
     *
     * [--status=<csv>]
     * : Comma-separated list of order statuses to include (e.g. pending,processing,completed).
     *
     * ## EXAMPLES
     *
     *   # Dry-run the first 500 orders
     *   wp zs-deposits migrate --dry-run --limit=500
     *
     *   # Process next 500 orders starting at offset 500
     *   wp zs-deposits migrate --limit=500 --offset=500
     */
    public function cmdMigrate(array $args, array $assoc_args): void
    {
        $dryRun = isset($assoc_args['dry-run']);
        $force  = isset($assoc_args['force']);
        $limit  = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 200;
        $offset = isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0;
        $statusCsv = isset($assoc_args['status']) ? (string) $assoc_args['status'] : '';
        $statuses = $statusCsv !== '' ? array_map('trim', explode(',', $statusCsv)) : [];

        \WP_CLI::log(sprintf('Starting migration: dryRun=%s force=%s limit=%d offset=%d statuses=%s',
            $dryRun ? 'yes' : 'no', $force ? 'yes' : 'no', $limit, $offset, $statusCsv ?: '(all)'));

        $ids = Migrator::findOrdersNeedingMigration($limit, $offset, $statuses);
        if (!$ids) {
            \WP_CLI::success('No candidate orders found.');
            return;
        }

        $total = count($ids);
        $changed = 0; $skipped = 0; $errors = 0;

        foreach ($ids as $i => $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) { $skipped++; continue; }

            $result = Migrator::migrateOrder($order, ['dry_run' => $dryRun, 'force' => $force]);
            $changed += (int) ($result['changed'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
            $errors  += (int) ($result['errors'] ?? 0);

            \WP_CLI::log(sprintf('[%d/%d] #%d changed=%d skipped=%d errors=%d', $i+1, $total, $orderId, $result['changed'] ?? 0, $result['skipped'] ?? 0, $result['errors'] ?? 0));
        }

        if ($dryRun) {
            \WP_CLI::success(sprintf('Dry-run complete. Candidates=%d, would change=%d, skipped=%d, errors=%d', $total, $changed, $skipped, $errors));
        } else {
            \WP_CLI::success(sprintf('Migration complete. Processed=%d, changed=%d, skipped=%d, errors=%d', $total, $changed, $skipped, $errors));
        }
    }
}
