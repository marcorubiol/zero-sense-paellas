<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

/**
 * WooCommerce Order Status Enhancements
 *
 * Registers custom statuses (Budget Requested, Deposit Paid, Fully Paid, Not Available)
 * and tweaks the admin list tables to match the legacy behaviour. This feature is
 * intentionally independent from the Deposits module so the statuses remain available
 * even if deposits logic is disabled.
 */
class OrderStatuses implements FeatureInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return __('Order Status Enhancements', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return __('Registers custom WooCommerce order statuses and integrates them seamlessly with admin interface, reports, and bulk actions.', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getCategory(): string
    {
        return 'WooCommerce';
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
        return ['defined:WC_VERSION'];
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        add_action('init', [$this, 'registerStatuses']);
        add_filter('wc_order_statuses', [$this, 'injectStatuses']);
        add_filter('bulk_actions-edit-shop_order', [$this, 'filterBulkActions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'filterBulkActions']);
        add_filter('woocommerce_reports_order_statuses', [$this, 'exposeStatusesForReports']);
        if (is_admin()) {
            add_filter('woocommerce_shop_order_list_table_query_args', [$this, 'filterHposOrderList'], 999);
            add_action('pre_get_posts', [$this, 'filterClassicOrdersList'], 999);
        }

        // Register status log components
        $this->registerStatusLogComponents();
    }

    /**
     * Register status log metabox and logger
     */
    private function registerStatusLogComponents(): void
    {
        $metabox = new \ZeroSense\Features\WooCommerce\OrderStatuses\Components\StatusLogMetabox();
        $metabox->register();

        $logger = new \ZeroSense\Features\WooCommerce\OrderStatuses\Components\StatusLogger();
        $logger->register();
    }

    /**
     * Custom statuses map (prefixed slugs => labels).
     */
    private function getStatuses(): array
    {
        return [
            'wc-budget-requested' => __('Budget Requested', 'zero-sense'),
            'wc-deposit-paid' => __('Deposit Paid', 'zero-sense'),
            'wc-fully-paid' => __('Fully Paid', 'zero-sense'),
            'wc-not-available' => __('Not Available', 'zero-sense'),
        ];
    }

    /**
     * Register the custom order statuses with WordPress.
     */
    public function registerStatuses(): void
    {
        foreach ($this->getStatuses() as $slug => $label) {
            $args = [
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => $slug !== 'wc-not-available',
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'zero-sense'
                ),
            ];

            register_post_status($slug, $args);
        }
    }

    /**
     * Add the custom statuses to WooCommerce status dropdowns in preferred order.
     */
    public function injectStatuses(array $statuses): array
    {
        $inserted = [];
        $moveToEnd = [];

        foreach ($statuses as $key => $label) {
            if ($key === 'wc-processing' || $key === 'wc-on-hold') {
                $moveToEnd[$key] = $label;
                continue;
            }

            if ($key === 'wc-pending') {
                $inserted['wc-budget-requested'] = __('Budget Requested', 'zero-sense');
            }

            $inserted[$key] = $label;

            if ($key === 'wc-pending') {
                $inserted['wc-deposit-paid'] = __('Deposit Paid', 'zero-sense');
                $inserted['wc-fully-paid'] = __('Fully Paid', 'zero-sense');
            }

            if ($key === 'wc-completed') {
                $inserted['wc-not-available'] = __('Not Available', 'zero-sense');
            }
        }

        foreach ($this->getStatuses() as $slug => $label) {
            if (!isset($inserted[$slug])) {
                if ($slug === 'wc-budget-requested') {
                    $inserted = [$slug => $label] + $inserted;
                    continue;
                }

                if ($slug === 'wc-deposit-paid') {
                    $inserted['wc-pending'] = $inserted['wc-pending'] ?? __('Pending payment', 'woocommerce');
                    $inserted = $this->insertAfter($inserted, 'wc-pending', $slug, $label);
                    continue;
                }

                if ($slug === 'wc-fully-paid') {
                    $inserted = $this->insertAfter($inserted, 'wc-deposit-paid', $slug, $label);
                    continue;
                }

                    $inserted = $this->insertAfter($inserted, 'wc-completed', $slug, $label);
            }
        }

        return array_merge($inserted, $moveToEnd);
    }

    /**
     * Remove certain bulk actions and add custom ones.
     */
    public function filterBulkActions(array $actions): array
    {
        unset($actions['mark_processing'], $actions['mark_on-hold']);

        $actions['mark_deposit-paid'] = __('Change status to Deposit Paid', 'zero-sense');
        $actions['mark_fully-paid'] = __('Change status to Fully Paid', 'zero-sense');
        $actions['mark_not-available'] = __('Change status to Not Available', 'zero-sense');

        return $actions;
    }

    /**
     * Expose custom statuses to WooCommerce reports.
     */
    public function exposeStatusesForReports($statuses): array
    {
        if (!is_array($statuses)) {
            $statuses = [];
        }
        
        $statuses['not-available'] = __('Not Available', 'zero-sense');
        $statuses['deposit-paid'] = __('Deposit Paid', 'zero-sense');
        $statuses['fully-paid'] = __('Fully Paid', 'zero-sense');
        return $statuses;
    }

    /**
     * Filter HPOS list table (new WooCommerce) to hide Cancelled/Not Available in "All" view.
     */
    public function filterHposOrderList(array $args): array
    {
        if (isset($_GET['status'])) {
            $statusParam = wp_unslash($_GET['status']);
            if (is_array($statusParam)) {
                $statusParam = array_map('sanitize_key', $statusParam);
            } else {
                $statusParam = sanitize_key((string) $statusParam);
            }
            if ((is_array($statusParam) && array_filter($statusParam, fn($s) => $s !== '' && $s !== 'all')) ||
                (!is_array($statusParam) && $statusParam !== '' && $statusParam !== 'all')) {
                return $args;
            }
        }

        $exclude = ['wc-cancelled', 'wc-not-available'];
        $all = array_keys(wc_get_order_statuses());
        $allowedPrefixed = array_values(array_diff($all, $exclude));
        $allowedUnprefixed = array_map(fn($status) => str_starts_with($status, 'wc-') ? substr($status, 3) : $status, $allowedPrefixed);

        $args['status'] = $allowedUnprefixed;
        $args['post_status'] = $allowedPrefixed;

        return $args;
    }

    /**
     * Filter classic posts-based order list to hide Cancelled/Not Available from "All" view.
     */
    public function filterClassicOrdersList($query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
            return;
        }

        if (isset($_GET['post_status'])) {
            $requested = $_GET['post_status'];
            if ($requested !== '' && $requested !== 'all') {
                return;
            }
        }

        $exclude = ['wc-cancelled', 'wc-not-available'];
        $all = array_keys(wc_get_order_statuses());
        $allowed = array_values(array_diff($all, $exclude));
        $query->set('post_status', $allowed);
    }

    /**
     * Helper to insert a status after a target key while preserving order.
     */
    private function insertAfter(array $statuses, string $targetKey, string $newKey, string $label): array
    {
        $result = [];
        $inserted = false;

        foreach ($statuses as $key => $value) {
            $result[$key] = $value;

            if ($key === $targetKey) {
                $result[$newKey] = $label;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $result[$newKey] = $label;
        }

        return $result;
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
                'title' => __('Custom order statuses added', 'zero-sense'),
                'items' => [
                    __('Budget Requested - Initial customer inquiry', 'zero-sense'),
                    __('Deposit Paid - Partial payment received', 'zero-sense'),
                    __('Fully Paid - Complete payment received', 'zero-sense'),
                    __('Not Available - Service unavailable', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Default statuses removed', 'zero-sense'),
                'items' => [
                    __('Processing - Removed from dropdowns and bulk actions', 'zero-sense'),
                    __('On Hold - Removed from dropdowns and bulk actions', 'zero-sense'),
                    __('Cancelled/Not Available - Hidden from "All" orders view', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Admin enhancements', 'zero-sense'),
                'items' => [
                    __('Color-coded status badges in order lists', 'zero-sense'),
                    __('Custom bulk actions for new statuses', 'zero-sense'),
                    __('Integration with WooCommerce reports', 'zero-sense'),
                    __('Optimized order list views', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Compatibility', 'zero-sense'),
                'content' => __('Works with both classic WooCommerce and HPOS (High-Performance Order Storage). Independent from Deposits module.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/OrderStatuses.php', 'zero-sense'),
                    __('registerStatuses() → registers post statuses', 'zero-sense'),
                    __('injectStatuses() → injects into WooCommerce dropdowns', 'zero-sense'),
                    __('filterBulkActions() → adds custom bulk actions', 'zero-sense'),
                    __('exposeStatusesForReports() → exposes to reports', 'zero-sense'),
                    __('filterHposOrderList() / filterClassicOrdersList() → list table filters', 'zero-sense'),
                    __('injectAdminStyles() / getAdminCss() → admin badge colours', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('init (register post statuses)', 'zero-sense'),
                    __('wc_order_statuses (inject custom statuses)', 'zero-sense'),
                    __('bulk_actions-edit-shop_order (bulk actions)', 'zero-sense'),
                    __('woocommerce_reports_order_statuses (reports exposure)', 'zero-sense'),
                    __('woocommerce_shop_order_list_table_query_args / pre_get_posts (list filtering)', 'zero-sense'),
                    __('admin_head (inline CSS)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Open an order → status dropdown should include Budget Requested, Deposit Paid, Fully Paid, Not Available.', 'zero-sense'),
                    __('Orders list: verify colours and that Cancelled/Not Available are hidden in "All" view.', 'zero-sense'),
                    __('Bulk actions: ensure new actions for Deposit/Fully/Not Available are present.', 'zero-sense'),
                    __('Reports: include the custom statuses where applicable.', 'zero-sense'),
                ],
            ],
        ];
    }
}
