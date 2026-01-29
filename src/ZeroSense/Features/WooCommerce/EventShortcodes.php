<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Post;
use WP_Term;
use ZeroSense\Core\FeatureInterface;

class EventShortcodes implements FeatureInterface
{
    private const META_OPS_MATERIAL = 'zs_ops_material';

    private const META_EVENT_ADULTS = 'zs_event_adults';
    private const META_EVENT_CHILDREN = 'zs_event_children_5_to_8';
    private const META_EVENT_BABIES = 'zs_event_children_0_to_4';

    private const OPTION_MATERIAL_SCHEMA = 'zs_ops_material_schema';

    private const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    private const META_RECIPE_INGREDIENTS = 'zs_recipe_ingredients';
    private const TAX_INGREDIENT = 'zs_ingredient';

    private const ADULT_WEIGHT = 1.0;
    private const CHILD_WEIGHT = 0.4;
    private const BABY_WEIGHT = 0.0;

    public function getName(): string
    {
        return __('Event Sheet Shortcodes', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds shortcodes to render event menu, materials and calculated ingredients.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
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
        add_shortcode('zs_event_menu', [$this, 'shortcodeMenu']);
        add_shortcode('zs_event_material', [$this, 'shortcodeMaterial']);
        add_shortcode('zs_event_ingredients', [$this, 'shortcodeIngredients']);
    }

    public function getPriority(): int
    {
        return 35;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    /**
     * [zs_event_menu order="123"]
     */
    public function shortcodeMenu($atts): string
    {
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $items = $order->get_items('line_item');
        if (!$items) {
            return '';
        }

        $rows = '';
        foreach ($items as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $name = $item->get_name();
            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $rows .= '<tr>'
                . '<td>' . esc_html($name) . '</td>'
                . '<td style="text-align:right;">' . esc_html($this->formatNumber($qty)) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<table class="zs-event-menu">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Menu', 'zero-sense') . '</th>'
            . '<th style="text-align:right;">' . esc_html__('Pax', 'zero-sense') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    /**
     * [zs_event_material order="123"]
     */
    public function shortcodeMaterial($atts): string
    {
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta(self::META_OPS_MATERIAL, true);
        $material = is_array($raw) ? $raw : [];

        $schema = $this->getMaterialSchema();
        if ($schema === []) {
            return '';
        }

        $rows = '';
        foreach ($schema as $key => $def) {
            $label = $def['label'] ?? $key;
            $type = $def['type'] ?? 'text';

            $entry = $material[$key] ?? null;
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $entry = $entry['value'];
            }

            $value = is_scalar($entry) ? (string) $entry : '';

            if ($type === 'bool') {
                if ($value !== '1') {
                    continue;
                }
                $value = esc_html__('Yes', 'zero-sense');
            } elseif ($type === 'qty_int') {
                $n = (int) $value;
                if ($n <= 0) {
                    continue;
                }
                $value = (string) $n;
            } else {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            $rows .= '<tr>'
                . '<td>' . esc_html($label) . '</td>'
                . '<td style="text-align:right;">' . esc_html($value) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<table class="zs-event-material">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Material & logistics', 'zero-sense') . '</th>'
            . '<th style="text-align:right;">' . esc_html__('Value', 'zero-sense') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    /**
     * [zs_event_ingredients order="123"]
     */
    public function shortcodeIngredients($atts): string
    {
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $eqTotal = $this->getEquivalentPax($order);
        if ($eqTotal <= 0) {
            return '';
        }

        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '';
        }

        $eligible = [];
        $sumQty = 0.0;

        foreach ($lineItems as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                $pid = (int) $item->get_product_id();
                $product = $pid > 0 ? wc_get_product($pid) : null;
            }

            if (!$product instanceof WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId <= 0) {
                continue;
            }

            $eligible[] = ['recipe_id' => $recipeId, 'qty' => $qty];
            $sumQty += $qty;
        }

        if ($eligible === [] || $sumQty <= 0) {
            return '';
        }

        $totals = [];
        foreach ($eligible as $row) {
            $recipeId = (int) $row['recipe_id'];
            $qty = (float) $row['qty'];

            $eqItem = $eqTotal * ($qty / $sumQty);
            if ($eqItem <= 0) {
                continue;
            }

            $recipeIngredients = get_post_meta($recipeId, self::META_RECIPE_INGREDIENTS, true);
            if (!is_array($recipeIngredients)) {
                continue;
            }

            foreach ($recipeIngredients as $ingRow) {
                if (!is_array($ingRow)) {
                    continue;
                }

                $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                $perPax = isset($ingRow['qty']) ? (float) $ingRow['qty'] : 0.0;
                $unit = isset($ingRow['unit']) ? sanitize_key((string) $ingRow['unit']) : '';

                if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                    continue;
                }

                $amount = $eqItem * $perPax;
                if ($amount <= 0) {
                    continue;
                }

                $k = $termId . '|' . $unit;
                if (!isset($totals[$k])) {
                    $totals[$k] = ['term_id' => $termId, 'unit' => $unit, 'qty' => 0.0];
                }
                $totals[$k]['qty'] += $amount;
            }
        }

        if ($totals === []) {
            return '';
        }

        usort($totals, function(array $a, array $b): int {
            $ta = $a['term_id'] ?? 0;
            $tb = $b['term_id'] ?? 0;
            return $ta <=> $tb;
        });

        $rows = '';
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit = (string) ($t['unit'] ?? '');
            $qty = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0 || $unit === '') {
                continue;
            }

            $termName = '';
            $term = get_term($termId, self::TAX_INGREDIENT);
            if ($term instanceof WP_Term) {
                $termName = $term->name;
            }

            if ($termName === '') {
                continue;
            }

            $rows .= '<tr>'
                . '<td>' . esc_html($termName) . '</td>'
                . '<td style="text-align:right;">' . esc_html($this->formatNumber($qty)) . '</td>'
                . '<td>' . esc_html($unit) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<table class="zs-event-ingredients">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Ingredient', 'zero-sense') . '</th>'
            . '<th style="text-align:right;">' . esc_html__('Total', 'zero-sense') . '</th>'
            . '<th>' . esc_html__('Unit', 'zero-sense') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    private function resolveOrderId($atts): ?int
    {
        $atts = is_array($atts) ? $atts : [];
        $orderId = isset($atts['order']) ? absint($atts['order']) : 0;

        if ($orderId <= 0 && isset($_GET['order'])) {
            $orderId = absint(wp_unslash((string) $_GET['order']));
        }

        if ($orderId <= 0) {
            global $post;
            if ($post instanceof WP_Post && $post->post_type === 'shop_order') {
                $orderId = (int) $post->ID;
            }
        }

        return $orderId > 0 ? $orderId : null;
    }

    private function getEquivalentPax(WC_Order $order): float
    {
        $adults = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies = (int) $order->get_meta(self::META_EVENT_BABIES, true);

        $eq = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        return $eq > 0 ? (float) $eq : 0.0;
    }

    private function getMaterialSchema(): array
    {
        $schema = get_option(self::OPTION_MATERIAL_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            return $this->getDefaultMaterialSchema();
        }

        $allowed = ['text', 'qty_int', 'bool', 'textarea'];
        $out = [];

        foreach ($schema as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            $type = isset($row['type']) ? sanitize_key((string) $row['type']) : 'text';

            if ($key === '' || $label === '') {
                continue;
            }

            if (!in_array($type, $allowed, true)) {
                $type = 'text';
            }

            $name = 'ops_material_label_' . $key;
            do_action('wpml_register_single_string', 'zero-sense', $name, $label);
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;

            $out[$key] = ['label' => $finalLabel, 'type' => $type];
        }

        return $out !== [] ? $out : $this->getDefaultMaterialSchema();
    }

    private function getDefaultMaterialSchema(): array
    {
        return [
            'vehicle' => ['label' => __('Vehicle', 'zero-sense'), 'type' => 'text'],
            'black_tablecloths' => ['label' => __('Black tablecloths', 'zero-sense'), 'type' => 'qty_int'],
            'cart' => ['label' => __('Cart', 'zero-sense'), 'type' => 'bool'],
            'work_tables' => ['label' => __('Work tables', 'zero-sense'), 'type' => 'qty_int'],
            'paella_pans' => ['label' => __('Paella pans', 'zero-sense'), 'type' => 'qty_int'],
            'burners' => ['label' => __('Burners', 'zero-sense'), 'type' => 'qty_int'],
            'tripods_legs' => ['label' => __('Tripods / legs', 'zero-sense'), 'type' => 'qty_int'],
            'butane' => ['label' => __('Butane', 'zero-sense'), 'type' => 'qty_int'],
            'hose' => ['label' => __('Hose', 'zero-sense'), 'type' => 'bool'],
            'parasols' => ['label' => __('Parasols', 'zero-sense'), 'type' => 'qty_int'],
            'tent' => ['label' => __('Tent', 'zero-sense'), 'type' => 'bool'],
            'lighting' => ['label' => __('Lighting', 'zero-sense'), 'type' => 'qty_int'],
            'water_fountain_8l' => ['label' => __('Water fountain (8L)', 'zero-sense'), 'type' => 'qty_int'],
            'trash_buckets' => ['label' => __('Trash buckets', 'zero-sense'), 'type' => 'qty_int'],
            'coolers' => ['label' => __('Coolers', 'zero-sense'), 'type' => 'qty_int'],
            'other' => ['label' => __('Other', 'zero-sense'), 'type' => 'textarea'],
        ];
    }

    private function formatNumber(float $n): string
    {
        $s = number_format($n, 3, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }
}
