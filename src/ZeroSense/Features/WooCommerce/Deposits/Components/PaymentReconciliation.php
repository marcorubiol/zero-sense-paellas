<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Deposits\Components;

/**
 * Detects orders sent to Redsys that never received a callback confirmation.
 * Sends an email alert so the team can verify in Getnet and resolve manually.
 *
 * Runs hourly via WP-Cron. Only fires when stuck orders are found.
 * Alerts once per payment attempt — tracks the order's date_modified at
 * alert time so a new attempt (new date_modified) triggers a new alert.
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

        // Filter out orders already alerted for this specific payment attempt
        $newAlerts = [];
        foreach ($orders as $order) {
            $lastAlerted = $order->get_meta('_zs_reconciliation_alerted_modified');
            $currentModified = $order->get_date_modified()?->getTimestamp();

            if ($lastAlerted && (int) $lastAlerted === $currentModified) {
                continue;
            }

            $newAlerts[] = $order;
        }

        if (!empty($newAlerts)) {
            $this->sendAlert($newAlerts);

            foreach ($newAlerts as $order) {
                $order->update_meta_data(
                    '_zs_reconciliation_alerted_modified',
                    $order->get_date_modified()?->getTimestamp()
                );
                $order->save();
            }
        }
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
