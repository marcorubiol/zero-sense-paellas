<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

/**
 * Changes the email verification message to remove the login option
 * and only keep the email verification option.
 */
class EmailVerificationText
{
    private string $newText;

    public function __construct()
    {
        add_filter('gettext', [$this, 'changeVerificationText'], 20, 3);
        add_action('init', [$this, 'registerWpmlString']);
        
        // Default text (will be filtered by WPML if translation exists)
        $this->newText = 'To view this page, you must verify the email address associated with the order.';
    }

    /**
     * Register string with WPML for translation
     */
    public function registerWpmlString(): void
    {
        if (function_exists('do_action')) {
            do_action(
                'wpml_register_single_string',
                'zero-sense',
                'order_pay_email_verification_text',
                $this->newText
            );
        }
    }

    /**
     * Changes the verification message text
     *
     * @param string $translation Translated text
     * @param string $text Original text
     * @param string $domain Text domain
     * @return string Modified translation
     */
    public function changeVerificationText(string $translation, string $text, string $domain): string
    {
        // Only modify WooCommerce texts
        if ($domain !== 'woocommerce') {
            return $translation;
        }

        // Target the specific verification message
        if ($text === 'To view this page, you must either %1$slogin%2$s or verify the email address associated with the order.') {
            // Get WPML translation if available, otherwise use default
            if (function_exists('apply_filters')) {
                return apply_filters(
                    'wpml_translate_single_string',
                    $this->newText,
                    'zero-sense',
                    'order_pay_email_verification_text'
                );
            }
            return $this->newText;
        }

        return $translation;
    }
}
