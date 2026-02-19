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
        add_action('admin_menu', [$this, 'removeDefaultSubmenu'], 999);
        add_action('admin_menu', [$this, 'reorderSubmenu'], 1000);
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
            '__return_null',
            'dashicons-admin-tools',
            56
        );
    }
    
    public function removeDefaultSubmenu(): void
    {
        remove_submenu_page('event-operations', 'event-operations');
    }

    public function reorderSubmenu(): void
    {
        global $submenu;

        if (empty($submenu['event-operations'])) {
            return;
        }

        $desired = ['zs-stock-alerts'];
        $first   = [];
        $rest    = [];

        foreach ($submenu['event-operations'] as $item) {
            $slug = $item[2] ?? '';
            if (in_array($slug, $desired, true)) {
                $first[$slug] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $ordered = [];
        foreach ($desired as $slug) {
            if (isset($first[$slug])) {
                $ordered[] = $first[$slug];
            }
        }

        $submenu['event-operations'] = array_merge($ordered, $rest);
    }
}
