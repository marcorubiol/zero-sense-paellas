<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;

class ShoppingList implements FeatureInterface
{
    private const OPTION_SECRET         = 'zs_shopping_list_secret';
    private const QUERY_SIG             = 'zs_sl_sig';
    private const QUERY_FROM            = 'zs_sl_from';
    private const QUERY_TO              = 'zs_sl_to';
    private const QUERY_LOC             = 'zs_sl_loc';
    private const QUERY_ORDERS          = 'zs_sl_orders';
    private const PAGE_SLUG             = 'llista-compra';
    private const META_EVENT_DATE       = 'zs_event_date';
    private const META_SERVICE_LOC      = 'zs_event_service_location';
    private const META_RECIPE_ID        = 'zs_recipe_id';
    private const META_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    private const META_RABBIT_CHOICE    = '_zs_rabbit_choice';
    private const META_RECIPE_ING       = 'zs_recipe_ingredients';
    private const META_RECIPE_LIQUIDS   = 'zs_recipe_liquids';
    private const META_NEEDS_PAELLA     = 'zs_recipe_needs_paella';
    private const TAX_INGREDIENT        = 'zs_ingredient';
    private const TAX_LIQUID            = 'zs_liquid';
    private const META_ADULTS           = 'zs_event_adults';
    private const META_CHILDREN         = 'zs_event_children_5_to_8';
    private const META_BABIES           = 'zs_event_children_0_to_4';
    private const ADULT_WEIGHT          = 1.0;
    private const CHILD_WEIGHT          = 0.4;
    private const BABY_WEIGHT           = 0.0;
    private const ALLOWED_STATUSES      = ['wc-deposit-paid', 'wc-fully-paid', 'deposit-paid', 'fully-paid'];

    public function getName(): string        { return __('Shopping List', 'zero-sense'); }
    public function getDescription(): string { return __('Token-protected public page aggregating ingredients across orders by date range and location.', 'zero-sense'); }
    public function getCategory(): string    { return 'WooCommerce'; }
    public function isToggleable(): bool     { return false; }
    public function isEnabled(): bool        { return true; }
    public function getPriority(): int       { return 30; }
    public function getConditions(): array   { return ['class_exists:WooCommerce']; }

    public function init(): void
    {
        add_action('init', [$this, 'ensureSecret']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'maybeProtectPage'], 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_shortcode('zs_shopping_list', [$this, 'renderShortcode']);
        add_shortcode('zs_shopping_list_require_token', [$this, 'shortcodeRequireToken']);
        add_action('wp_ajax_nopriv_zs_shopping_list_data', [$this, 'ajaxGetData']);
        add_action('wp_ajax_zs_shopping_list_data', [$this, 'ajaxGetData']);
        add_action('wp_ajax_zs_shopping_list_debug', [$this, 'ajaxDebug']);
    }

    public function ensureSecret(): void
    {
        if (!get_option(self::OPTION_SECRET)) {
            update_option(self::OPTION_SECRET, $this->generateSecret(), false);
        }
    }

