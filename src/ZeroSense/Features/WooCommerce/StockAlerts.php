<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components\AlertsDashboardPage;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components\AlertsAdminNotice;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components\OrderAlertsColumn;

class StockAlerts implements FeatureInterface
{
    public function getName(): string
    {
        return __('Stock Alerts', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Monitor equipment inventory and prevent stock shortages across events', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Register all Stock Alert components
        (new AlertsDashboardPage())->register();
        (new AlertsAdminNotice())->register();
        (new OrderAlertsColumn())->register();
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin'];
    }

    public function getOptionName(): string
    {
        return 'zs_stock_alerts_enabled';
    }
}
