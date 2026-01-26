<?php
namespace ZeroSense\Features\WordPress;

use ZeroSense\Core\FeatureInterface;

/**
 * Hide Admin Bar for Non-Admins
 * 
 * Hides the WordPress admin bar for non-administrator users on the frontend.
 * This provides a cleaner frontend experience for regular users.
 */
class HideAdminBar implements FeatureInterface
{
    /**
     * Get feature name
     */
    public function getName(): string
    {
        return __('Hide Admin Bar for Non-Admins', 'zero-sense');
    }
    /**
     * Get feature description
     */
    public function getDescription(): string
    {
        return __('Automatically hides the WordPress admin bar for non-admin users while preserving it for administrators, improving frontend user experience and reducing visual clutter.', 'zero-sense');
    }

    /**
     * Get feature category
     */
    public function getCategory(): string
    {
        return 'WordPress';
    }

    /**
     * Check if feature is toggleable
     */
    public function isToggleable(): bool
    {
        return true;
    }

    /**
     * Check if feature is enabled
     */
    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    /**
     * Option name for toggle state
     */
    public function getOptionName(): string
    {
        return 'zs_wordpress_hide_admin_bar';
    }

    /**
     * Get feature priority
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * Get conditions for loading this feature
     */
    public function getConditions(): array
    {
        return ['!is_admin']; // Only load on frontend
    }

    /**
     * Initialize the feature
     */
    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Hide admin bar for non-admins
        add_action('after_setup_theme', [$this, 'hideAdminBarForNonAdmins']);
    }

    /**
     * Hide admin bar for non-administrator users
     */
    public function hideAdminBarForNonAdmins(): void
    {
        if (!current_user_can('manage_options')) {
            show_admin_bar(false);
        }
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
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('Only affects non-administrator users', 'zero-sense'),
                    __('Works exclusively on frontend pages', 'zero-sense'),
                    __('Preserves admin bar for administrators', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Technical details', 'zero-sense'),
                'content' => __('Uses WordPress show_admin_bar(false) function and checks for manage_options capability to determine user permissions.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WordPress/HideAdminBar.php', 'zero-sense'),
                    __('hideAdminBarForNonAdmins() → runs on after_setup_theme', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('after_setup_theme (initialization point)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Login with a non-admin user and visit the frontend → admin bar should be hidden.', 'zero-sense'),
                    __('Login as admin → admin bar should remain visible.', 'zero-sense'),
                ],
            ],
        ];
    }
}
