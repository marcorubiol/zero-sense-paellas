<?php
namespace ZeroSense\Features\Operations;

use ZeroSense\Core\FeatureInterface;

class EventOperationsMenu implements FeatureInterface
{
    public function getName(): string
    {
        return __('Event Operations Menu', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Creates the Event Operations top-level menu for staff, materials, and operational tools.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Operations';
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
        add_action('admin_menu', [$this, 'registerMenu'], 5);
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function getConditions(): array
    {
        return ['is_admin'];
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Event Operations', 'zero-sense'),
            __('Event Operations', 'zero-sense'),
            'manage_woocommerce',
            'event-operations',
            [$this, 'renderDashboard'],
            'dashicons-calendar-alt',
            56
        );

        add_submenu_page(
            'event-operations',
            __('Dashboard', 'zero-sense'),
            __('Dashboard', 'zero-sense'),
            'manage_woocommerce',
            'event-operations',
            [$this, 'renderDashboard']
        );
    }

    public function renderDashboard(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Event Operations', 'zero-sense'); ?></h1>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Welcome to Event Operations', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('Manage all operational aspects of your events from this central hub.', 'zero-sense'); ?></p>
                
                <h3><?php esc_html_e('Available Tools', 'zero-sense'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('Staff Members', 'zero-sense'); ?>:</strong> <?php esc_html_e('Manage your event staff and assign them to orders', 'zero-sense'); ?></li>
                    <li><strong><?php esc_html_e('Material & Logistics', 'zero-sense'); ?>:</strong> <?php esc_html_e('Configure material and logistics fields for orders', 'zero-sense'); ?></li>
                    <li><strong><?php esc_html_e('Work Experience & Access', 'zero-sense'); ?>:</strong> <?php esc_html_e('Manage work experience settings', 'zero-sense'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
}
