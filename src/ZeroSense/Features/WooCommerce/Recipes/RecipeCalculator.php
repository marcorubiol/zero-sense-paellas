<?php
namespace ZeroSense\Features\WooCommerce\Recipes;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

class RecipeCalculator
{
    const ADULT_WEIGHT  = 1.0;
    const CHILD_WEIGHT  = 0.4;
    const BABY_WEIGHT   = 0.0;
    const SAFETY_MARGIN = 1.13;
    const PAELLA_MIN_PAX_THRESHOLD = 13;
    const PAELLA_MIN_PAX_RECIPE    = 15;

    const META_EVENT_ADULTS   = 'zs_event_adults';
    const META_EVENT_CHILDREN = 'zs_event_children_5_to_8';
    const META_EVENT_BABIES   = 'zs_event_children_0_to_4';

    const META_RECIPE_ID        = 'zs_recipe_id';
    const META_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    const META_RABBIT_CHOICE    = '_zs_rabbit_choice';
    const META_RECIPE_ING       = 'zs_recipe_ingredients';
    const META_RECIPE_LIQUIDS   = 'zs_recipe_liquids';
    const META_NEEDS_PAELLA     = 'zs_recipe_needs_paella';
    const META_ITEM_CHILDREN    = '_zs_item_children';

    /**
     * Ratio: equivalent pax / total pax — applies adult/child/baby weights.
     */
    public static function getPaxRatio(WC_Order $order): float
    {
        $adults   = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies   = (int) $order->get_meta(self::META_EVENT_BABIES, true);
        $total    = $adults + $children + $babies;
        if ($total <= 0) {
            return 1.0;
        }
        $eq = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        return $eq / $total;
    }

    /**
     * Ratio: adults only / total pax — for non-paella recipes (ignores child/baby weights).
     */
    public static function getAdultsRatio(WC_Order $order): float
    {
        $adults   = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies   = (int) $order->get_meta(self::META_EVENT_BABIES, true);
        $total    = $adults + $children + $babies;
        if ($total <= 0) {
            return 1.0;
        }
        return $adults / $total;
    }

    /**
     * Resolve the WPML-original product ID (handles variable products + WPML language).
     */
    public static function resolveOriginalProductId(int $productId): int
    {
        $parentId = wp_get_post_parent_id($productId);
        $checkId  = $parentId ? $parentId : $productId;

        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $checkId;
        }

