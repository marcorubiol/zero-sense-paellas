<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Tools;

use ZeroSense\Features\WooCommerce\Deposits\Tools\Migrator;

if (!defined('ABSPATH')) { exit; }

class Batch
{
    private const STATE_OPTION = 'zs_deposits_migration_state';

    public function register(): void
    {
        add_action('wp_ajax_zs_deposits_migration_next', [$this, 'ajaxNext']);
        add_action('wp_ajax_zs_deposits_migration_reset', [$this, 'ajaxReset']);
        add_action('wp_ajax_zs_deposits_migration_inspect', [$this, 'ajaxInspect']);
    }

    public static function getState(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state)) { $state = []; }
        $defaults = [
            'offset' => 0,
            'limit' => 200,
            'processed' => 0,
            'changed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'dry_run' => true,
            'statuses' => [],
            'done' => false,
            'last_run' => null,
        ];
        return array_merge($defaults, $state);
    }

    public static function saveState(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    public function ajaxReset(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        delete_option(self::STATE_OPTION);
        wp_send_json_success(['message' => 'state_reset']);
    }

    public function ajaxNext(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        $state = self::getState();

        // Allow client to set dry_run, limit, statuses on first call
        if (isset($_POST['init']) && $_POST['init'] === '1') {
            $state['dry_run'] = isset($_POST['dry_run']) ? ($_POST['dry_run'] === '1') : true;
            $state['limit'] = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 200;
            $statusCsv = isset($_POST['statuses']) ? sanitize_text_field((string) $_POST['statuses']) : '';
            $state['statuses'] = $statusCsv ? array_map('trim', explode(',', $statusCsv)) : [];
            $state['offset'] = 0;
            $state['processed'] = 0;
            $state['changed'] = 0;
            $state['skipped'] = 0;
            $state['errors'] = 0;
            $state['done'] = false;
        }

        if ($state['done']) {
            wp_send_json_success(['state' => $state, 'message' => 'already_done']);
        }

        $ids = Migrator::findOrdersNeedingMigration($state['limit'], $state['offset'], $state['statuses']);
        $count = is_array($ids) ? count($ids) : 0;

        if ($count === 0) {
            $state['done'] = true;
            $state['last_run'] = current_time('mysql');
            self::saveState($state);
            wp_send_json_success(['state' => $state, 'batch' => ['count' => 0]]);
        }

        $batchChanged = 0; $batchSkipped = 0; $batchErrors = 0;
        $changedIds = []; $skippedIds = []; $errorIds = [];
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                $batchSkipped++; $skippedIds[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            $res = Migrator::migrateOrder($order, ['dry_run' => $state['dry_run']]);
            $batchChanged += (int) ($res['changed'] ?? 0);
            $batchSkipped += (int) ($res['skipped'] ?? 0);
            $batchErrors  += (int) ($res['errors'] ?? 0);
            if (!empty($res['changed'])) { $changedIds[] = $id; }
            if (!empty($res['skipped'])) {
                $reason = 'unknown';
                if (!empty($res['notes']['already_migrated'])) { $reason = 'already_migrated'; }
                elseif (!empty($res['notes']['no_legacy_found'])) { $reason = 'no_legacy_found'; }
                $skippedIds[] = ['id' => $id, 'reason' => $reason];
            }
        }

        $state['offset'] += $count;
        $state['processed'] += $count;
        $state['changed'] += $batchChanged;
        $state['skipped'] += $batchSkipped;
        $state['errors']  += $batchErrors;
        $state['last_run'] = current_time('mysql');

        self::saveState($state);

        wp_send_json_success([
            'state' => $state,
            'batch' => [
                'count' => $count,
                'changed' => $batchChanged,
                'skipped' => $batchSkipped,
                'errors' => $batchErrors,
                'changed_ids' => $changedIds,
                'skipped_ids' => $skippedIds,
                'error_ids' => $errorIds,
            ],
        ]);
    }

    public function ajaxInspect(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $dryRun  = isset($_POST['dry_run']) ? ($_POST['dry_run'] === '1') : true;
        if (!$orderId) { wp_send_json_error(['message' => 'invalid_order']); }
        $order = wc_get_order($orderId);
        if (!$order) { wp_send_json_error(['message' => 'order_not_found']); }

        $res = Migrator::migrateOrder($order, ['dry_run' => $dryRun, 'force' => true]);
        wp_send_json_success(['order_id' => $orderId, 'result' => $res]);
    }
}
