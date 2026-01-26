<?php

namespace ZeroSense\Features\Utilities;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) {
    exit;
}

class OrderEventDateFormatter implements FeatureInterface
{
    public function getName(): string
    {
        return __('Order Event Date Formatter', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Loads the reusable event date helper for consistent dd/mm/YYYY formatting across admin screens.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Utilities';
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
        return 5;
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WooCommerce'];
    }

    public function init(): void
    {
        require_once __DIR__ . '/helpers/order-event-date-formatter.php';
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
                'title' => __('Key details', 'zero-sense'),
                'items' => [
                    __('Defines `zs_format_event_date_for_admin()` for consistent dd/mm/YYYY output.', 'zero-sense'),
                    __('Covers raw timestamps and free-form strings with DateTime fallback.', 'zero-sense'),
                    __('Used by order list columns and other admin displays.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Utilities/OrderEventDateFormatter.php', 'zero-sense'),
                    __('Helper: src/ZeroSense/Features/Utilities/helpers/order-event-date-formatter.php', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Load WooCommerce → Orders to confirm Event Date column renders formatted values.', 'zero-sense'),
                    __('Edit an order, adjust `event_date` meta (timestamp or string) and confirm formatting persists.', 'zero-sense'),
                ],
            ],
        ];
    }
}
