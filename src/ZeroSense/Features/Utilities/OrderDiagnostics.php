<?php

declare(strict_types=1);

namespace ZeroSense\Features\Utilities;

use ZeroSense\Core\FeatureInterface;

/**
 * Logs orders that are non-selectable/non-deletable in HPOS mode.
 * These are orders that exist in wp_posts but not in wc_orders (legacy unmigrated orders).
 * Check logs via: WP Admin > Tools > WooCommerce > Status > Logs > zero-sense-order-diagnostics
 */
class OrderDiagnostics implements FeatureInterface
{
    private const LOG_SOURCE = 'zero-sense-order-diagnostics';

    public function getName(): string
    {
        return __('Order Diagnostics', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Logs orders that are non-selectable or non-deletable in HPOS mode (legacy unmigrated orders).', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Utilities';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
    }

    public function init(): void
    {
        add_action('admin_init', [$this, 'maybeRunDiagnostics']);
        add_action('wp_ajax_zs_run_order_diagnostics', [$this, 'ajaxRunDiagnostics']);
        add_action('admin_notices', [$this, 'maybeShowDiagnosticsButton']);
    }

    public function maybeShowDiagnosticsButton(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        $lastRun = (int) get_option('zs_order_diagnostics_last_run', 0);
        $nonce   = wp_create_nonce('zs_order_diagnostics');
        ?>
        <div class="notice notice-info" style="display:flex; align-items:center; gap:12px;">
            <p style="margin:0;">
                <strong>ZS Order Diagnostics</strong> —
                <?php if ($lastRun): ?>
                    <?php printf(__('Último análisis: %s', 'zero-sense'), date('d/m/Y H:i', $lastRun)); ?>
                    — <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs&source=' . self::LOG_SOURCE)); ?>" target="_blank"><?php _e('Ver log', 'zero-sense'); ?></a>
                <?php else: ?>
                    <?php _e('No ejecutado aún.', 'zero-sense'); ?>
                <?php endif; ?>
            </p>
            <button type="button" class="button" id="zs-run-diagnostics" data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php _e('Analizar pedidos', 'zero-sense'); ?>
            </button>
            <span id="zs-diagnostics-result" style="color:#646970;"></span>
        </div>
        <script>
        document.getElementById('zs-run-diagnostics').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            document.getElementById('zs-diagnostics-result').textContent = '<?php echo esc_js(__('Analizando...', 'zero-sense')); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=zs_run_order_diagnostics&nonce=' + btn.dataset.nonce
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    document.getElementById('zs-diagnostics-result').textContent = data.data.message;
                } else {
                    document.getElementById('zs-diagnostics-result').textContent = '<?php echo esc_js(__('Error al analizar.', 'zero-sense')); ?>';
                }
            });
        });
        </script>
        <?php
    }

    public function maybeRunDiagnostics(): void
    {
        // Auto-run once per day silently in background on wc-orders page
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-orders') {
            return;
        }

        $lastRun = (int) get_option('zs_order_diagnostics_last_run', 0);
        if (time() - $lastRun < DAY_IN_SECONDS) {
            return;
        }

        $this->runDiagnostics();
    }

    public function ajaxRunDiagnostics(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'zs_order_diagnostics')) {
            wp_send_json_error('Invalid nonce');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->runDiagnostics();
        wp_send_json_success(['message' => $result]);
    }

    private function runDiagnostics(): string
    {
        global $wpdb;

        $hposEnabled = $this->isHposEnabled();
        $logger      = wc_get_logger();

        $logger->info('=== ZS Order Diagnostics START ===', ['source' => self::LOG_SOURCE]);
        $logger->info('HPOS enabled: ' . ($hposEnabled ? 'yes' : 'no'), ['source' => self::LOG_SOURCE]);

        if (!$hposEnabled) {
            $msg = 'HPOS not enabled — no legacy order issues expected.';
            $logger->info($msg, ['source' => self::LOG_SOURCE]);
            update_option('zs_order_diagnostics_last_run', time());
            return $msg;
        }

        // Orders in wp_posts but NOT in wc_orders (legacy unmigrated)
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hpos_table}'");

        if (!$table_exists) {
            $msg = "Table {$hpos_table} not found — HPOS may not be fully set up.";
            $logger->warning($msg, ['source' => self::LOG_SOURCE]);
            update_option('zs_order_diagnostics_last_run', time());
            return $msg;
        }

        $legacy_orders = $wpdb->get_results("
            SELECT p.ID, p.post_status, p.post_date
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'shop_order'
            AND p.ID NOT IN (SELECT id FROM {$hpos_table})
            ORDER BY p.ID DESC
            LIMIT 100
        ");

        $count = count($legacy_orders);
        $logger->info("Legacy orders (in wp_posts, NOT in wc_orders): {$count}", ['source' => self::LOG_SOURCE]);

        if ($count > 0) {
            foreach ($legacy_orders as $order) {
                $logger->info(
                    "  Legacy order #{$order->ID} | status: {$order->post_status} | date: {$order->post_date}",
                    ['source' => self::LOG_SOURCE]
                );
            }
            $logger->warning(
                "These {$count} orders are NOT selectable/deletable in HPOS mode. Run WooCommerce HPOS migration to fix: WooCommerce > Status > Tools > Migrate order data.",
                ['source' => self::LOG_SOURCE]
            );
        }

        // Orders in wc_orders but NOT in wp_posts (reverse check)
        $orphan_hpos = $wpdb->get_var("
            SELECT COUNT(*) FROM {$hpos_table} o
            WHERE o.type = 'shop_order'
            AND o.id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order')
        ");

        $logger->info("HPOS-only orders (in wc_orders, NOT in wp_posts): {$orphan_hpos}", ['source' => self::LOG_SOURCE]);

        // Check sync status
        $sync_enabled = get_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
        $logger->info("HPOS data sync enabled: {$sync_enabled}", ['source' => self::LOG_SOURCE]);

        $logger->info('=== ZS Order Diagnostics END ===', ['source' => self::LOG_SOURCE]);
        update_option('zs_order_diagnostics_last_run', time());

        if ($count > 0) {
            return sprintf(
                __('%d pedidos legacy encontrados (no seleccionables en HPOS). Ver log para detalles.', 'zero-sense'),
                $count
            );
        }

        return __('Sin pedidos legacy. Todo OK.', 'zero-sense');
    }

    private function isHposEnabled(): bool
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
}
