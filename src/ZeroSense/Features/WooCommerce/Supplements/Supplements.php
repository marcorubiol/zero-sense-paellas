<?php
namespace ZeroSense\Features\WooCommerce\Supplements;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) { exit; }

class Supplements implements FeatureInterface
{
    public function getName(): string
    {
        return 'Auto Supplements';
    }

    public function getDescription(): string
    {
        return 'Auto-applies supplement products (servicio exclusivo de cocina, suplemento paella adicional) as real WooCommerce line items on order save.';
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
        (new SupplementManager())->register();
    }
}
