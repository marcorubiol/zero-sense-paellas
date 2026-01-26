<?php

declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

/**
 * WooCommerce · Empty Cart via URL parameter
 *
 * Usage: add `?zs_empty_cart=1` to any site URL. Example:
 * echo add_query_arg('zs_empty_cart', '1', wc_get_cart_url());
 */
class EmptyCartLink implements FeatureInterface
{
    public function getName(): string
    {
        return __('Empty Cart via URL', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Allows emptying the WooCommerce cart via the URL parameter `zs_empty_cart=1` and redirects to the cart page.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false; // Simple, always-on utility
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 9; // run early but after core
    }

    public function getConditions(): array
    {
        return ['defined:WC_VERSION'];
    }

    public function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('init', [$this, 'maybeEmptyCart']);
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'code',
                'title' => __('How to create the link', 'zero-sense'),
                'content' => '<?php echo add_query_arg(\'zs_empty_cart\', \'1\', wc_get_cart_url()); ?>',
            ],
            [
                'type' => 'text',
                'title' => __('Behavior', 'zero-sense'),
                'content' => __('When visiting any URL with `?zs_empty_cart=1`, the cart is emptied and the user is redirected to the WooCommerce cart page.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/EmptyCartLink.php', 'zero-sense'),
                    __('maybeEmptyCart() → checks param, ensures session, empties cart, redirects', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('init (wires maybeEmptyCart)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Open wc_get_cart_url() with ?zs_empty_cart=1 and confirm cart becomes empty.', 'zero-sense'),
                    __('Validate redirect to the cart page and no PHP notices (session ensured).', 'zero-sense'),
                ],
            ],
        ];
    }

    /**
     * Empty the cart and redirect when the URL param is present.
     */
    public function maybeEmptyCart(): void
    {
        if (!isset($_GET['zs_empty_cart'])) {
            return;
        }

        if (!function_exists('WC') || !isset(WC()->cart)) {
            return;
        }

        // Ensure WC session exists to avoid notices
        if (isset(WC()->session) && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        WC()->cart->empty_cart();

        // Always redirect to cart URL after emptying
        if (function_exists('wc_get_cart_url')) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }
}
