<?php
namespace ZeroSense\Features\WordPress;

use ZeroSense\Core\FeatureInterface;

/**
 * Responsive Image Sizes
 *
 * Registers a curated set of responsive image sizes and exposes them in the
 * media modal. All sizes are filterable for child themes or future needs.
 */
class ResponsiveImageSizes implements FeatureInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return __('Responsive Image Sizes', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return __('Registers optimized responsive image sizes for different screen resolutions and makes them available in the WordPress media modal.', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getCategory(): string
    {
        return 'WordPress';
    }

    /**
     * {@inheritdoc}
     */
    public function isToggleable(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * {@inheritdoc}
     */
    public function getConditions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        add_action('after_setup_theme', [$this, 'registerImageSizes'], 20);
        add_filter('image_size_names_choose', [$this, 'registerMediaModalLabels']);
    }

    /**
     * Register responsive image sizes.
     */
    public function registerImageSizes(): void
    {
        if (!current_theme_supports('post-thumbnails')) {
            add_theme_support('post-thumbnails');
        }

        foreach ($this->getImageSizes() as $name => $settings) {
            [$width, $height, $crop] = $this->normaliseSizeDefinition($settings);

            if ($width <= 0) {
                continue;
            }

            add_image_size($name, $width, $height, $crop);
        }
    }

    /**
     * Register size labels in the Add Media modal.
     */
    public function registerMediaModalLabels(array $sizes): array
    {
        $labels = [];
        foreach (array_keys($this->getImageSizes()) as $name) {
            $labels[$name] = apply_filters(
                'zero_sense_image_size_label',
                ucwords(str_replace(['-', '_'], ' ', $name)),
                $name
            );
        }

        return array_merge($sizes, $labels);
    }

    /**
     * Retrieve the responsive image sizes definition.
     */
    private function getImageSizes(): array
    {
        return apply_filters('zero_sense_image_sizes', [
            'image-480' => [480, 999, false],
            'image-640' => [640, 999, false],
            'image-720' => [720, 999, false],
            'image-960' => [960, 999, false],
            'image-1168' => [1168, 999, false],
            'image-1440' => [1440, 999, false],
            'image-1920' => [1920, 999, false],
        ]);
    }

    /**
     * Normalise the size definition into width, height, crop.
     *
     * @param mixed $settings
     */
    private function normaliseSizeDefinition($settings): array
    {
        if (!is_array($settings)) {
            return [(int) $settings, 999, false];
        }

        $width = isset($settings['width']) ? (int) $settings['width'] : (int) ($settings[0] ?? 0);
        $height = isset($settings['height']) ? (int) $settings['height'] : (int) ($settings[1] ?? 999);
        $crop = isset($settings['crop']) ? (bool) $settings['crop'] : (bool) ($settings[2] ?? false);

        if ($height <= 0) {
            $height = 999;
        }

        return [$width, $height, $crop];
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
                'title' => __('Added image sizes', 'zero-sense'),
                'items' => [
                    __('480px × 999px (mobile)', 'zero-sense'),
                    __('640px × 999px (small tablet)', 'zero-sense'),
                    __('720px × 999px (tablet)', 'zero-sense'),
                    __('960px × 999px (small desktop)', 'zero-sense'),
                    __('1168px × 999px (desktop)', 'zero-sense'),
                    __('1440px × 999px (large desktop)', 'zero-sense'),
                    __('1920px × 999px (full HD)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('Proportional scaling without cropping', 'zero-sense'),
                    __('Automatic post-thumbnails support', 'zero-sense'),
                    __('Available in media library for selection', 'zero-sense'),
                    __('Optimized for responsive design', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Usage', 'zero-sense'),
                'content' => __('Select these sizes when inserting images or use them programmatically with WordPress image functions for optimal performance across devices.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WordPress/ResponsiveImageSizes.php', 'zero-sense'),
                    __('registerImageSizes() → registers sizes via add_image_size', 'zero-sense'),
                    __('registerMediaModalLabels() → exposes sizes in media modal', 'zero-sense'),
                    __('getImageSizes() → filterable size definitions (zero_sense_image_sizes)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('after_setup_theme (size registration)', 'zero-sense'),
                    __('image_size_names_choose (media modal labels)', 'zero-sense'),
                    __('zero_sense_image_sizes (filter sizes)', 'zero-sense'),
                    __('zero_sense_image_size_label (filter labels)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Open Media → Add new image to regenerate sizes (use a Regenerate Thumbnails tool if needed).', 'zero-sense'),
                    __('Insert image in editor and confirm new sizes appear in the dropdown.', 'zero-sense'),
                    __('Override sizes via zero_sense_image_sizes filter in theme to adjust widths as required.', 'zero-sense'),
                ],
            ],
        ];
    }
}
