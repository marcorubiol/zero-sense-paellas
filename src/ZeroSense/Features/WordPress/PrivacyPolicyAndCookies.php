<?php
namespace ZeroSense\Features\WordPress;

use ZeroSense\Core\FeatureInterface;

/**
 * Privacy Policy & Must-Have Cookie Compatibility
 *
 * Enhances the privacy policy dropdown to include the `legal-page` post type,
 * keeps privacy URLs working with WPML and WooCommerce, and ensures the
 * Must-Have Cookie service worker leaves crucial AJAX endpoints untouched.
 */
class PrivacyPolicyAndCookies implements FeatureInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return __('Privacy Policy & Cookie Compatibility', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return __('Enhances privacy policy management by extending the WordPress privacy policy selector to include legal pages and ensures cookie compliance plugins don\'t break AJAX functionality.', 'zero-sense');
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
        return 15;
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
        add_filter('wp_dropdown_pages', [$this, 'filterPrivacyPolicyDropdown'], 10, 2);
        add_filter('privacy_policy_url', [$this, 'filterPrivacyPolicyUrl'], 10, 2);

        if (class_exists('WooCommerce')) {
            add_filter('woocommerce_get_privacy_policy_url', [$this, 'filterPrivacyPolicyUrl'], 10, 2);
        }

        add_filter('mhcookie_sw_exclude_urls', [$this, 'excludeMustHaveCookieUrls']);
    }

    /**
     * Inject legal-page CPT entries into the privacy policy dropdown UI.
     */
    public function filterPrivacyPolicyDropdown(string $output, array $args): string
    {
        if (!isset($args['name']) || $args['name'] !== 'page_for_privacy_policy') {
            return $output;
        }

        $legalPages = $this->getLegalPages();
        if (empty($legalPages)) {
            return $output;
        }

        $closingPosition = strrpos($output, '</select>');
        if ($closingPosition === false) {
            return $output;
        }

        $options = '';
        foreach ($legalPages as $page) {
            $selected = selected((int) get_option('wp_page_for_privacy_policy'), $page->ID, false);
            $options .= sprintf(
                '<option value="%1$d" %2$s>%3$s</option>',
                $page->ID,
                $selected,
                esc_html($page->post_title . ' (Legal Page)')
            );
        }

        return substr_replace($output, $options, $closingPosition, 0);
    }

    /**
     * Ensure privacy policy URLs point to legal-page entries when selected.
     */
    public function filterPrivacyPolicyUrl(string $url, int $pageId): string
    {
        if ($this->isLegalPage($pageId)) {
            $permalink = get_permalink($pageId);
            if ($permalink) {
                return $permalink;
            }
        }

        return $url;
    }

    /**
     * Keep Must-Have Cookie from intercepting AJAX and heavy media routes.
     */
    public function excludeMustHaveCookieUrls(array $urls): array
    {
        $defaults = [
            '/wp-admin/admin-ajax.php',
            '/wp-content/uploads/*.mp4',
            '/wp-content/uploads/*.webm',
            '/wp-content/uploads/*.mov',
            '/wp-content/uploads/*.avi',
            '/wp-content/uploads/Paella-Bogavante.mp4',
        ];

        return array_values(array_unique(array_merge($urls, $defaults)));
    }

    /**
     * Retrieve published legal pages, respecting WPML when active.
     *
     * @return array<int, \WP_Post>
     */
    private function getLegalPages(): array
    {
        $args = [
            'post_type' => 'legal-page',
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
            'posts_per_page' => -1,
            'suppress_filters' => false,
        ];

        if (defined('ICL_SITEPRESS_VERSION')) {
            $language = apply_filters('wpml_current_language', null);
            if (!empty($language) && is_string($language)) {
                $args['lang'] = $language;
            }
        }

        return get_posts($args);
    }

    /**
     * Determine whether a given page ID is a legal-page CPT entry.
     */
    private function isLegalPage(int $pageId): bool
    {
        $post = get_post($pageId);
        return $post instanceof \WP_Post && $post->post_type === 'legal-page';
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
                'title' => __('Privacy policy enhancements', 'zero-sense'),
                'items' => [
                    __('Adds legal-page post type to privacy policy dropdown', 'zero-sense'),
                    __('WPML integration for multilingual legal pages', 'zero-sense'),
                    __('WooCommerce privacy policy URL compatibility', 'zero-sense'),
                    __('Automatic permalink generation for legal pages', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Cookie plugin compatibility', 'zero-sense'),
                'items' => [
                    __('Excludes admin-ajax.php from Must-Have Cookie blocking', 'zero-sense'),
                    __('Protects video files from service worker interference', 'zero-sense'),
                    __('Maintains AJAX functionality for cart and checkout', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Integration', 'zero-sense'),
                'content' => __('Works with legal-page custom post type, WPML multilingual plugin, WooCommerce, and Must-Have Cookie compliance plugin.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WordPress/PrivacyPolicyAndCookies.php', 'zero-sense'),
                    __('filterPrivacyPolicyDropdown() → injects legal-page options into selector', 'zero-sense'),
                    __('filterPrivacyPolicyUrl() → resolves permalink for legal-page IDs', 'zero-sense'),
                    __('excludeMustHaveCookieUrls() → augments SW exclude list', 'zero-sense'),
                    __('getLegalPages() / isLegalPage() → CPT helpers with WPML support', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('wp_dropdown_pages (privacy selector UI)', 'zero-sense'),
                    __('privacy_policy_url (core URL resolver)', 'zero-sense'),
                    __('woocommerce_get_privacy_policy_url (WC URL resolver)', 'zero-sense'),
                    __('mhcookie_sw_exclude_urls (service worker exclude list)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Settings → Privacy → confirm legal-page entries appear in selector and save one.', 'zero-sense'),
                    __('Visit privacy URL on front; ensure it points to selected legal-page (with WPML language).', 'zero-sense'),
                    __('If Must-Have Cookie is active, verify AJAX still works (cart/checkout) and videos are not blocked.', 'zero-sense'),
                ],
            ],
        ];
    }
}
