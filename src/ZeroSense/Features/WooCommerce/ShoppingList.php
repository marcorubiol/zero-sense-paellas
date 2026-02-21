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
    public function isToggleable(): bool     { return true; }
    public function isEnabled(): bool        { return (bool) get_option($this->getOptionName(), true); }
    public function getOptionName(): string  { return 'zs_feature_shopping_list'; }
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
        $preItemKeysForJs = [];
        if ($this->verifySignature()) {
            $from  = sanitize_text_field((string) get_query_var(self::QUERY_FROM));
            $to    = sanitize_text_field((string) get_query_var(self::QUERY_TO));
            $loc   = absint(get_query_var(self::QUERY_LOC));
            $raw   = sanitize_text_field((string) get_query_var(self::QUERY_ORDERS));
            if ($from !== '' && $to !== '' && $loc > 0) {
                $orders = $this->queryOrders($from, $to, $loc);
                $validKeys = [];
                $keysByOrder = [];
                foreach ($orders as $o) {
                    $oid = (string) $o['id'];
                    $keysByOrder[$oid] = [];
                    foreach ($o['items'] as $item) {
                        $validKeys[] = $item['key'];
                        $keysByOrder[$oid][] = $item['key'];
                    }
                }
                if ($raw !== '') {
                    $requested = array_filter(array_map('trim', explode(',', $raw)));
                    foreach ($requested as $r) {
                        if (strpos($r, ':') !== false) {
                            if (in_array($r, $validKeys, true)) { $preItemKeysForJs[] = $r; }
                        } elseif (isset($keysByOrder[$r])) {
                            foreach ($keysByOrder[$r] as $k) { $preItemKeysForJs[] = $k; }
                        }
                    }
                    $preItemKeysForJs = array_values(array_unique($preItemKeysForJs));
                } else {
                    $preItemKeysForJs = $validKeys;
                }
            }
        }
        $preTotalsForJs = !empty($preItemKeysForJs) ? $this->aggregatePaxTotals($orders ?? [], $preItemKeysForJs) : null;
        wp_localize_script('zs-shopping-list', 'zsShoppingList', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('zs_shopping_list_nonce'),
            'pageUrl'      => (string) get_permalink(get_page_by_path(self::PAGE_SLUG)),
            'preItemKeys'  => $preItemKeysForJs,
            'preTotals'    => $preTotalsForJs,
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

        $preOrders = $preList = $preItemKeys = [];
        if ($preFiltered) {
            $preOrders = $this->queryOrders($from, $to, $loc);
            $validKeys = [];
            $keysByOrder = [];
            foreach ($preOrders as $o) {
                $oid = (string) $o['id'];
                $keysByOrder[$oid] = [];
                foreach ($o['items'] as $item) {
                    $validKeys[] = $item['key'];
                    $keysByOrder[$oid][] = $item['key'];
                }
            }
            if ($ordersRaw !== '') {
                $requested = array_filter(array_map('trim', explode(',', $ordersRaw)));
                foreach ($requested as $r) {
                    if (strpos($r, ':') !== false) {
                        if (in_array($r, $validKeys, true)) { $preItemKeys[] = $r; }
                    } elseif (isset($keysByOrder[$r])) {
                        foreach ($keysByOrder[$r] as $k) { $preItemKeys[] = $k; }
                    }
                }
                $preItemKeys = array_values(array_unique($preItemKeys));
            } else {
                $preItemKeys = $validKeys;
            }
            if (!empty($preItemKeys)) {
                $preList = $this->aggregateIngredients($preItemKeys);
            }
        }

        $preTotals = !empty($preItemKeys) ? $this->aggregatePaxTotals($preOrders, $preItemKeys) : [];

        ob_start();
        $this->renderPage($from, $to, $loc, $locations, $preOrders, $preItemKeys, $preList, $preTotals);
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    public function ajaxGetData(): void
    {
        check_ajax_referer('zs_shopping_list_nonce', 'nonce');
        $from        = sanitize_text_field(wp_unslash($_POST['from'] ?? ''));
        $to          = sanitize_text_field(wp_unslash($_POST['to'] ?? ''));
        $loc         = absint($_POST['loc'] ?? 0);
        $itemKeysRaw = sanitize_text_field(wp_unslash($_POST['item_keys'] ?? ''));

        if ($from === '' || $to === '' || $loc <= 0) {
            wp_send_json_error(['message' => 'Missing required parameters.']); return;
        }

        $orders    = $this->queryOrders($from, $to, $loc);
        $validKeys = [];
        foreach ($orders as $o) {
            foreach ($o['items'] as $item) {
                $validKeys[] = $item['key'];
            }
        }

        if ($itemKeysRaw === '__none__') {
            $itemKeys = [];
        } elseif ($itemKeysRaw !== '') {
            $requested = array_filter(explode(',', $itemKeysRaw));
            $itemKeys  = array_values(array_intersect($requested, $validKeys));
        } else {
            $itemKeys = $validKeys;
        }

        $orderIds = array_unique(array_map(function (string $k): int {
            return (int) explode(':', $k)[0];
        }, $itemKeys));

        $list = !empty($itemKeys) ? $this->aggregateIngredients($itemKeys) : [];

        $totals = $this->aggregatePaxTotals($orders, $itemKeys);

        wp_send_json_success([
            'orders'     => $orders,
            'list'       => $list,
            'totals'     => $totals,
            'signed_url' => $this->buildSignedUrl($from, $to, $loc, array_values($orderIds)),
        ]);
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    private function queryOrders(string $from, string $to, int $loc): array
    {
        $cacheKey = 'zs_sl_orders_v2_' . md5($from . '_' . $to . '_' . $loc);
        $cached = get_transient($cacheKey);
        
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $statuses = array_unique(array_map(function (string $s): string {
            return strpos($s, 'wc-') === 0 ? $s : 'wc-' . $s;
        }, self::ALLOWED_STATUSES));

        $ids = wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => $statuses,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_EVENT_DATE,  'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'CHAR'],
                ['key' => self::META_SERVICE_LOC, 'value' => (string) $loc, 'compare' => '='],
            ],
        ]);
        
        if (!is_array($ids)) { 
            return []; 
        }

        $result = [];
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if (!$order instanceof WC_Order) { continue; }
            $rawDate = (string) $order->get_meta(self::META_EVENT_DATE, true);
            $adults   = (int) $order->get_meta(self::META_ADULTS, true);
            $children = (int) $order->get_meta(self::META_CHILDREN, true);
            $babies   = (int) $order->get_meta(self::META_BABIES, true);
            $result[] = [
                'id'       => $order->get_id(),
                'number'   => $order->get_order_number(),
                'customer' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'date'     => $this->formatDateEs($rawDate),
                'date_raw' => $rawDate,
                'guests'   => (int) $order->get_meta('zs_event_total_guests', true),
                'adults'   => $adults,
                'children' => $children,
                'babies'   => $babies,
                'items'    => $this->getOrderItems($order),
            ];
        }
        usort($result, function (array $a, array $b): int {
            return strcmp((string) $a['date_raw'], (string) $b['date_raw']);
        });
        
        // Cache the result for 1 hour
        set_transient($cacheKey, $result, 5 * MINUTE_IN_SECONDS);
        
        return $result;
    }

    private function formatDateEs(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $date;
    }

    private function getOrderItems(WC_Order $order): array
    {
        $adults   = (int) $order->get_meta(self::META_ADULTS, true);
        $children = (int) $order->get_meta(self::META_CHILDREN, true);
        $babies   = (int) $order->get_meta(self::META_BABIES, true);
        $total    = $adults + $children + $babies;
        $eqTotal  = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        $paxRatio = $total > 0 ? $eqTotal / $total : 1.0;

        $items = []; $idx = 0;
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) { $idx++; continue; }
            $product  = $item->get_product();
            $recipeId = $product instanceof \WC_Product ? (int) $product->get_meta(self::META_RECIPE_ID, true) : 0;
            if ($recipeId <= 0) { $idx++; continue; }
            $qty      = (int) $item->get_quantity();
            $recipeName = get_the_title($recipeId);
            $items[] = [
                'key'  => $order->get_id() . ':' . $idx,
                'eq'   => round($qty * $paxRatio, 1),
                'name' => $recipeName ?: $item->get_name(),
                'qty'  => $qty,
            ];
            $idx++;
        }
        return $items;
    }

    private function aggregateIngredients(array $itemKeys): array
    {
        $ingTotals = $liquidTotals = [];

        $byOrder = [];
        foreach ($itemKeys as $key) {
            $parts = explode(':', (string) $key);
            if (count($parts) !== 2) { continue; }
            $byOrder[(int) $parts[0]][] = (int) $parts[1];
        }

        foreach ($byOrder as $orderId => $allowedIdxs) {
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order) { continue; }
            $adults   = (int) $order->get_meta(self::META_ADULTS, true);
            $children = (int) $order->get_meta(self::META_CHILDREN, true);
            $babies   = (int) $order->get_meta(self::META_BABIES, true);
            $total    = $adults + $children + $babies;
            $eqTotal  = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
            $paxRatio = $total > 0 ? $eqTotal / $total : 1.0;
            $lineItems = $order->get_items('line_item');
            if (!$lineItems) { continue; }

            $allItems = []; $idx = 0;
            foreach ($lineItems as $item) {
                if (!$item instanceof \WC_Order_Item_Product) { continue; }
                $allItems[$idx] = $item;
                $idx++;
            }

            

            $eligible = [];
            foreach ($allowedIdxs as $i) {
                if (!isset($allItems[$i])) { continue; }
                $item = $allItems[$i];
                $qty  = (float) $item->get_quantity();
                if ($qty <= 0) { continue; }
                $product = $item->get_product();
                if (!$product instanceof \WC_Product) { continue; }
                $recipeId = $this->resolveRecipeId($item, $product);
                if ($recipeId <= 0) { continue; }
                $eligible[] = ['recipe_id' => $recipeId, 'qty' => $qty];
            }
            if (empty($eligible)) { continue; }

            foreach ($eligible as $row) {
                $recipeId = (int) $row['recipe_id'];
                $eqItem = (float) $row['qty'] * $paxRatio;
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

    private function renderPage(string $from, string $to, int $loc, array $locations, array $preOrders, array $preItemKeys, array $preList, array $preTotals = []): void
    {
        require __DIR__ . '/Views/shopping-list.php';
    }

    private function renderOrdersPanel(array $preOrders, array $preItemKeys): void
    {
        require __DIR__ . '/Views/shopping-list-orders.php';
    }

    private function renderList(array $preList): void
    {
        require __DIR__ . '/Views/shopping-list-ingredients.php';
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

    private function aggregatePaxTotals(array $orders, array $selectedItemKeys): array
    {
        $adults = $children = $babies = 0;
        $eq = 0.0;
        $addedOrders = [];
        foreach ($orders as $o) {
            $oid = (string) $o['id'];
            foreach ($o['items'] ?? [] as $item) {
                if (!in_array($item['key'], $selectedItemKeys, true)) { continue; }
                $eq += (float) ($item['eq'] ?? 0);
                if (!isset($addedOrders[$oid])) {
                    $addedOrders[$oid] = true;
                    $adults   += (int) ($o['adults']   ?? 0);
                    $children += (int) ($o['children'] ?? 0);
                    $babies   += (int) ($o['babies']   ?? 0);
                }
            }
        }
        return [
            'adults'   => $adults,
            'children' => $children,
            'babies'   => $babies,
            'eq'       => round($eq, 1),
        ];
    }

    private function getEffectiveRecipes(WC_Order $order): float
    {
        $adults   = (int) $order->get_meta(self::META_ADULTS, true);
        $children = (int) $order->get_meta(self::META_CHILDREN, true);
        $babies   = (int) $order->get_meta(self::META_BABIES, true);
        $total    = $adults + $children + $babies;
        if ($total <= 0) { return 0.0; }
        $eq       = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        $paxRatio = $eq / $total;

        $totalEq = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) { continue; }
            $product = $item->get_product();
            if (!$product instanceof \WC_Product) { continue; }
            if ($this->resolveRecipeId($item, $product) <= 0) { continue; }
            $totalEq += (float) $item->get_quantity() * $paxRatio;
        }
        return $totalEq;
    }

    private function formatNumber(float $n): string
    {
        $s = number_format($n, 1, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }
}
