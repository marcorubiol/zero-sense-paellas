<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class ProductAdmin
{
    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderField']);
        add_action('woocommerce_process_product_meta', [$this, 'saveField']);
    }

    public function renderField(): void
    {
        woocommerce_wp_checkbox([
            'id'          => MetaKeys::PRODUCT_HAS_RABBIT_OPTION,
            'label'       => __('Rabbit option', 'zero-sense'),
            'description' => __('Enable rabbit/no-rabbit choice for this product.', 'zero-sense'),
        ]);
    }

    public function saveField(int $postId): void
    {
        $value = isset($_POST[MetaKeys::PRODUCT_HAS_RABBIT_OPTION]) ? 'yes' : 'no';
        update_post_meta($postId, MetaKeys::PRODUCT_HAS_RABBIT_OPTION, $value);
    }
}
