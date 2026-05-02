<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Deposits\Components;

/**
 * Detects orders sent to Redsys that never received a callback confirmation.
 * Sends an email alert so the team can verify in Getnet and resolve manually.
 *
 * Runs hourly via WP-Cron. Only fires when stuck orders are found.
 * Dedupe is two-layered:
 *   1. Per payment attempt: tracks date_modified at alert time so a real new
 *      attempt (new date_modified after a Redsys re-try) can trigger a new alert.
 *   2. Hard cooldown: even if date_modified changes for unrelated reasons
 *      (cron-driven saves, supplement recalcs, etc.), never re-alert within
 *      ALERT_COOLDOWN_SECONDS of the previous alert for the same order.
 *
 * Only alerts on `pending` and `on-hold` orders — these are orders where
 * the initial payment (deposit or full) was sent to Redsys but never confirmed.
 * `deposit-paid` orders are NOT included because they legitimately wait weeks
 * for the balance payment before the event.
 */
class PaymentReconciliation
{
    private const HOOK = 'zs_payment_reconciliation_check';
    private const STUCK_THRESHOLD_SECONDS = 3600; // 1 hour
    private const ALERT_COOLDOWN_SECONDS  = 7 * DAY_IN_SECONDS;

    public function register(): void
    {
        add_action('init', [$this, 'schedule']);
        add_action(self::HOOK, [$this, 'run']);
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public function run(): void
    {
        $orders = $this->getStuckOrders();

        if (empty($orders)) {
            return;
        }

        // Two-layer dedupe: per-attempt (date_modified) + hard cooldown (alerted_at)
        $newAlerts = [];
        foreach ($orders as $order) {
            $lastAlerted     = (int) $order->get_meta('_zs_reconciliation_alerted_modified');
            $alertedAt       = (int) $order->get_meta('_zs_reconciliation_alerted_at');
            $currentModified = $order->get_date_modified()?->getTimestamp();

            if ($lastAlerted && $lastAlerted === $currentModified) {
                continue;
            }

            if ($alertedAt && (time() - $alertedAt) < self::ALERT_COOLDOWN_SECONDS) {
                continue;
            }

            $newAlerts[] = $order;
        }

        if (!empty($newAlerts)) {
            $this->sendAlert($newAlerts);

            // Persist dedupe metas without bumping date_modified.
            // $order->save() (and save_meta_data()) update wp_wc_orders.date_updated_gmt
            // under HPOS, which would re-trigger the per-attempt dedupe path on the next
            // cron cycle and cause a recurring nightly self-touch loop.
            foreach ($newAlerts as $order) {
                $modified = $order->get_date_modified()?->getTimestamp();
                $this->writeMetaDirect($order->get_id(), '_zs_reconciliation_alerted_modified', (string) $modified);
                $this->writeMetaDirect($order->get_id(), '_zs_reconciliation_alerted_at', (string) time());
            }
        }
    }

    /**
     * Direct upsert into wp_wc_orders_meta so date_updated_gmt is not bumped.
     */
    private function writeMetaDirect(int $orderId, string $key, string $value): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_orders_meta';

        $existingId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d AND meta_key = %s LIMIT 1",
            $orderId,
            $key
        ));

        if ($existingId) {
            $wpdb->update($table, ['meta_value' => $value], ['id' => (int) $existingId]);
        } else {
            $wpdb->insert($table, [
                'order_id'   => $orderId,
                'meta_key'   => $key,
                'meta_value' => $value,
            ]);
        }

        wp_cache_delete($orderId, 'orders');
    }

    /**
     * Find orders that were sent to Redsys (have _zs_redsys_params)
     * but still sit in a pre-payment status after the threshold.
     *
     * Only `pending` and `on-hold` — NOT `deposit-paid`, which is a normal
     * state for orders waiting for the balance payment weeks later.
     *
     * @return \WC_Order[]
     */
    private function getStuckOrders(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STUCK_THRESHOLD_SECONDS);

        return wc_get_orders([
            'status'        => ['pending', 'on-hold'],
            'date_modified' => '<' . $cutoff,
            'meta_key'      => '_zs_redsys_params',
            'meta_compare'  => 'EXISTS',
            'limit'         => 20,
            'orderby'       => 'date',
            'order'         => 'ASC',
        ]);
    }

    /**
     * @param \WC_Order[] $orders
     */
    private function sendAlert(array $orders): void
    {
        $to      = get_option('admin_email');
        $subject = sprintf('[Paellas en Casa] %d pedido(s) sin confirmación de pago', count($orders));

        $lines = ["Los siguientes pedidos fueron enviados a Redsys pero no hemos recibido confirmación:\n"];

        foreach ($orders as $order) {
            $modified = $order->get_date_modified();
            $ago      = $modified ? human_time_diff($modified->getTimestamp(), time()) : '?';

            $lines[] = sprintf(
                "- Pedido #%d — %s — %s EUR — estado: %s — hace %s",
                $order->get_id(),
                $order->get_formatted_billing_full_name(),
                number_format((float) $order->get_total(), 2, ',', '.'),
                $order->get_status(),
                $ago
            );
        }

        $lines[] = "\nVerificar en el panel de Getnet si estos cobros se realizaron.";
        $lines[] = "Si el pago se confirma, actualizar el estado del pedido manualmente en WooCommerce.";

        wp_mail($to, $subject, implode("\n", $lines));
    }
}
