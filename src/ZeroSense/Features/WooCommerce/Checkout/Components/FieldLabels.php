<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class FieldLabels
{
    public function __construct()
    {
        // Simple approach like legacy plugin - just one hook with multiple callbacks
        add_filter('woocommerce_checkout_required_field_notice', [$this, 'customize_field_errors'], 10, 2);
    }

    /**
     * Get billing prefixes to remove from error messages
     */
    private function get_billing_prefixes_to_remove(): array
    {
        return [
            'Billing ',       // English
            'Facturación ',   // Spanish
            'Factura ',       // Catalan
        ];
    }

    /**
     * Handle error messages for required fields (simplified like legacy)
     */
    public function customize_field_errors($error, $field_label = '')
    {
        if (!is_checkout()) {
            return $error;
        }

        $prefixes_to_remove = $this->get_billing_prefixes_to_remove();
        
        // If we have a field label, clean it and reconstruct the message
        if (!empty($field_label)) {
            $field_label_modified = str_replace($prefixes_to_remove, '', $field_label);
            
            // Fallback: If stripping prefixes results in empty label, use original
            if (trim($field_label_modified) === '') {
                $field_label_modified = $field_label;
            }

            // Reconstruct the error message using the modified field label
            return sprintf(
                __('%s is a required field.', 'woocommerce'),
                '<strong>' . esc_html($field_label_modified) . '</strong>'
            );
        }
        
        // If no field label, just clean the error message directly
        return str_replace($prefixes_to_remove, '', $error);
    }
}
