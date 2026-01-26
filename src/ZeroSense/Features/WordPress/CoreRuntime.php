<?php
namespace ZeroSense\Features\WordPress;

use ZeroSense\Core\FeatureInterface;

class CoreRuntime implements FeatureInterface
{
    public function getName(): string
    {
        return __('Core Runtime', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Always-on plugin runtime wiring (HPOS compatibility, dev tools visibility).', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WordPress';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 1;
    }

    public function getConditions(): array
    {
        return [];
    }

    public function init(): void
    {
        add_action('before_woocommerce_init', function (): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ZERO_SENSE_FILE, true);
            }
        });
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        $isDev = defined('ZERO_SENSE_VERSION') && strpos((string) ZERO_SENSE_VERSION, '-dev') !== false;

        return [
            [
                'type' => 'list',
                'title' => __('Core runtime', 'zero-sense'),
                'items' => [
                    __('HPOS compatibility declared on before_woocommerce_init (custom_order_tables).', 'zero-sense'),
                    __('Feature auto-discovery enabled (FeatureManager scans src/ZeroSense/Features/*).', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Environment', 'zero-sense'),
                'items' => [
                    'ZERO_SENSE_VERSION=' . (defined('ZERO_SENSE_VERSION') ? (string) ZERO_SENSE_VERSION : 'undefined'),
                    'WP_DEBUG=' . (defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'),
                    'DEV build=' . ($isDev ? 'true' : 'false'),
                ],
            ],
        ];
    }
}
