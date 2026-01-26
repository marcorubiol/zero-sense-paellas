<?php

declare(strict_types=1);

namespace ZeroSense\Features\Integrations\Bricks;

use ZeroSense\Core\FeatureInterface;

/**
 * Core helper adjustments for Bricks Builder integration.
 */
class BricksIntegration implements FeatureInterface
{
    private const ECHO_FUNCTIONS = [
        'date',
    ];

    private const ADDITIONAL_HTML_TAGS = [
        'form',
        'select',
    ];

    public function getName(): string
    {
        return __('Bricks Integration', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Optimizes Bricks Builder by allowing additional PHP functions and HTML tags needed for Zero Sense workflows and templates.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return [];
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
        if (!$this->isEnabled()) {
            return;
        }

        add_filter('bricks/code/echo_function_names', [$this, 'filterEchoFunctionNames']);
        add_filter('bricks/allowed_html_tags', [$this, 'filterAllowedHtmlTags']);
        // Frontend builder iframe
        add_action('template_redirect', [$this, 'ensureBuilderHasCart'], 1);
        // Ensure after Woo init (safe for wc_load_cart)
        add_action('woocommerce_init', [$this, 'ensureBuilderHasCart'], 20);
        // Admin screen opening builder, and builder AJAX/REST calls
        add_action('admin_init', [$this, 'ensureBuilderHasCart'], 1);
        add_action('wp', [$this, 'ensureBuilderHasCart'], 1);
    }

    /**
     * Ensure Zerø Sense helpers are available inside Bricks code elements.
     *
     * @param mixed $functionNames
     * @return array<int, string>
     */
    public function filterEchoFunctionNames($functionNames): array
    {
        $base = is_array($functionNames) ? $functionNames : [];

        foreach (self::ECHO_FUNCTIONS as $name) {
            if (!in_array($name, $base, true)) {
                $base[] = $name;
            }
        }

        return $base;
    }

    /**
     * Allow additional HTML tags required by Zerø Sense layouts.
     *
     * @param mixed $allowedTags
     * @return array<int, string>
     */
    public function filterAllowedHtmlTags($allowedTags): array
    {
        $base = is_array($allowedTags) ? $allowedTags : [];

        foreach (self::ADDITIONAL_HTML_TAGS as $tag) {
            if (!in_array($tag, $base, true)) {
                $base[] = $tag;
            }
        }

        return $base;
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
                'title' => sprintf(__('PHP functions enabled (%d)', 'zero-sense'), count(self::ECHO_FUNCTIONS)),
                'items' => $this->generatePhpFunctionsList(),
            ],
            [
                'type' => 'list',
                'title' => sprintf(__('HTML tags enabled (%d)', 'zero-sense'), count(self::ADDITIONAL_HTML_TAGS)),
                'items' => $this->generateHtmlTagsList(),
            ],
            [
                'type' => 'text',
                'title' => __('Usage', 'zero-sense'),
                'content' => __('These enhancements are automatically available in Bricks Builder code elements and templates. No additional configuration required.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Integrations/Bricks/BricksIntegration.php', 'zero-sense'),
                    __('filterEchoFunctionNames() → extends bricks/code/echo_function_names', 'zero-sense'),
                    __('filterAllowedHtmlTags() → extends bricks/allowed_html_tags', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('bricks/code/echo_function_names', 'zero-sense'),
                    __('bricks/allowed_html_tags', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('In Bricks code element, use date() to confirm it’s allowed.', 'zero-sense'),
                    __('Render <form> or <select> in a Bricks template to validate allowed tags.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => sprintf(__('Total enhancements: %d', 'zero-sense'), $this->getTotalEnhancementsCount()),
                'content' => __('All functions and tags are automatically generated from code constants. Add new items to ECHO_FUNCTIONS or ADDITIONAL_HTML_TAGS and they will appear here automatically.', 'zero-sense'),
            ],
        ];
    }

    /**
     * Generate PHP functions list from constants
     */
    private function generatePhpFunctionsList(): array
    {
        $functions = [];
        foreach (self::ECHO_FUNCTIONS as $function) {
            $functions[] = sprintf('%s() - For %s functionality in code elements', $function, ucfirst($function));
        }
        return $functions;
    }

    /**
     * Generate HTML tags list from constants
     */
    private function generateHtmlTagsList(): array
    {
        $tags = [];
        $descriptions = [
            'form' => 'For custom forms in templates',
            'select' => 'For dropdown elements',
        ];
        
        foreach (self::ADDITIONAL_HTML_TAGS as $tag) {
            $description = $descriptions[$tag] ?? 'For enhanced template functionality';
            $tags[] = sprintf('<%s> - %s', $tag, $description);
        }
        return $tags;
    }

    /**
     * Get total count of all enhancements
     */
    private function getTotalEnhancementsCount(): int
    {
        return count(self::ECHO_FUNCTIONS) + count(self::ADDITIONAL_HTML_TAGS);
    }

    public function ensureBuilderHasCart(): void
    {
        // Detect any Bricks builder context (frontend iframe, admin builder, AJAX/REST calls)
        $isBricks = false;
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            $isBricks = true;
        }
        if (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()) {
            $isBricks = true;
        }
        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            $isBricks = true;
        }
        if (isset($_GET['bricks']) || isset($_GET['brickspreview'])) {
            $isBricks = true;
        }

        if (!$isBricks) {
            return;
        }

        if (!function_exists('WC') || !function_exists('wc_load_cart')) {
            return;
        }

        $wc = WC();
        if (!$wc || (isset($wc->cart) && is_object($wc->cart))) {
            return;
        }

        // Load cart safely (this method is also called from woocommerce_init hook)
        wc_load_cart();
    }
}
