<?php
namespace ZeroSense\Features\WooCommerce\LineItemChildren;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) { exit; }

class LineItemChildren implements FeatureInterface
{
    public function getName(): string
    {
        return 'Line Item Children';
    }

    public function getDescription(): string
    {
        return 'Per-line-item children (5-8) count for paella orders. Applies 40% discount automatically and uses per-item count for recipe calculations.';
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
        (new OrderAdmin())->register();
    }
}
