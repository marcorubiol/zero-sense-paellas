<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

use WC_Payment_Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Pay_Later extends WC_Payment_Gateway
{
    public $instructions;
    public $order_status;

    public function __construct()
    {
        $this->id = 'pay_later';
        $this->icon = apply_filters('woocommerce_pay_later_icon', '');
        $this->has_fields = false;
        $this->method_title = __('Pay Later', 'zero-sense');
        $this->method_description = __('Allows customers to place orders and pay later.', 'zero-sense');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->order_status = $this->get_option('order_status', 'on-hold');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'zero-sense'),
                'type' => 'checkbox',
                'label' => __('Enable Pay Later', 'zero-sense'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'zero-sense'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'zero-sense'),
                'default' => __('Pay Later', 'zero-sense'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'zero-sense'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'zero-sense'),
                'default' => __('Pay for your order later. We will contact you with payment details.', 'zero-sense'),
                'desc_tip' => true,
            ],
            'instructions' => [
                'title' => __('Instructions', 'zero-sense'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'zero-sense'),
                'default' => '',
                'desc_tip' => true,
            ],
            'order_status' => [
                'title' => __('Order Status', 'woocommerce'),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'description' => __('Choose the order status for new orders placed with this gateway.', 'woocommerce'),
                'default' => 'on-hold',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(),
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'result' => 'fail',
                'redirect' => '',
            ];
        }

        $order->update_status($this->order_status, __('Awaiting payment via Pay Later method.', 'zero-sense'));
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public function thankyou_page()
    {
        if ($this->instructions) {
            echo wpautop(wptexturize($this->instructions));
        }
    }

    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status($this->order_status)) {
            echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
        }
    }

    public function is_available()
    {
        return ('yes' === $this->enabled);
    }
}
