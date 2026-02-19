<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Gateways;

use ZeroSense\Core\FeatureInterface;

class Gateways implements FeatureInterface
{
    public function getName(): string
    {
        return __('WooCommerce Payment Gateways (Redsys)', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Registers Redsys gateways (Card, Bizum, Deposits) and shares common helpers.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false; // Core integration, always on
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function init(): void
    {
        // Register gateways through WooCommerce filter
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateways']);
    }

    /**
     * @param array $methods
     * @return array
     */
    public function registerGateways(array $methods): array
    {
        // Redsys (Card)
        if (class_exists(RedsysStandard::class)) {
            $methods[] = RedsysStandard::class;
        }
        // Redsys (Bizum)
        if (class_exists(RedsysBizum::class)) {
            $methods[] = RedsysBizum::class;
        }
        return $methods;
    }

    /**
     * Check if feature has information
     */
    public function hasInformation(): bool
    {
        return true;
    }

    /**
     * Get information blocks for dashboard
     */
    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/Gateways/Gateways.php', 'zero-sense'),
                    __('Registers: RedsysStandard, RedsysBizum, Deposits Redsys Gateway (if classes exist)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('woocommerce_payment_gateways (registration point)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('In WooCommerce → Payments, verify Redsys Card/Bizum and Deposits gateway appear when plugins/classes are present.', 'zero-sense'),
                    __('Toggle gateways and place test orders to validate availability.', 'zero-sense'),
                ],
            ],
        ];
    }
}
