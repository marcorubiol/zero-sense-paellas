<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

/**
 * Adds an admin bar note on order-pay showing order id, status, and Pay Later availability.
 */
class AdminBarDebug
{
    public function __construct()
    {
        add_action('admin_bar_menu', [$this, 'addAdminBarNote'], 999);
        // Fallback: if admin bar is not visible, print a small footer badge on order-pay for admins
        add_action('wp_footer', [$this, 'maybeRenderFooterBadge']);
        // Last-resort fallback: render a tiny badge early in the head
        add_action('wp_head', [$this, 'maybeRenderHeadBadge']);
        // Content-level badge to guarantee visibility on the order-pay page content
        add_action('woocommerce_before_pay', [$this, 'renderContentBadge'], 5);
    }

    public function addAdminBarNote(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (!function_exists('wc_get_order')) {
            return;
        }

        [$order_id, $status, $pay_later_available] = $this->collectDebugInfo();

        $title = sprintf(
            'Order-Pay • #%s • status=%s • pay_later=%s',
            $order_id ? (string) $order_id : 'N/A',
            esc_html($status),
            esc_html($pay_later_available)
        );

        $wp_admin_bar->add_node([
            'id'    => 'zs-order-pay-debug',
            'title' => $title,
            'meta'  => [
                'class' => 'zs-order-pay-debug',
            ],
        ]);
    }

    public function maybeRenderFooterBadge(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (!function_exists('wc_get_order')) {
            return;
        }

        [$order_id, $status, $pay_later_available] = $this->collectDebugInfo();

        $text = sprintf(
            'Order-Pay • #%s • status=%s • pay_later=%s',
            $order_id ? (string) $order_id : 'N/A',
            esc_html($status),
            esc_html($pay_later_available)
        );

        echo '<div style="position:fixed;right:10px;bottom:10px;z-index:99999;background:#111;color:#fff;padding:8px 10px;border-radius:4px;font:12px/1.3 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial;opacity:0.9;">' . esc_html($text) . '</div>';
    }

    /**
     * Collect order id, status and Pay Later availability for current order-pay context.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function collectDebugInfo(): array
    {
        $order_id = 0;
        if (function_exists('get_query_var')) {
            $order_id = absint(get_query_var('order-pay'));
        }
        if ($order_id <= 0 && isset($_GET['key']) && function_exists('wc_get_order_id_by_order_key')) {
            $order_key = sanitize_text_field(wp_unslash($_GET['key']));
            $resolved = absint(wc_get_order_id_by_order_key($order_key));
            if ($resolved > 0) {
                $order_id = $resolved;
            }
        }
        if ($order_id <= 0 && isset($_GET['order_id'])) {
            $order_id = absint(wp_unslash($_GET['order_id']));
        }
        if ($order_id <= 0 && isset($_GET['order_key']) && function_exists('wc_get_order_id_by_order_key')) {
            $order_key = sanitize_text_field(wp_unslash($_GET['order_key']));
            $resolved = absint(wc_get_order_id_by_order_key($order_key));
            if ($resolved > 0) {
                $order_id = $resolved;
            }
        }

        $status = 'unknown';
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $status = $order->get_status();
            }
        }

        $pay_later_available = 'n/a';
        if (function_exists('WC')) {
            $gateways = WC()->payment_gateways();
            if ($gateways && method_exists($gateways, 'get_available_payment_gateways')) {
                $available = $gateways->get_available_payment_gateways();
                $pay_later_available = isset($available['pay_later']) ? 'yes' : 'no';
            }
        }

        return [$order_id, $status, $pay_later_available];
    }

    public function maybeRenderHeadBadge(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return;
        }

        [$order_id, $status, $pay_later_available] = $this->collectDebugInfo();

        $text = sprintf(
            'Order-Pay • #%s • status=%s • pay_later=%s',
            $order_id ? (string) $order_id : 'N/A',
            esc_html($status),
            esc_html($pay_later_available)
        );

        echo '<style>.zs-op-head-badge{position:fixed;left:10px;top:10px;z-index:99999;background:#111;color:#fff;padding:6px 8px;border-radius:4px;font:11px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial;opacity:.9}</style>';
        echo '<div class="zs-op-head-badge">' . esc_html($text) . '</div>';
    }

    public function renderContentBadge(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            return;
        }

        [$order_id, $status, $pay_later_available] = $this->collectDebugInfo();

        $text = sprintf(
            'Order-Pay • #%s • status=%s • pay_later=%s',
            $order_id ? (string) $order_id : 'N/A',
            esc_html($status),
            esc_html($pay_later_available)
        );

        echo '<div style="margin:10px 0;padding:10px;border:1px dashed #666;background:#111;color:#fff;border-radius:4px;font:12px/1.3 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial;">' . esc_html($text) . '</div>';
    }
}
