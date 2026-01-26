<?php
namespace ZeroSense\Features\WooCommerce\OrderPay;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\OrderPay\Components\OrderPayTermsHandler;
use ZeroSense\Features\WooCommerce\OrderPay\Components\NoticeClearing;
use ZeroSense\Features\WooCommerce\OrderPay\Components\BacsRedirects;
use ZeroSense\Features\WooCommerce\OrderPay\Components\ConditionalDisplay;
use ZeroSense\Features\WooCommerce\OrderPay\Components\PaymentButtonText;
use ZeroSense\Features\WooCommerce\OrderPay\Components\MarketingConsent;
use ZeroSense\Features\WooCommerce\OrderPay\Components\CancelOrderButton;
use ZeroSense\Features\WooCommerce\OrderPay\Components\EmailVerificationText;

class OrderPayPageEnhancements implements FeatureInterface
{
    public function getName(): string
    {
        return __('Order Pay Page Enhancements', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Always-on improvements for the Order Pay page, including conditional terms, notice clearing, and BACS redirects.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return ['defined:WC_VERSION'];
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        new OrderPayTermsHandler();
        new NoticeClearing();
        new BacsRedirects();
        new ConditionalDisplay();
        new PaymentButtonText();
        new MarketingConsent();
        new CancelOrderButton();
        new EmailVerificationText();
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Order Pay Terms Handler', 'zero-sense'),
                'items' => [
                    __('Hides terms checkbox for deposit-paid orders.', 'zero-sense'),
                    __('Custom terms text with service terms link.', 'zero-sense'),
                    __('Only applies to order-pay pages.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Notice Clearing', 'zero-sense'),
                'items' => [
                    __('Clears stale notices on order-pay page load.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('BACS Redirects', 'zero-sense'),
                'items' => [
                    __('Custom redirects for BACS deposit payments.', 'zero-sense'),
                    __('Handles first and second payment flows.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Conditional Display', 'zero-sense'),
                'items' => [
                    __('Shows payment options only for Pending/Deposit-Paid orders.', 'zero-sense'),
                    __('Shows reservation section only for Pending orders.', 'zero-sense'),
                    __('Manages Terms & Conditions UX with helper text.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Payment Button Text', 'zero-sense'),
                'items' => [
                    __('Changes button text based on order status.', 'zero-sense'),
                    __('Adds explanatory text with terms link.', 'zero-sense'),
                    __('Supports Spanish, Catalan, and English.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Marketing Consent', 'zero-sense'),
                'items' => [
                    __('Adds marketing consent checkbox to order-pay.', 'zero-sense'),
                    __('Saves consent to MetaBox field and order notes.', 'zero-sense'),
                    __('Only applies to order-pay pages.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Cancel Order Button', 'zero-sense'),
                'items' => [
                    __('Adds a Cancel Order button below the Pay button on order-pay.', 'zero-sense'),
                    __('Confirms, cancels the order via AJAX, and redirects to the Cancelled status page.', 'zero-sense'),
                    __('WPML-aware: redirects to the translated woo-status/cancelado page.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Email Verification Text', 'zero-sense'),
                'items' => [
                    __('Changes the email verification message to remove the login option.', 'zero-sense'),
                    __('Only shows "verify email" option (not "login or verify").', 'zero-sense'),
                    __('WPML-compatible via gettext filter.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/OrderPay/OrderPayPageEnhancements.php', 'zero-sense'),
                    __('Components/OrderPayTermsHandler.php', 'zero-sense'),
                    __('Components/NoticeClearing.php', 'zero-sense'),
                    __('Components/BacsRedirects.php', 'zero-sense'),
                    __('Components/ConditionalDisplay.php', 'zero-sense'),
                    __('Components/PaymentButtonText.php', 'zero-sense'),
                    __('Components/MarketingConsent.php', 'zero-sense'),
                    __('Components/CancelOrderButton.php', 'zero-sense'),
                    __('Components/EmailVerificationText.php', 'zero-sense'),
                    __('Components/AdminBarDebug.php', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('woocommerce_review_order_before_submit (consent, terms helpers)', 'zero-sense'),
                    __('woocommerce_order_button_text (button text)', 'zero-sense'),
                    __('template_redirect / is_wc_endpoint_url(\'order-pay\') guards', 'zero-sense'),
                    __('gettext (email verification text)', 'zero-sense'),
                    __('AJAX endpoint for cancel action (if present in component)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Load an order-pay URL for a Pending order → see reservation + payment blocks as configured.', 'zero-sense'),
                    __('For Deposit-Paid orders, confirm terms checkbox behavior and BACS redirect flow.', 'zero-sense'),
                    __('Button label should change per status and show helper text with link.', 'zero-sense'),
                    __('Use Cancel button → confirm AJAX cancel and redirect to cancelled page (localized).', 'zero-sense'),
                ],
            ],
        ];
    }
}
