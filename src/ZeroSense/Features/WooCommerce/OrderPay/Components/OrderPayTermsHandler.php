<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

use WP_Error;

class OrderPayTermsHandler
{
    private bool $filtersApplied = false;

    public function __construct()
    {
        // Only apply on order-pay pages
        add_action('template_redirect', [$this, 'maybeApplyOrderPayFilters'], 1);
        add_filter('woocommerce_checkout_show_terms', [$this, 'handleOrderPayTerms'], PHP_INT_MAX, 1);
        add_filter('woocommerce_get_terms_and_conditions_checkbox_text', [$this, 'customOrderPayTermsText']);
        // We avoid injecting inside the Woo wrapper to prevent hidden duplicates.
        // Extra fallback: render checkbox just before submit if the terms.php block is skipped entirely
        add_action('woocommerce_pay_order_before_submit', [$this, 'renderOrderPayTermsCheckboxFallback'], 5);
        // Bricks template path fallback: inject via footer if nothing rendered
        add_action('wp_footer', [$this, 'injectTermsViaFooterIfMissing'], 99);
        add_filter('woocommerce_checkout_posted_data', [$this, 'markTermsAsAcceptedOrderPay'], PHP_INT_MAX);
        add_action('woocommerce_checkout_process', [$this, 'forceDisableTermsValidationOrderPay'], PHP_INT_MAX);
        add_action('woocommerce_after_checkout_validation', [$this, 'removeTermsErrorsOrderPay'], PHP_INT_MAX, 2);
    }

    public function maybeApplyOrderPayFilters(): void
    {
        if ($this->filtersApplied || !$this->isOrderPayPage()) {
            return;
        }
        $this->filtersApplied = true;
        $this->applyOrderPayFilters();
    }

