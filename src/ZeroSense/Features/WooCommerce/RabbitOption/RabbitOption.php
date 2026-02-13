<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) { exit; }

class RabbitOption implements FeatureInterface
{
    public function getName(): string
    {
        return __('Rabbit Option', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Per-product rabbit preference. Adds a with/without rabbit choice on the product page, carries it through cart to order, and displays it clearly in admin.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
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
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function init(): void
    {
        (new Bootstrap())->boot();
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
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('Checkbox in product General tab to enable rabbit option', 'zero-sense'),
                    __('Radio buttons (With/Without rabbit) on single product page', 'zero-sense'),
                    __('Badge on shop loop for rabbit-eligible products', 'zero-sense'),
                    __('Choice stored as order item meta, visible in admin and emails', 'zero-sense'),
                    __('Default: With rabbit', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/RabbitOption/', 'zero-sense'),
                    __('Components: ProductAdmin, ProductDisplay, CartIntegration, OrderAdmin', 'zero-sense'),
                    __('Support: MetaKeys (_zs_has_rabbit_option, _zs_rabbit_choice)', 'zero-sense'),
                ],
            ],
        ];
    }
}
