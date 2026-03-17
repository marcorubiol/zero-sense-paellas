<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Post;
use WP_Term;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\Recipes\RecipeCalculator;

class EventShortcodes implements FeatureInterface
{
    private const META_OPS_INFRASTRUCTURE = 'zs_ops_infrastructure';

    private const META_EVENT_ADULTS = 'zs_event_adults';
    private const META_EVENT_CHILDREN = 'zs_event_children_5_to_8';
    private const META_EVENT_BABIES = 'zs_event_children_0_to_4';

    private const OPTION_INFRASTRUCTURE_SCHEMA = 'zs_ops_infrastructure_schema';

    private const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    private const META_PRODUCT_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    private const META_RABBIT_CHOICE = '_zs_rabbit_choice';
    private const META_RECIPE_INGREDIENTS = 'zs_recipe_ingredients';
    private const TAX_INGREDIENT = 'zs_ingredient';

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
        add_shortcode('zs_event_infrastructure', [$this, 'shortcodeInfrastructure']);
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
     * [zs_event_infrastructure order="123"]
     */
    public function shortcodeInfrastructure($atts): string
    {
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta(self::META_OPS_INFRASTRUCTURE, true);
        $infrastructure = is_array($raw) ? $raw : [];

        $schema = $this->getInfrastructureSchema();
        if ($schema === []) {
            return '';
        }

        $rows = '';
        foreach ($schema as $key => $def) {
            $label = $def['label'] ?? $key;
            $type = $def['type'] ?? 'text';

            $entry = $infrastructure[$key] ?? null;
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

        return '<table class="zs-event-infrastructure">'
            . '<thead><tr>'
            . '<th>' . esc_html__('Complementary infrastructure', 'zero-sense') . '</th>'
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

        $eligible = RecipeCalculator::getEligibleItems($order);
        if ($eligible === []) {
            return '';
        }

        $totals = RecipeCalculator::aggregateIngredients($eligible);
        if ($totals === []) {
            return '';
        }

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

    private function getInfrastructureSchema(): array
    {
        $schema = get_option(self::OPTION_INFRASTRUCTURE_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            return [];
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

            $name = 'ops_infrastructure_label_' . $key;
            do_action('wpml_register_single_string', 'zero-sense', $name, $label);
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;

            $out[$key] = ['label' => $finalLabel, 'type' => $type];
        }

        return $out;
    }

    private function formatNumber(float $n): string
    {
        $s = number_format($n, 3, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }
}