    public function enqueueAssets(): void
    {
        if (!$this->isShoppingListPage()) { return; }
        wp_enqueue_style('zs-shopping-list', ZERO_SENSE_URL . 'assets/css/shopping-list.css', [], ZERO_SENSE_VERSION);
        wp_enqueue_script('zs-shopping-list', ZERO_SENSE_URL . 'assets/js/shopping-list.js', [], ZERO_SENSE_VERSION, true);
        wp_localize_script('zs-shopping-list', 'zsShoppingList', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zs_shopping_list_nonce'),
            'pageUrl' => (string) get_permalink(get_page_by_path(self::PAGE_SLUG)),
        ]);
    }

    /** @param string[] $vars @return string[] */
    public function registerQueryVars(array $vars): array
    {
        $vars[] = self::QUERY_SIG;
        $vars[] = self::QUERY_FROM;
        $vars[] = self::QUERY_TO;
        $vars[] = self::QUERY_LOC;
        $vars[] = self::QUERY_ORDERS;
        return $vars;
    }

    public function maybeProtectPage(): void
    {
        if (!$this->isShoppingListPage()) { return; }
        $sig = get_query_var(self::QUERY_SIG);
        if (!is_string($sig) || $sig === '') { return; }
        if (!$this->verifySignature()) { $this->deny(); }
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        add_filter('wp_robots', function (array $r): array {
            $r['noindex'] = true; $r['nofollow'] = true; return $r;
        });
        nocache_headers();
    }

    private function isShoppingListPage(): bool
    {
        $page = get_page_by_path(self::PAGE_SLUG);
        return $page && is_page($page->ID);
    }

    public function shortcodeRequireToken(): string
    {
        if (!$this->verifySignature()) { $this->deny(); }
        return '';
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function renderShortcode($atts): string
    {
        $from      = sanitize_text_field((string) get_query_var(self::QUERY_FROM));
        $to        = sanitize_text_field((string) get_query_var(self::QUERY_TO));
        $loc       = absint(get_query_var(self::QUERY_LOC));
        $ordersRaw = sanitize_text_field((string) get_query_var(self::QUERY_ORDERS));
        $sigValid  = $this->verifySignature();
        $preFiltered = $sigValid && $from !== '' && $to !== '' && $loc > 0;

        $locations = get_terms(['taxonomy' => 'service-area', 'hide_empty' => false]);
        if (is_wp_error($locations) || !is_array($locations)) { $locations = []; }

        $preOrders = $preList = $preOrderIds = [];
        if ($preFiltered) {
            $preOrders   = $this->queryOrders($from, $to, $loc);
            $preOrderIds = $ordersRaw !== ''
                ? array_values(array_filter(array_map('absint', explode(',', $ordersRaw))))
                : array_column($preOrders, 'id');
            if (!empty($preOrderIds)) {
                $preList = $this->aggregateIngredients($preOrderIds);
            }
        }

        ob_start();
        $this->renderPage($from, $to, $loc, $locations, $preOrders, $preOrderIds, $preList);
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    public function ajaxDebug(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']); return;
        }

        $from = sanitize_text_field(wp_unslash($_POST['from'] ?? ''));
        $to   = sanitize_text_field(wp_unslash($_POST['to']   ?? ''));
        $loc  = absint($_POST['loc'] ?? 0);

        $statuses = array_unique(array_map(function (string $s): string {
            return strpos($s, 'wc-') === 0 ? $s : 'wc-' . $s;
        }, self::ALLOWED_STATUSES));

        // 1. Query with NO filters — just statuses
        $allIds = wc_get_orders(['limit' => 20, 'return' => 'ids', 'status' => $statuses]);

        // 2. Sample the first few orders to see their actual meta values
        $samples = [];
        foreach (array_slice((array) $allIds, 0, 5) as $id) {
            $o = wc_get_order((int) $id);
            if (!$o instanceof WC_Order) { continue; }
            $samples[] = [
                'id'       => $o->get_id(),
                'status'   => $o->get_status(),
                'date_meta'=> $o->get_meta(self::META_EVENT_DATE, true),
                'loc_meta' => $o->get_meta(self::META_SERVICE_LOC, true),
            ];
        }

        // 3. Query with only date filter
        $idsDateOnly = $from && $to ? wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => $statuses,
            'meta_query' => [['key' => self::META_EVENT_DATE, 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE']],
        ]) : 'skipped';

        // 4. Query with only loc filter
        $idsLocOnly = $loc > 0 ? wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => $statuses,
            'meta_query' => [['key' => self::META_SERVICE_LOC, 'value' => $loc, 'compare' => '=', 'type' => 'NUMERIC']],
        ]) : 'skipped';

        // 5. Full query
        $idsFull = ($from && $to && $loc > 0) ? wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => $statuses,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_EVENT_DATE,  'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE'],
                ['key' => self::META_SERVICE_LOC, 'value' => $loc,         'compare' => '=',       'type' => 'NUMERIC'],
            ],
        ]) : 'skipped';

        // 6. Available service-area terms
        $terms = get_terms(['taxonomy' => 'service-area', 'hide_empty' => false]);
        $termList = is_wp_error($terms) ? 'ERROR: ' . $terms->get_error_message() : array_map(function ($t) {
            return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
        }, (array) $terms);

        wp_send_json_success([
            'params'           => compact('from', 'to', 'loc'),
            'statuses_used'    => $statuses,
            'total_with_status'=> count((array) $allIds),
            'samples'          => $samples,
            'count_date_only'  => is_array($idsDateOnly)  ? count($idsDateOnly)  : $idsDateOnly,
            'ids_date_only'    => is_array($idsDateOnly)  ? $idsDateOnly         : [],
            'count_loc_only'   => is_array($idsLocOnly)   ? count($idsLocOnly)   : $idsLocOnly,
            'ids_loc_only'     => is_array($idsLocOnly)   ? $idsLocOnly          : [],
            'count_full'       => is_array($idsFull)      ? count($idsFull)      : $idsFull,
            'ids_full'         => is_array($idsFull)      ? $idsFull             : [],
            'service_area_terms' => $termList,
            'meta_keys_used'   => [
                'date' => self::META_EVENT_DATE,
                'loc'  => self::META_SERVICE_LOC,
            ],
        ]);
    }

    public function ajaxGetData(): void
    {
        check_ajax_referer('zs_shopping_list_nonce', 'nonce');
        $from        = sanitize_text_field(wp_unslash($_POST['from'] ?? ''));
        $to          = sanitize_text_field(wp_unslash($_POST['to'] ?? ''));
        $loc         = absint($_POST['loc'] ?? 0);
        $orderIdsRaw = sanitize_text_field(wp_unslash($_POST['order_ids'] ?? ''));

        if ($from === '' || $to === '' || $loc <= 0) {
            wp_send_json_error(['message' => 'Missing required parameters.']); return;
        }

        $orders   = $this->queryOrders($from, $to, $loc);
        $validIds = array_column($orders, 'id');

        if ($orderIdsRaw !== '') {
            $requested = array_values(array_filter(array_map('absint', explode(',', $orderIdsRaw))));
            $orderIds  = array_values(array_intersect($requested, $validIds));
        } else {
            $orderIds = $validIds;
        }

        $list = !empty($orderIds) ? $this->aggregateIngredients($orderIds) : [];

        wp_send_json_success([
            'orders'     => $orders,
            'list'       => $list,
            'signed_url' => $this->buildSignedUrl($from, $to, $loc, $orderIds),
        ]);
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    private function queryOrders(string $from, string $to, int $loc): array
    {
        $statuses = array_unique(array_map(function (string $s): string {
            return strpos($s, 'wc-') === 0 ? $s : 'wc-' . $s;
        }, self::ALLOWED_STATUSES));

        $ids = wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => $statuses,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_EVENT_DATE,  'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE'],
                ['key' => self::META_SERVICE_LOC, 'value' => $loc,         'compare' => '=',       'type' => 'NUMERIC'],
            ],
        ]);
        if (!is_array($ids)) { return []; }

        $result = [];
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if (!$order instanceof WC_Order) { continue; }
            $result[] = [
                'id'       => $order->get_id(),
                'number'   => $order->get_order_number(),
                'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'date'     => (string) $order->get_meta(self::META_EVENT_DATE, true),
                'guests'   => (int) $order->get_meta('zs_event_total_guests', true),
                'status'   => wc_get_order_status_name($order->get_status()),
                'products' => $this->getOrderProductNames($order),
            ];
        }
        usort($result, function (array $a, array $b): int {
            return strcmp((string) $a['date'], (string) $b['date']);
        });
        return $result;
    }

    private function getOrderProductNames(WC_Order $order): string
    {
        $names = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) { continue; }
            $qty     = (int) $item->get_quantity();
            $names[] = $item->get_name() . ($qty > 1 ? ' ×' . $qty : '');
        }
        return implode(', ', $names);
    }

    private function aggregateIngredients(array $orderIds): array
    {
        $ingTotals = $liquidTotals = [];

        foreach ($orderIds as $orderId) {
            $order = wc_get_order((int) $orderId);
            if (!$order instanceof WC_Order) { continue; }
            $eqTotal = $this->getEquivalentPax($order);
            if ($eqTotal <= 0) { continue; }
            $lineItems = $order->get_items('line_item');
            if (!$lineItems) { continue; }

            $eligible = []; $sumQty = 0.0;
            foreach ($lineItems as $item) {
                if (!$item instanceof \WC_Order_Item_Product) { continue; }
                $qty = (float) $item->get_quantity();
                if ($qty <= 0) { continue; }
                $product = $item->get_product();
                if (!$product instanceof \WC_Product) { continue; }
                $recipeId = $this->resolveRecipeId($item, $product);
                if ($recipeId <= 0) { continue; }
                $eligible[] = ['recipe_id' => $recipeId, 'qty' => $qty];
                $sumQty += $qty;
            }
            if (empty($eligible) || $sumQty <= 0) { continue; }

            foreach ($eligible as $row) {
                $recipeId = (int) $row['recipe_id'];
                $eqItem   = $eqTotal * ((float) $row['qty'] / $sumQty);
                if ($eqItem <= 0) { continue; }

                $recipeIng = get_post_meta($recipeId, self::META_RECIPE_ING, true);
                if (is_array($recipeIng)) {
                    foreach ($recipeIng as $ingRow) {
                        if (!is_array($ingRow)) { continue; }
                        $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                        $perPax = isset($ingRow['qty'])        ? (float) $ingRow['qty']       : 0.0;
                        $unit   = isset($ingRow['unit'])       ? sanitize_key((string) $ingRow['unit']) : '';
                        if ($termId <= 0 || $perPax <= 0 || $unit === '') { continue; }
                        $amount = $eqItem * $perPax;
                        if ($amount <= 0) { continue; }
                        $k = $termId . '|' . $unit;
                        if (!isset($ingTotals[$k])) { $ingTotals[$k] = ['term_id' => $termId, 'unit' => $unit, 'qty' => 0.0]; }
                        $ingTotals[$k]['qty'] += $amount;
                    }
                }

                if (get_post_meta($recipeId, self::META_NEEDS_PAELLA, true) === '1') {
                    $recipeLiquids = get_post_meta($recipeId, self::META_RECIPE_LIQUIDS, true);
                    if (is_array($recipeLiquids)) {
                        foreach ($recipeLiquids as $liqRow) {
                            if (!is_array($liqRow)) { continue; }
                            $termId       = isset($liqRow['liquid']) ? (int) $liqRow['liquid']   : 0;
                            $litresPerPax = isset($liqRow['qty'])    ? (float) $liqRow['qty']     : 0.0;
                            if ($termId <= 0 || $litresPerPax <= 0) { continue; }
                            $amount = $litresPerPax * $eqItem;
                            if ($amount <= 0) { continue; }
                            $k = (string) $termId;
                            if (!isset($liquidTotals[$k])) { $liquidTotals[$k] = ['term_id' => $termId, 'qty' => 0.0]; }
                            $liquidTotals[$k]['qty'] += $amount;
                        }
                    }
                }
            }
        }

        $result = [];
        usort($ingTotals, function (array $a, array $b): int {
            return ($a['term_id'] ?? 0) <=> ($b['term_id'] ?? 0);
        });
        foreach ($ingTotals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit   = (string) ($t['unit'] ?? '');
            $qty    = (float) ($t['qty'] ?? 0);
            if ($termId <= 0 || $qty <= 0 || $unit === '') { continue; }
            $term = get_term($termId, self::TAX_INGREDIENT);
            $name = $term instanceof \WP_Term ? $term->name : '';
            if ($name === '') { continue; }
            $n        = $this->normalizeUnit($qty, $unit);
            $result[] = ['name' => $name, 'qty' => $n['qty'], 'unit' => $n['unit'], 'type' => 'ingredient'];
        }
        foreach ($liquidTotals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $qty    = (float) ($t['qty'] ?? 0);
            if ($termId <= 0 || $qty <= 0) { continue; }
            $term = get_term($termId, self::TAX_LIQUID);
            $name = $term instanceof \WP_Term ? $term->name : '';
            if ($name === '') { continue; }
            $n        = $this->normalizeUnit($qty, 'l');
            $result[] = ['name' => $name, 'qty' => $n['qty'], 'unit' => $n['unit'], 'type' => 'liquid'];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // HTML
    // -------------------------------------------------------------------------

    private function renderPage(string $from, string $to, int $loc, array $locations, array $preOrders, array $preOrderIds, array $preList): void
    {
        ?>
        <div class="zs-sl" id="zs-sl">
            <div class="zs-sl__filters no-print">
                <div class="zs-sl__filter-row">
                    <div class="zs-sl__filter-group">
                        <label class="zs-sl__label" for="zs-sl-from"><?php esc_html_e('Des de', 'zero-sense'); ?></label>
                        <input class="zs-sl__input" type="date" id="zs-sl-from" value="<?php echo esc_attr($from); ?>">
                    </div>
                    <div class="zs-sl__filter-group">
                        <label class="zs-sl__label" for="zs-sl-to"><?php esc_html_e('Fins a', 'zero-sense'); ?></label>
                        <input class="zs-sl__input" type="date" id="zs-sl-to" value="<?php echo esc_attr($to); ?>">
                    </div>
                    <div class="zs-sl__filter-group">
                        <label class="zs-sl__label" for="zs-sl-loc"><?php esc_html_e('Localització', 'zero-sense'); ?></label>
                        <select class="zs-sl__select" id="zs-sl-loc">
                            <option value=""><?php esc_html_e('Selecciona...', 'zero-sense'); ?></option>
                            <?php foreach ($locations as $term) : if (!$term instanceof \WP_Term) { continue; } ?>
                                <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($loc, $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="zs-sl__filter-group zs-sl__filter-group--action">
                        <button type="button" class="zs-sl__btn zs-sl__btn--primary" id="zs-sl-search"><?php esc_html_e('Cercar pedidos', 'zero-sense'); ?></button>
                    </div>
                </div>
            </div>
            <div class="zs-sl__body" id="zs-sl-body">
                <?php if (!empty($preOrders)) : ?>
                    <?php $this->renderOrdersPanel($preOrders, $preOrderIds); ?>
                    <div id="zs-sl-list-wrap"><?php $this->renderList($preList); ?></div>
                <?php else : ?>
                    <div class="zs-sl__empty" id="zs-sl-empty">
                        <p><?php esc_html_e('Selecciona un rang de dates i una localització per veure la llista de la compra.', 'zero-sense'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="zs-sl__loading" id="zs-sl-loading" style="display:none;">
                <span><?php esc_html_e('Carregant...', 'zero-sense'); ?></span>
            </div>
        </div>
        <?php
    }

    private function renderOrdersPanel(array $orders, array $selectedIds): void
    {
        ?>
        <div class="zs-sl__orders no-print" id="zs-sl-orders">
            <h3 class="zs-sl__section-title"><?php esc_html_e('Pedidos inclosos', 'zero-sense'); ?></h3>
            <div class="zs-sl__orders-actions">
                <button type="button" class="zs-sl__btn zs-sl__btn--sm" id="zs-sl-check-all"><?php esc_html_e('Tots', 'zero-sense'); ?></button>
                <button type="button" class="zs-sl__btn zs-sl__btn--sm" id="zs-sl-uncheck-all"><?php esc_html_e('Cap', 'zero-sense'); ?></button>
            </div>
            <div class="zs-sl__orders-list" id="zs-sl-orders-list">
                <?php foreach ($orders as $o) : ?>
                    <label class="zs-sl__order-item">
                        <input type="checkbox" class="zs-sl__order-check" value="<?php echo esc_attr((string) $o['id']); ?>" <?php checked(in_array($o['id'], $selectedIds, true)); ?>>
                        <span class="zs-sl__order-num">#<?php echo esc_html((string) $o['number']); ?></span>
                        <span class="zs-sl__order-customer"><?php echo esc_html($o['customer']); ?></span>
                        <span class="zs-sl__order-date"><?php echo esc_html($o['date']); ?></span>
                        <span class="zs-sl__order-guests"><?php echo esc_html((string) $o['guests']); ?> pax</span>
                        <span class="zs-sl__order-products"><?php echo esc_html($o['products']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="zs-sl__orders-footer">
                <button type="button" class="zs-sl__btn zs-sl__btn--primary" id="zs-sl-update"><?php esc_html_e('Actualitzar llista', 'zero-sense'); ?></button>
                <button type="button" class="zs-sl__btn zs-sl__btn--secondary" id="zs-sl-share"><?php esc_html_e('Copiar enllaç', 'zero-sense'); ?></button>
                <button type="button" class="zs-sl__btn zs-sl__btn--secondary" onclick="window.print()"><?php esc_html_e('Imprimir', 'zero-sense'); ?></button>
            </div>
        </div>
        <?php
    }

    private function renderList(array $list): void
    {
        if (empty($list)) {
            echo '<div class="zs-sl__list-empty"><p>' . esc_html__('No hi ha ingredients per als pedidos seleccionats.', 'zero-sense') . '</p></div>';
            return;
        }
        $ingredients = array_values(array_filter($list, function (array $i): bool { return $i['type'] === 'ingredient'; }));
        $liquids     = array_values(array_filter($list, function (array $i): bool { return $i['type'] === 'liquid'; }));
        ?>
        <div class="zs-sl__list" id="zs-sl-list">
            <?php if (!empty($ingredients)) : ?>
                <div class="zs-sl__list-section">
                    <h3 class="zs-sl__section-title"><?php esc_html_e('Ingredients', 'zero-sense'); ?></h3>
                    <div class="zs-sl__list-items">
                        <?php foreach ($ingredients as $item) : ?>
                            <div class="zs-sl__list-item">
                                <span class="zs-sl__item-name"><?php echo esc_html($item['name']); ?></span>
                                <span class="zs-sl__item-qty"><?php echo esc_html($this->formatNumber($item['qty'])); ?> <span class="zs-sl__item-unit"><?php echo esc_html($item['unit']); ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($liquids)) : ?>
                <div class="zs-sl__list-section">
                    <h3 class="zs-sl__section-title"><?php esc_html_e('Líquids', 'zero-sense'); ?></h3>
                    <div class="zs-sl__list-items">
                        <?php foreach ($liquids as $item) : ?>
                            <div class="zs-sl__list-item">
                                <span class="zs-sl__item-name"><?php echo esc_html($item['name']); ?></span>
                                <span class="zs-sl__item-qty"><?php echo esc_html($this->formatNumber($item['qty'])); ?> <span class="zs-sl__item-unit"><?php echo esc_html($item['unit']); ?></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------

    public function buildSignedUrl(string $from, string $to, int $loc, array $orderIds): string
    {
        $pageUrl = (string) get_permalink(get_page_by_path(self::PAGE_SLUG));
        if ($pageUrl === '') { return ''; }
        sort($orderIds);
        $params = [
            self::QUERY_FROM   => $from,
            self::QUERY_TO     => $to,
            self::QUERY_LOC    => (string) $loc,
            self::QUERY_ORDERS => implode(',', $orderIds),
        ];
        $params[self::QUERY_SIG] = $this->sign($params);
        return add_query_arg($params, $pageUrl);
    }

    private function sign(array $params): string
    {
        ksort($params);
        $secret = (string) get_option(self::OPTION_SECRET, '');
        return hash_hmac('sha256', http_build_query($params), $secret);
    }

    private function verifySignature(): bool
    {
        $sig = get_query_var(self::QUERY_SIG);
        if (!is_string($sig) || $sig === '') { return false; }
        $params = [
            self::QUERY_FROM   => sanitize_text_field((string) get_query_var(self::QUERY_FROM)),
            self::QUERY_TO     => sanitize_text_field((string) get_query_var(self::QUERY_TO)),
            self::QUERY_LOC    => (string) absint(get_query_var(self::QUERY_LOC)),
            self::QUERY_ORDERS => sanitize_text_field((string) get_query_var(self::QUERY_ORDERS)),
        ];
        return hash_equals($this->sign($params), $sig);
    }

    private function deny(): void
    {
        status_header(404);
        nocache_headers();
        wp_die(__('Accés no autoritzat.', 'zero-sense'), '', ['response' => 404]);
    }

    private function generateSecret(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return wp_generate_password(64, false, false);
        }
    }

    // -------------------------------------------------------------------------
    // Math helpers
    // -------------------------------------------------------------------------

    private function getEquivalentPax(WC_Order $order): float
    {
        $adults   = (int) $order->get_meta(self::META_ADULTS, true);
        $children = (int) $order->get_meta(self::META_CHILDREN, true);
        $babies   = (int) $order->get_meta(self::META_BABIES, true);
        $eq = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        return $eq > 0 ? (float) $eq : 0.0;
    }

    private function resolveRecipeId(\WC_Order_Item_Product $item, \WC_Product $product): int
    {
        $recipeId = (int) $product->get_meta(self::META_RECIPE_ID, true);
        if ($recipeId <= 0) { return 0; }
        if ($item->get_meta(self::META_RABBIT_CHOICE, true) === 'without') {
            $noRabbit = (int) $product->get_meta(self::META_RECIPE_NO_RABBIT, true);
            return $noRabbit > 0 ? $noRabbit : $recipeId;
        }
        return $recipeId;
    }

    private function normalizeUnit(float $qty, string $unit): array
    {
        if ($unit === 'g'  && $qty >= 1000) { return ['qty' => $qty / 1000, 'unit' => 'kg']; }
        if ($unit === 'kg' && $qty < 1)     { return ['qty' => $qty * 1000, 'unit' => 'gr']; }
        if ($unit === 'ml' && $qty >= 1000) { return ['qty' => $qty / 1000, 'unit' => 'lit']; }
        if ($unit === 'l'  && $qty < 1)     { return ['qty' => $qty * 1000, 'unit' => 'ml']; }
        $map = ['g' => 'gr', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'lit', 'u' => 'pcs'];
        return ['qty' => $qty, 'unit' => $map[$unit] ?? $unit];
    }

    private function formatNumber(float $n): string
    {
        $s = number_format($n, 1, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }
}