        $defaultLang = apply_filters('wpml_default_language', null);
        $originalId  = apply_filters('wpml_object_id', $checkId, 'product', true, $defaultLang);
        return $originalId ? (int) $originalId : $checkId;
    }

    /**
     * Resolve the recipe ID for an order item (handles rabbit choice + WPML).
     */
    public static function resolveRecipeId(WC_Order_Item_Product $item, WC_Product $product): int
    {
        $checkId = self::resolveOriginalProductId($product->get_id());

        $recipeId = (int) get_post_meta($checkId, self::META_RECIPE_ID, true);
        if ($recipeId <= 0) {
            return 0;
        }

        if ($item->get_meta(self::META_RABBIT_CHOICE, true) === 'without') {
            $noRabbit = (int) get_post_meta($checkId, self::META_RECIPE_NO_RABBIT, true);
            return $noRabbit > 0 ? $noRabbit : $recipeId;
        }

        return $recipeId;
    }

    /**
     * Get eligible items from an order with pre-calculated eq (qty * paxRatio).
     * Returns [['recipe_id' => int, 'qty' => float, 'eq' => float], ...]
     */
    public static function getEligibleItems(WC_Order $order, bool $paellaOnly = false): array
    {
        $paxRatio = self::getPaxRatio($order);
        $lineItems   = $order->get_items('line_item');
        if (!$lineItems) {
            return [];
        }

        $eligible = [];
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
                continue;
            }
            $recipeId = self::resolveRecipeId($item, $product);
            if ($recipeId <= 0) {
                continue;
            }
            $isPaella = get_post_meta($recipeId, self::META_NEEDS_PAELLA, true) === '1';
            if ($paellaOnly && !$isPaella) {
                continue;
            }
            if ($isPaella) {
                $rawMeta = $item->get_meta(self::META_ITEM_CHILDREN, true);
                if ($rawMeta !== '' && $rawMeta !== false && $rawMeta !== null) {
                    $itemChildren = max(0, min((int) $rawMeta, (int) $qty));
                    $itemAdults   = max(0, (int) $qty - $itemChildren);
                    $rawEq        = $itemAdults + $itemChildren * self::CHILD_WEIGHT;
                } else {
                    $rawEq = $qty * $paxRatio;
                }
                if ($rawEq < self::PAELLA_MIN_PAX_THRESHOLD) {
                    $eq = self::PAELLA_MIN_PAX_RECIPE;
                } else {
                    $eq = round($rawEq * self::SAFETY_MARGIN);
                }
            } else {
                $eq = $qty;
            }
            $eligible[] = [
                'recipe_id' => $recipeId,
                'qty'       => $qty,
                'eq'        => $eq,
            ];
        }

        return $eligible;
    }

    /**
     * Aggregate ingredients from eligible items with 13% safety margin.
     * Returns [termId.'|'.unit => ['term_id' => int, 'unit' => string, 'qty' => float], ...]
     */
    public static function aggregateIngredients(array $eligible): array
    {
        $totals = [];
        foreach ($eligible as $row) {
            $recipeId = (int) $row['recipe_id'];
            $eq       = (float) $row['eq'];
            if ($eq <= 0) {
                continue;
            }
            $recipeIng = get_post_meta($recipeId, self::META_RECIPE_ING, true);
            if (!is_array($recipeIng)) {
                continue;
            }
            foreach ($recipeIng as $ingRow) {
                if (!is_array($ingRow)) {
                    continue;
                }
                $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                $perPax = isset($ingRow['qty'])        ? (float) $ingRow['qty']       : 0.0;
                $unit   = isset($ingRow['unit'])       ? sanitize_key((string) $ingRow['unit']) : '';
                if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                    continue;
                }
                $amount = $eq * $perPax;
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

        usort($totals, fn(array $a, array $b): int => ($a['term_id'] ?? 0) <=> ($b['term_id'] ?? 0));

        return $totals;
    }

    /**
     * Aggregate liquids from eligible items (paella recipes only) with 13% safety margin.
     * Returns [termId => ['term_id' => int, 'qty' => float], ...]
     */
    public static function aggregateLiquids(array $eligible): array
    {
        $totals = [];
        foreach ($eligible as $row) {
            $recipeId = (int) $row['recipe_id'];
            $eq       = (float) $row['eq'];
            if ($eq <= 0) {
                continue;
            }
            if (get_post_meta($recipeId, self::META_NEEDS_PAELLA, true) !== '1') {
                continue;
            }
            $recipeLiquids = get_post_meta($recipeId, self::META_RECIPE_LIQUIDS, true);
            if (!is_array($recipeLiquids)) {
                continue;
            }
            foreach ($recipeLiquids as $liqRow) {
                if (!is_array($liqRow)) {
                    continue;
                }
                $termId       = isset($liqRow['liquid']) ? (int) $liqRow['liquid']   : 0;
                $litresPerPax = isset($liqRow['qty'])    ? (float) $liqRow['qty']     : 0.0;
                if ($termId <= 0 || $litresPerPax <= 0) {
                    continue;
                }
                $amount = $litresPerPax * $eq;
                if ($amount <= 0) {
                    continue;
                }
                $k = (string) $termId;
                if (!isset($totals[$k])) {
                    $totals[$k] = ['term_id' => $termId, 'qty' => 0.0];
                }
                $totals[$k]['qty'] += $amount;
            }
        }

        return $totals;
    }

    /**
     * Normalize units: g→kg, ml→l, etc.
     * Returns ['qty' => float, 'unit' => string]
     */
    public static function normalizeUnit(float $qty, string $unit): array
    {
        if ($unit === 'cn') {
            return ['qty' => $qty, 'unit' => 'c/n'];
        }
        if ($unit === 'g'  && $qty >= 1000) { return ['qty' => $qty / 1000, 'unit' => 'kg']; }
        if ($unit === 'kg' && $qty < 1)     { return ['qty' => $qty * 1000, 'unit' => 'gr']; }
        if ($unit === 'ml' && $qty >= 1000) { return ['qty' => $qty / 1000, 'unit' => 'lit']; }
        if ($unit === 'l'  && $qty < 1)     { return ['qty' => $qty * 1000, 'unit' => 'ml']; }
        $map = ['g' => 'gr', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'lit', 'u' => 'pcs', 'cn' => 'c/n'];
        return ['qty' => $qty, 'unit' => $map[$unit] ?? $unit];
    }
}