    private function applyOrderPayFilters(): void
    {
        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            // For deposit-paid orders, completely bypass terms
            add_filter('woocommerce_terms_is_checked_on_checkout', '__return_true', PHP_INT_MAX);
            add_filter('woocommerce_terms_is_checked_default', '__return_true', PHP_INT_MAX);
        }
    }

    public function handleOrderPayTerms(bool $show_terms): bool
    {
        // Only influence behavior on order-pay page
        if (!$this->isOrderPayPage()) {
            return $show_terms;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            return false; // Hide terms for deposit-paid orders
        }

        // Force showing terms on order-pay even if Woo settings would hide them
        return true;
    }

    public function customOrderPayTermsText($text)
    {
        if (!$this->isOrderPayPage()) {
            return $text;
        }

        // Get the current language code
        $current_language = apply_filters('wpml_current_language', 'en');

        // URLs for terms of service PDF
        $service_terms_urls = [
            'en' => '/wp-content/uploads/Contracte-PEC-2025_en.pdf',
            'es' => '/wp-content/uploads/Contracte-PEC-2025_es.pdf',
            'ca' => '/wp-content/uploads/Contracte-PEC-2025_ca.pdf',
        ];

        // Get the URL for the current language, or default to English
        $service_terms_url = isset($service_terms_urls[$current_language]) ? $service_terms_urls[$current_language] : $service_terms_urls['en'];
        $service_terms_link = '<a href="' . esc_url($service_terms_url) . '" target="_blank">' . __('terms of service', 'zero-sense') . '</a>';

        return $text . ' ' . __('and the', 'zero-sense') . ' ' . $service_terms_link;
    }

    /**
     * Renders a fallback Terms checkbox on order-pay when WooCommerce does not render it
     * (e.g., Terms page is not configured). Uses the same field name `terms` so posted data matches.
     */
    public function renderOrderPayTermsCheckbox(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            return; // Do not show for deposit-paid
        }

        // Mimic WooCommerce markup class names for consistency
        $text = apply_filters('woocommerce_get_terms_and_conditions_checkbox_text', __('I have read and agree to the website terms and conditions', 'woocommerce'));
        echo '<p class="form-row validate-required">';
        echo '  <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
        echo '    <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="terms" id="terms" />';
        echo '    <span>' . wp_kses_post($text) . '</span> <span class="required">*</span>';
        echo '  </label>';
        echo '</p>';
    }

    /**
     * Final fallback for themes (e.g., Bricks template mode) that skip Woo hooks entirely.
     * Injects the checkbox before #place_order via a small inline script if #terms is missing.
     */
    public function injectTermsViaFooterIfMissing(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            return;
        }

        // Build the same text used in PHP so languages/links are consistent
        $text = apply_filters('woocommerce_get_terms_and_conditions_checkbox_text', __('I have read and agree to the website terms and conditions', 'woocommerce'));
        $text = wp_kses_post($text);
        ?>
        <script>
        (function(){
          if (document.querySelector('input[name="terms"]')) return;
          var btn = document.getElementById('place_order');
          if (!btn) return;
          var p = document.createElement('p');
          p.className = 'form-row validate-required';
          p.innerHTML = '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">\
            <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="terms" id="zs-terms" />\
            <span><?php echo $text; ?></span> <span class="required">*</span>\
          </label>';
          btn.parentNode.insertBefore(p, btn);
        })();
        </script>
        <?php
    }

    /**
     * Fallback position (before submit button) in case the terms.php template early-exits
     * and our previous hook does not run. Only renders when Woo native checkbox is disabled.
     */
    public function renderOrderPayTermsCheckboxFallback(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        // If Woo would render its own checkbox, skip fallback to avoid duplicates
        if (function_exists('wc_terms_and_conditions_checkbox_enabled') && wc_terms_and_conditions_checkbox_enabled()) {
            return;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            return;
        }

        $text = apply_filters('woocommerce_get_terms_and_conditions_checkbox_text', __('I have read and agree to the website terms and conditions', 'woocommerce'));
        echo '<p class="form-row validate-required">';
        echo '  <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
        echo '    <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="terms" id="zs-terms" />';
        echo '    <span>' . wp_kses_post($text) . '</span> <span class="required">*</span>';
        echo '  </label>';
        echo '</p>';
    }

    public function markTermsAsAcceptedOrderPay(array $data): array
    {
        if (!$this->isOrderPayPage()) {
            return $data;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            $data['terms'] = 1;
            $data['terms-field'] = 1;
            $data['woocommerce_checkout_validation'] = 'terms-auto-accepted-deposit-paid-order-pay';
        }

        return $data;
    }

    public function forceDisableTermsValidationOrderPay(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            // Remove WooCommerce's terms validation hook
            // Note: Terms are already marked as accepted in markTermsAsAcceptedOrderPay()
            // and errors are cleaned up in removeTermsErrorsOrderPay()
            remove_action('woocommerce_checkout_process', 'woocommerce_checkout_terms_and_conditions');
        }
    }

    public function removeTermsErrorsOrderPay($data, $errors): void
    {
        if (!$this->isOrderPayPage() || !($errors instanceof WP_Error)) {
            return;
        }

        $order = $this->getCurrentOrderPayOrder();
        if ($order && $order->has_status('deposit-paid')) {
            $errors->remove('terms');
            
            $notices = wc_get_notices('error');
            if (empty($notices)) return;
            
            $remaining = array_filter($notices, fn($n) => !isset($n['data']['id']) || $n['data']['id'] !== 'terms');
            if (count($remaining) < count($notices)) {
                wc_clear_notices();
                foreach ($remaining as $notice) {
                    wc_add_notice($notice['notice'], 'error', $notice['data'] ?? []);
                }
            }
        }
    }

    private function getCurrentOrderPayOrder(): ?\WC_Order
    {
        if (!$this->isOrderPayPage()) {
            return null;
        }

        $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : null;
        if (!$order_key) {
            return null;
        }

        $order_id = wc_get_order_id_by_order_key($order_key);
        if (!$order_id) {
            return null;
        }

        $order = wc_get_order($order_id);
        return ($order instanceof \WC_Order) ? $order : null;
    }

    private function isOrderPayPage(): bool
    {
        return (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) ||
               (isset($_GET['pay_for_order'], $_GET['key']));
    }
}
