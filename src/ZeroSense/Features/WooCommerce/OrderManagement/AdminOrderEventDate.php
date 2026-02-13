<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) { exit; }

class AdminOrderEventDate implements FeatureInterface
{
    private const META_EVENT_DATE = 'zs_event_date';
    private const META_EVENT_DATE_LEGACY = 'event_date';

    public function getName(): string
    {
        return __('Orders: Event Date Column', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a sortable Event Date column to WooCommerce Orders with filters (All/Future/Past) and robust dd/mm/YYYY formatting. Works with classic CPT and HPOS.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_admin_order_event_date';
    }

    public function isEnabled(): bool
    {
        // Default ON, cast to bool to respect dashboard toggle semantics
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Column registration (classic + HPOS)
        add_filter('manage_edit-shop_order_columns', [$this, 'registerColumn'], 9999);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'registerColumn'], 9999);

        // Prime meta cache for classic screen to avoid N queries
        add_filter('the_posts', [$this, 'primeEventDateMetaCache'], 10, 2);

        // Enqueue fixer JS on orders screens
        add_action('admin_enqueue_scripts', [$this, 'enqueueFixerScript']);

        // Renderers
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderClassic'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderHpos'], 10, 2);

        // Sortable flags
        add_filter('manage_edit-shop_order_sortable_columns', [$this, 'makeSortableClassic'], 10);
        add_filter('woocommerce_shop_order_list_table_sortable_columns', [$this, 'makeSortableHpos'], 10);

        // Sorting implementation (classic)
        add_action('pre_get_posts', [$this, 'handleClassicSorting'], 10);

        // Sorting implementation (HPOS)
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'handleHposSorting'], 10);

        // Filter dropdowns
        add_action('restrict_manage_posts', [$this, 'renderClassicFilter'], 10);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'renderHposFilter'], 10);
    }

    public function registerColumn(array $columns): array
    {
        if (!isset($columns['event_date'])) {
            $columns['event_date'] = __('Event Date', 'zero-sense');
        }
        return $columns;
    }

    public function primeEventDateMetaCache($posts, $query)
    {
        if (!is_admin() || !$query->is_main_query() || empty($posts)) {
            return $posts;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return $posts;
        }
        $post_ids = wp_list_pluck($posts, 'ID');
        if (!empty($post_ids)) {
            update_meta_cache('post', $post_ids);
        }
        return $posts;
    }

    public function enqueueFixerScript(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            $script_rel = 'assets/js/admin-order-event-date-fixer.js';
            if (defined('ZERO_SENSE_PATH') && file_exists(constant('ZERO_SENSE_PATH') . $script_rel)) {
                $url = defined('ZERO_SENSE_URL') ? constant('ZERO_SENSE_URL') . $script_rel : '';
                $ver = filemtime(constant('ZERO_SENSE_PATH') . $script_rel);
                if ($url) {
                    wp_enqueue_script('zero-sense-event-date-fixer', $url, [], $ver, true);
                }
            }
        }
    }

    public function renderClassic(string $column, int $post_id): void
    {
        if ($column !== 'event_date') {
            return;
        }
        $raw = get_post_meta($post_id, self::META_EVENT_DATE, true);
        if ($raw === '' || $raw === null) {
            $raw = get_post_meta($post_id, self::META_EVENT_DATE_LEGACY, true);
        }
        $formatted = self::formatEventDateForAdmin($raw);
        echo '<span class="zs-event-date" data-timestamp="' . esc_attr($raw) . '">' . esc_html($formatted) . '</span>';
    }

    public function renderHpos(string $column, $order): void
    {
        if ($column !== 'event_date') {
            return;
        }
        if ($order && is_object($order) && method_exists($order, 'get_meta')) {
            $raw = $order->get_meta(self::META_EVENT_DATE, true);
            if ($raw === '' || $raw === null) {
                $raw = $order->get_meta(self::META_EVENT_DATE_LEGACY, true);
            }
            $formatted = self::formatEventDateForAdmin($raw);
            echo '<span class="zs-event-date" data-timestamp="' . esc_attr($raw) . '">' . esc_html($formatted) . '</span>';
        }
    }

    public function makeSortableClassic(array $columns): array
    {
        $columns['event_date'] = 'event_date';
        return $columns;
    }

    public function makeSortableHpos(array $columns): array
    {
        $columns['event_date'] = 'event_date';
        return $columns;
    }

    public function handleClassicSorting($query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return;
        }

        $filter = isset($_GET['zs_event_date']) ? sanitize_text_field($_GET['zs_event_date']) : 'future';
        $today_ts = (string) strtotime(current_time('Y-m-d'));

        if (in_array($filter, ['future', 'past'], true)) {
            $cmp = $filter === 'future' ? '>=' : '<';
            $query->set('meta_query', [
                ['key' => self::META_EVENT_DATE, 'value' => $today_ts, 'compare' => $cmp, 'type' => 'NUMERIC'],
            ]);

            $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
            if ($orderby === '' || $orderby === 'event_date') {
                $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : '';
                $order = in_array($order, ['ASC', 'DESC'], true) ? $order : ($filter === 'future' ? 'ASC' : 'DESC');
                $query->set('meta_key', self::META_EVENT_DATE);
                $query->set('orderby', 'meta_value_num');
                $query->set('order', $order);
            }
            return;
        }

        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        if ($orderby === 'event_date') {
            $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
            $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
            $query->set('meta_key', self::META_EVENT_DATE);
            $query->set('orderby', 'meta_value_num');
            $query->set('order', $order);
        }
    }

    public function handleHposSorting(array $args): array
    {
        $filter = isset($_GET['zs_event_date']) ? sanitize_text_field($_GET['zs_event_date']) : 'future';
        $today_ts = (string) strtotime(current_time('Y-m-d'));

        if (in_array($filter, ['future', 'past'], true)) {
            $cmp = $filter === 'future' ? '>=' : '<';
            $mq = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
            $mq[] = ['key' => self::META_EVENT_DATE, 'value' => $today_ts, 'compare' => $cmp, 'type' => 'NUMERIC'];
            $args['meta_query'] = $mq;

            $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
            if ($orderby === '' || $orderby === 'event_date') {
                $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : '';
                $order = in_array($order, ['ASC', 'DESC'], true) ? $order : ($filter === 'future' ? 'ASC' : 'DESC');
                $args['orderby'] = 'ID';
                $args['order']   = $order;
            }
            return $args;
        }

        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
        if ($orderby === 'event_date') {
            $order = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
            $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
            $args['meta_key'] = self::META_EVENT_DATE;
            $args['orderby']  = 'meta_value_num';
            $args['order']    = $order;
        }
        return $args;
    }

    public function renderClassicFilter(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return;
        }
        $current = isset($_GET['zs_event_date']) ? sanitize_text_field($_GET['zs_event_date']) : 'future';
        echo '<select name="zs_event_date" id="zs_event_date" class="wc-enhanced-select">';
        echo '<option value=""' . selected($current, '', false) . '>' . esc_html__('All events', 'zero-sense') . '</option>';
        echo '<option value="future"' . selected($current, 'future', false) . '>' . esc_html__('Future events', 'zero-sense') . '</option>';
        echo '<option value="past"' . selected($current, 'past', false) . '>' . esc_html__('Past events', 'zero-sense') . '</option>';
        echo '</select>';
    }

    public function renderHposFilter(): void
    {
        $current = isset($_GET['zs_event_date']) ? sanitize_text_field($_GET['zs_event_date']) : 'future';
        echo '<select name="zs_event_date" id="zs_event_date" class="wc-enhanced-select">';
        echo '<option value=""' . selected($current, '', false) . '>' . esc_html__('All events', 'zero-sense') . '</option>';
        echo '<option value="future"' . selected($current, 'future', false) . '>' . esc_html__('Future events', 'zero-sense') . '</option>';
        echo '<option value="past"' . selected($current, 'past', false) . '>' . esc_html__('Past events', 'zero-sense') . '</option>';
        echo '</select>';
    }

    private static function formatEventDateForAdmin($value): string
    {
        if (empty($value)) {
            return '-';
        }

        // Legacy numeric timestamp
        if (is_numeric($value) && (int) $value > 0) {
            return date_i18n('d/m/Y', (int) $value);
        }

        if (!is_string($value)) {
            return '-';
        }

        // YYYY-MM-DD → dd/mm/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $value;
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
                    __('Sortable "Event Date" column in orders (classic + HPOS)', 'zero-sense'),
                    __('Filter by Future/Past/All events from list table', 'zero-sense'),
                    __('Robust dd/mm/YYYY formatting from timestamp or string', 'zero-sense'),
                    __('Meta priming to avoid N+1 queries on classic screen', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/OrderManagement/AdminOrderEventDate.php', 'zero-sense'),
                    __('registerColumn() / renderClassic() / renderHpos()', 'zero-sense'),
                    __('makeSortableClassic() / makeSortableHpos()', 'zero-sense'),
                    __('handleClassicSorting() / handleHposSorting()', 'zero-sense'),
                    __('renderClassicFilter() / renderHposFilter()', 'zero-sense'),
                    __('formatEventDateForAdmin() → wrapper for `zs_format_event_date_for_admin()` helper (Utilities feature)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('manage_edit-shop_order_columns, woocommerce_shop_order_list_table_columns', 'zero-sense'),
                    __('manage_shop_order_posts_custom_column, woocommerce_shop_order_list_table_custom_column', 'zero-sense'),
                    __('manage_edit-shop_order_sortable_columns, woocommerce_shop_order_list_table_sortable_columns', 'zero-sense'),
                    __('pre_get_posts (classic sorting/filter), woocommerce_shop_order_list_table_query_args (HPOS)', 'zero-sense'),
                    __('restrict_manage_posts, woocommerce_shop_order_list_table_restrict_manage_orders', 'zero-sense'),
                    __('admin_enqueue_scripts (fixer script)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Go to Orders list: confirm "Event Date" column appears and sorts ASC/DESC.', 'zero-sense'),
                    __('Use filter dropdown to switch between Future/Past/All and verify results.', 'zero-sense'),
                    __('Open an order and ensure event_date meta is displayed formatted (dd/mm/YYYY).', 'zero-sense'),
                ],
            ],
        ];
    }
}
