<?php
namespace ZeroSense\Features\Utilities;

use ZeroSense\Core\FeatureInterface;

/**
 * Automatic CSS Variables
 * 
 * Loads CSS variables for consistent styling across admin and frontend.
 * Automatically detects and loads CSS variable files when they exist.
 */
class CssVariables implements FeatureInterface
{
    /**
     * Get feature name
     */
    public function getName(): string
    {
        return __('Automatic CSS Variables', 'zero-sense');
    }

    /**
     * Get feature description
     */
    public function getDescription(): string
    {
        return __('Automatically detects and loads CSS variables from your uploads directory to ensure consistent styling across admin and login pages.', 'zero-sense');
    }

    /**
     * Get feature category
     */
    public function getCategory(): string
    {
        return 'Utilities';
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

    public function getOptionName(): string
    {
        return 'zs_utilities_css_variables_enable';
    }

    /**
     * Get feature priority
     */
    public function getPriority(): int
    {
        return 1; // Load early
    }

    /**
     * Get conditions for loading this feature
     */
    public function getConditions(): array
    {
        return []; // Load everywhere
    }

    /**
     * Initialize the feature
     */
    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueueCssVariables']);
        add_action('login_enqueue_scripts', [$this, 'enqueueCssVariables']);
    }

    public function enqueueCssVariables(): void
    {
        $asset = $this->resolveAsset();

        if ($asset === null) {
            return;
        }

        wp_enqueue_style($asset['handle'], $asset['url'], [], $asset['version']);
    }

    /**
     * @return array{handle:string,url:string,version:?string}|null
     */
    private function resolveAsset(): ?array
    {
        $uploadDir = wp_upload_dir();

        if (empty($uploadDir['basedir']) || empty($uploadDir['baseurl'])) {
            return null;
        }

        $relativePath = 'automatic-css/automatic-variables.css';
        $absolutePath = trailingslashit($uploadDir['basedir']) . $relativePath;

        if (!file_exists($absolutePath)) {
            return null;
        }

        $url = trailingslashit($uploadDir['baseurl']) . $relativePath;
        $version = @filemtime($absolutePath);

        return [
            'handle' => 'zero-sense-automaticcss-variables',
            'url' => $url,
            'version' => $version ? (string) $version : null,
        ];
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
                'type' => 'text',
                'title' => __('File location', 'zero-sense'),
                'content' => __('Looks for: wp-content/uploads/automatic-css/automatic-variables.css', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('Automatic file detection - only loads if file exists', 'zero-sense'),
                    __('Cache-busting using file modification time', 'zero-sense'),
                    __('Loads on admin and login pages only', 'zero-sense'),
                    __('High priority loading for early availability', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Integration', 'zero-sense'),
                'content' => __('Works seamlessly with Automatic CSS plugin or any custom CSS variables file placed in the expected location.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Utilities/CssVariables.php', 'zero-sense'),
                    __('enqueueCssVariables() → enqueues resolved CSS asset', 'zero-sense'),
                    __('resolveAsset() → locates file in uploads and builds URL/version', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('admin_enqueue_scripts', 'zero-sense'),
                    __('login_enqueue_scripts', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Create file at uploads/automatic-css/automatic-variables.css and refresh admin/login; verify stylesheet loads.', 'zero-sense'),
                    __('Change file timestamp and confirm version query updates (cache bust).', 'zero-sense'),
                    __('Remove file to ensure no enqueue occurs (silent).', 'zero-sense'),
                ],
            ],
        ];
    }
}
