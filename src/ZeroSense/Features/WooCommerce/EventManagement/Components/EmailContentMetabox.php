<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class EmailContentMetabox
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
            'zs_email_content',
            __('Email Content', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'normal',
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

        $budgetEmailContent = $order->get_meta(MetaKeys::BUDGET_EMAIL_CONTENT, true);
        $finalDetailsEmailContent = $order->get_meta(MetaKeys::FINAL_DETAILS_EMAIL_CONTENT, true);
        
        wp_nonce_field('zs_email_content_save', 'zs_email_content_nonce');
        ?>
        
        <div class="zs-email-content-wrapper">
            <div class="zs-field">
                <label for="budget_email_content">
                    <?php esc_html_e('Budget Email Content', 'zero-sense'); ?>
                </label>
                <textarea id="budget_email_content" name="budget_email_content" rows="6" class="widefat"><?php echo esc_textarea(is_string($budgetEmailContent) ? $budgetEmailContent : ''); ?></textarea>
                <p class="description"><?php esc_html_e('Custom content for budget emails sent via FlowMattic.', 'zero-sense'); ?></p>
            </div>
            
            <div class="zs-field">
                <label for="final_details_email_content">
                    <?php esc_html_e('Final Details Email Content', 'zero-sense'); ?>
                </label>
                <textarea id="final_details_email_content" name="final_details_email_content" rows="6" class="widefat"><?php echo esc_textarea(is_string($finalDetailsEmailContent) ? $finalDetailsEmailContent : ''); ?></textarea>
                <p class="description"><?php esc_html_e('Custom content for final details emails sent via FlowMattic.', 'zero-sense'); ?></p>
            </div>
        </div>

        <style>
            .zs-email-content-wrapper {
                padding: 12px;
            }
            .zs-email-content-wrapper .zs-field {
                margin-bottom: 16px;
            }
            .zs-email-content-wrapper .zs-field:last-child {
                margin-bottom: 0;
            }
            .zs-email-content-wrapper .zs-field label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                font-size: 13px;
                color: #1d2327;
            }
        </style>
        <?php
    }

    public function save($orderId): void
    {
        if (!isset($_POST['zs_email_content_nonce']) || 
            !wp_verify_nonce($_POST['zs_email_content_nonce'], 'zs_email_content_save')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['budget_email_content'])) {
            $order->update_meta_data(MetaKeys::BUDGET_EMAIL_CONTENT, sanitize_textarea_field((string) $_POST['budget_email_content']));
        }
        if (isset($_POST['final_details_email_content'])) {
            $order->update_meta_data(MetaKeys::FINAL_DETAILS_EMAIL_CONTENT, sanitize_textarea_field((string) $_POST['final_details_email_content']));
        }

        $order->save();
    }
}
