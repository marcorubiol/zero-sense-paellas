<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class CustomerPreferencesMetabox
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }
        
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20);
    }

    public function addMetabox(): void
    {
        $screen = wc_get_page_screen_id('shop-order');
        
        add_meta_box(
            'zs_customer_preferences',
            __('Customer Preferences', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'side',
            'default'
        );
    }

    public function render($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post 
            ? wc_get_order($postOrOrder->ID) 
            : $postOrOrder;
            
        if (!$order instanceof WC_Order) {
            return;
        }

        $marketingConsent = $order->get_meta(MetaKeys::MARKETING_CONSENT, true);
        $rabbitOption = $order->get_meta(MetaKeys::RABBIT_OPTION, true);
        
        wp_nonce_field('zs_customer_preferences_save', 'zs_customer_preferences_nonce');
        ?>
        
        <div class="zs-customer-preferences-wrapper">
            <p>
                <label>
                    <input type="checkbox" 
                           id="marketing_consent" 
                           name="marketing_consent" 
                           value="1"
                           <?php checked($marketingConsent, '1'); ?>>
                    <?php esc_html_e('Marketing Consent', 'zero-sense'); ?>
                </label>
                <span class="description" style="display:block;margin-top:4px;font-size:12px;color:#646970;">
                    <?php esc_html_e('Syncs with FluentCRM', 'zero-sense'); ?>
                </span>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" 
                           id="rabbit_option" 
                           name="rabbit_option" 
                           value="1"
                           <?php checked($rabbitOption, '1'); ?>>
                    <?php esc_html_e('Rabbit Option', 'zero-sense'); ?>
                </label>
                <span class="description" style="display:block;margin-top:4px;font-size:12px;color:#646970;">
                    <?php esc_html_e('Customer wants rabbit in paella', 'zero-sense'); ?>
                </span>
            </p>
        </div>
        <?php
    }

    public function save($orderId): void
    {
        if (!isset($_POST['zs_customer_preferences_nonce']) || 
            !wp_verify_nonce($_POST['zs_customer_preferences_nonce'], 'zs_customer_preferences_save')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $order->update_meta_data(MetaKeys::MARKETING_CONSENT, isset($_POST['marketing_consent']) ? '1' : '0');
        $order->update_meta_data(MetaKeys::RABBIT_OPTION, isset($_POST['rabbit_option']) ? '1' : '0');

        $order->save();
    }
}
