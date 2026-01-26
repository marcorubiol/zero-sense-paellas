<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

use WP_Query;

class CancelOrderButton
{
    public function __construct()
    {
        add_action('woocommerce_pay_order_after_submit', [$this, 'renderButton']);
        add_action('wp_ajax_zs_cancel_order', [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_zs_cancel_order', [$this, 'handleAjax']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    private function isOrderPayContext(): bool
    {
        return function_exists('is_checkout') && is_checkout() && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
    }

    public function enqueueAssets(): void
    {
        if (! $this->isOrderPayContext()) {
            return;
        }

        $handle = 'zero-sense-order-pay-cancel';
        $src    = plugins_url('src/ZeroSense/Features/WooCommerce/assets/order-pay-cancel.js', defined('ZERO_SENSE_FILE') ? ZERO_SENSE_FILE : __FILE__);
        // Fallback version by time to avoid cache while developing
        $ver    = defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : time();

        $order_id = $this->getOrderIdFromRequest();
        if (! $this->isPendingOrder($order_id)) {
            return;
        }

        wp_enqueue_script($handle, $src, [], $ver, true);

        wp_localize_script($handle, 'ZS_CancelOrder', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('zs_cancel_order'),
            'orderId'      => $order_id,
            'redirectUrl'  => $this->getCancelledStatusUrl(),
            'i18n'         => [
                'confirm' => __('Are you sure you want to cancel this order?', 'zero-sense'),
            ],
        ]);
    }

    public function renderButton(): void
    {
        if (! $this->isOrderPayContext()) {
            return;
        }

        $order_id = $this->getOrderIdFromRequest();
        if (! $order_id || ! $this->isPendingOrder($order_id)) {
            return;
        }
        ?>
        <div class="zs-cancel-button-wrapper">
            <button
                type="button"
                class="btn--danger btn--outline"
                id="zs-cancel-order-btn"
                data-order-id="<?php echo esc_attr($order_id); ?>"
            ><?php echo esc_html__('I am not interested', 'zero-sense'); ?></button>
            <p class="zs-explanation"><?php echo esc_html__('Click this button if you decide not to proceed, so we can free up the date for other events. Thank you for letting us know!', 'zero-sense'); ?></p>
        </div>
        <div id="zs-cancel-overlay" style="display:none;">
            <div class="zs-cancel-spinner"></div>
        </div>
        <style>
            #zs-cancel-overlay { position: fixed; z-index: 99999; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(255,255,255,0.7); display: none; align-items: center; justify-content: center; }
            body.zs-cancelling { opacity: 0.5; transition: opacity 0.2s; }
            .zs-cancel-spinner { border: 6px solid #f3f3f3; border-top: 6px solid var(--danger, #d33); border-radius: 50%; width: 60px; height: 60px; animation: zs-spin 1s linear infinite; }
            @keyframes zs-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
        <?php
    }

    public function handleAjax(): void
    {
        if (! isset($_POST['order_id'], $_POST['_wpnonce'])) {
            wp_send_json_error(__('Missing data', 'zero-sense'));
        }
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'zs_cancel_order')) {
            wp_send_json_error(__('Invalid nonce', 'zero-sense'));
        }

        $order_id = absint(wp_unslash($_POST['order_id']));
        if (! $order_id) {
            wp_send_json_error(__('Invalid order ID', 'zero-sense'));
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (! $order) {
            wp_send_json_error(__('Order not found', 'zero-sense'));
        }

        // Only allow admin or the order owner
        if (! current_user_can('manage_woocommerce') && get_current_user_id() !== (int) $order->get_user_id()) {
            wp_send_json_error(__('Not allowed', 'zero-sense'));
        }

        $order->update_status('cancelled', __('Order cancelled by customer.', 'zero-sense'));
        wp_send_json_success();
    }

    private function getOrderIdFromRequest(): int
    {
        $order_id = 0;
        if (isset($_GET['order-pay'])) {
            $order_id = absint($_GET['order-pay']);
        } elseif (function_exists('get_query_var') && get_query_var('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
        }
        return $order_id;
    }

    private function isPendingOrder(int $order_id): bool
    {
        if (! $order_id) {
            return false;
        }
        if (! function_exists('wc_get_order')) {
            return false;
        }
        $order = wc_get_order($order_id);
        if (! $order) {
            return false;
        }
        return $order->has_status('pending');
    }

    private function getCancelledStatusUrl(): string
    {
        // Try to get Spanish slug 'cancelado' of CPT 'woo-status'
        $default_cancelled = get_page_by_path('cancelado', OBJECT, 'woo-status');

        if (! $default_cancelled || (isset($default_cancelled->post_type) && $default_cancelled->post_type !== 'woo-status')) {
            $query = new WP_Query([
                'name'           => 'cancelado',
                'post_type'      => 'woo-status',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);
            if ($query->have_posts()) {
                $default_cancelled = $query->posts[0];
            }
        }

        $cancelled_id = $default_cancelled ? (int) $default_cancelled->ID : 0;

        // Translate via WPML if available
        if ($cancelled_id && function_exists('apply_filters')) {
            $lang = defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : null;
            if ($lang) {
                $translated_id = apply_filters('wpml_object_id', $cancelled_id, 'woo-status', false, $lang);
                if ($translated_id) {
                    $cancelled_id = (int) $translated_id;
                }
            }
        }

        return $cancelled_id ? get_permalink($cancelled_id) : home_url('/');
    }
}
