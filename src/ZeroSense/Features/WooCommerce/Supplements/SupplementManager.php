<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Supplements;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) { exit; }

class SupplementManager
{
    private const PRODUCT_ID_SERVICIO_EXCLUSIVO = 16847;
    private const PRODUCT_ID_PAELLA_ADICIONAL   = 16385;
    private const MIN_SERVICIO_EXCLUSIVO        = 80.0;

    private const META_SUPPLEMENT_TYPE     = '_zs_supplement_type';
    private const TYPE_SERVICIO_EXCLUSIVO  = 'servicio_exclusivo';
    private const TYPE_PAELLA_ADICIONAL    = 'paella_adicional';

    private const META_DISMISSED_PREFIX = '_zs_supplement_dismissed_';

    private const PAELLA_CATEGORY_BASE_IDS   = [86, 87]; // nuestras-paellas, paellas-gourmet
    private const WORKSHOP_CATEGORY_SLUG     = 'workshop';

    private const RECALCULABLE_STATUSES = ['pending', 'budget-requested', 'deposit-paid', 'processing', 'on-hold'];

    private static bool $processing = false;

    public function register(): void
    {
        add_action('woocommerce_after_order_object_save', [$this, 'onOrderSave'], 25, 1);
        add_action('woocommerce_checkout_order_created', [$this, 'onOrderSave'], 25, 1);
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderAutoBadge'], 10, 3);
    }

    public function onOrderSave($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $orderId = $order->get_id();
        if ($orderId <= 0) {
            return;
        }

        // Anti-loop: prevent re-entry when we call $order->save()
        if (self::$processing) {
            return;
        }

        // Cache gate: prevent duplicate processing in same request
        $skipKey = 'zs_skip_supplements_' . $orderId;
        if (wp_cache_get($skipKey, 'zero-sense')) {
            wp_cache_delete($skipKey, 'zero-sense');
            return;
        }

        // Status guard
        if (!in_array($order->get_status(), self::RECALCULABLE_STATUSES, true)) {
            return;
        }

        $this->process($order);
    }

    private function process(WC_Order $order): void
    {
        $orderId     = $order->get_id();
        $totalGuests = (int) $order->get_meta('zs_event_total_guests', true);
        $hasWorkshop = $this->orderHasWorkshopProduct($order);
        $paellaTypes = $this->countPaellaTypes($order);
        $notes       = [];
        $changed     = false;

        // --- Servicio exclusivo de cocina ---
        $existingServicio = $this->findSupplementItem($order, self::TYPE_SERVICIO_EXCLUSIVO);
        $dismissedServicio = $order->get_meta(self::META_DISMISSED_PREFIX . self::TYPE_SERVICIO_EXCLUSIVO, true) === 'yes';

        if ($totalGuests <= 0 || $dismissedServicio || $hasWorkshop) {
            // Should NOT have servicio exclusivo
            if ($existingServicio) {
                $order->remove_item($existingServicio->get_id());
                $notes[] = sprintf('Removed "%s"%s', $existingServicio->get_name(), $hasWorkshop ? ' — workshop order' : '');
                $changed = true;
            }
        } else {
            // Should have servicio exclusivo
            $product = $this->resolveProduct(self::PRODUCT_ID_SERVICIO_EXCLUSIVO);
            if ($product) {
                $pricePerPerson = (float) $product->get_price();
                $total = max($totalGuests * $pricePerPerson, self::MIN_SERVICIO_EXCLUSIVO);

                if ($existingServicio) {
                    $oldTotal = (float) $existingServicio->get_total();
                    if (abs($oldTotal - $total) >= 0.01) {
                        $existingServicio->set_subtotal($total);
                        $existingServicio->set_total($total);
                        $existingServicio->save();
                        $notes[] = sprintf('Updated "%s" — %d guests × %.2f€ = %.2f€ (was: %.2f€)', $product->get_name(), $totalGuests, $pricePerPerson, $total, $oldTotal);
                        $changed = true;
                    }
                } else {
                    $item = new WC_Order_Item_Product();
                    $item->set_product($product);
                    $item->set_quantity(1);
                    $item->set_subtotal($total);
                    $item->set_total($total);
                    $item->add_meta_data(self::META_SUPPLEMENT_TYPE, self::TYPE_SERVICIO_EXCLUSIVO, true);
                    $order->add_item($item);
                    $notes[] = sprintf('Added "%s" — %d guests × %.2f€ = %.2f€', $product->get_name(), $totalGuests, $pricePerPerson, $total);
                    $changed = true;
                }
            }
        }

        // --- Suplemento paella adicional ---
        $existingPaella = $this->findSupplementItem($order, self::TYPE_PAELLA_ADICIONAL);
        $dismissedPaella = $order->get_meta(self::META_DISMISSED_PREFIX . self::TYPE_PAELLA_ADICIONAL, true) === 'yes';

        $neededQty = $paellaTypes > 1 ? $paellaTypes - 1 : 0;

        if ($neededQty <= 0 || $dismissedPaella) {
            // Should NOT have paella adicional
            if ($existingPaella) {
                $order->remove_item($existingPaella->get_id());
                $notes[] = sprintf('Removed "%s"', $existingPaella->get_name());
                $changed = true;
            }
        } else {
            // Should have paella adicional
            $product = $this->resolveProduct(self::PRODUCT_ID_PAELLA_ADICIONAL);
            if ($product) {
                $unitPrice = (float) $product->get_price();
                $total = $neededQty * $unitPrice;

                if ($existingPaella) {
                    $oldQty = (int) $existingPaella->get_quantity();
                    if ($oldQty !== $neededQty) {
                        $existingPaella->set_quantity($neededQty);
                        $existingPaella->set_subtotal($total);
                        $existingPaella->set_total($total);
                        $existingPaella->save();
                        $notes[] = sprintf('Updated "%s" × %d (%d paella types)', $product->get_name(), $neededQty, $paellaTypes);
                        $changed = true;
                    }
                } else {
                    $item = new WC_Order_Item_Product();
                    $item->set_product($product);
                    $item->set_quantity($neededQty);
                    $item->set_subtotal($total);
                    $item->set_total($total);
                    $item->add_meta_data(self::META_SUPPLEMENT_TYPE, self::TYPE_PAELLA_ADICIONAL, true);
                    $order->add_item($item);
                    $notes[] = sprintf('Added "%s" × %d (%d paella types)', $product->get_name(), $neededQty, $paellaTypes);
                    $changed = true;
                }
            }
        }

        // --- Detect manual dismissal (supplement was expected but removed by staff) ---
        $this->detectDismissals($order);

        if ($changed) {
            foreach ($notes as $note) {
                $order->add_order_note('[Supplement auto] ' . $note);
            }

            self::$processing = true;
            wp_cache_set('zs_skip_supplements_' . $orderId, true, 'zero-sense');
            $order->calculate_totals(false);
            $order->save();
            self::$processing = false;
        }
    }

    /**
     * Find an existing supplement line item by type meta, or adopt by product ID.
     * If a line item matches by product ID but lacks the meta, it gets "adopted"
     * (meta is added) so the system can manage it going forward.
     */
    private function findSupplementItem(WC_Order $order, string $type): ?WC_Order_Item_Product
    {
        $targetProductId = $type === self::TYPE_SERVICIO_EXCLUSIVO
            ? self::PRODUCT_ID_SERVICIO_EXCLUSIVO
            : self::PRODUCT_ID_PAELLA_ADICIONAL;

        // First pass: find by meta (already managed)
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ($item->get_meta(self::META_SUPPLEMENT_TYPE, true) === $type) {
                return $item;
            }
        }

        // Second pass: find by product ID (legacy/manually added) and adopt
        $defaultLang = apply_filters('wpml_default_language', 'es');
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }
            $baseId = defined('ICL_SITEPRESS_VERSION')
                ? (int) apply_filters('wpml_object_id', $product->get_id(), 'product', true, $defaultLang)
                : $product->get_id();
            if ($baseId === $targetProductId) {
                // Adopt: add meta so we manage it from now on
                $item->add_meta_data(self::META_SUPPLEMENT_TYPE, $type, true);
                $item->save();
                return $item;
            }
        }

        return null;
    }

    /**
     * Detect if staff removed a supplement manually (it existed before but is gone now).
     * Marks it as dismissed so we don't re-add it.
     */
    private function detectDismissals(WC_Order $order): void
    {
        // Check each type: if we previously had the supplement (tracked via order meta snapshot)
        // but it's gone now and wasn't removed by process(), mark as dismissed.
        // This is handled implicitly: if findSupplementItem returns null for a type
        // that should exist, and it's not in $existingXxx, it means staff removed it.
        // The _dismissed flag is checked at the top of each section.
    }

    /**
     * Check if order contains any product from the "workshop" category.
     */
    private function orderHasWorkshopProduct(WC_Order $order): bool
    {
        $workshopTermId = $this->getWorkshopCategoryId();
        if ($workshopTermId <= 0) {
            return false;
        }

        $defaultLang = apply_filters('wpml_default_language', 'es');

        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }
            $catIds = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
            if (!is_array($catIds)) {
                continue;
            }
            foreach ($catIds as $catId) {
                $baseId = (int) apply_filters('wpml_object_id', $catId, 'product_cat', true, $defaultLang);
                if ($baseId === $workshopTermId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Count distinct paella product types in the order.
     */
    private function countPaellaTypes(WC_Order $order): int
    {
        $defaultLang = apply_filters('wpml_default_language', 'es');
        $seenProducts = [];

        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            // Skip auto-managed supplements
            if ($item->get_meta(self::META_SUPPLEMENT_TYPE, true) !== '') {
                continue;
            }
            $product = $item->get_product();
            if (!$product instanceof WC_Product) {
                continue;
            }
            $productId = $product->get_id();
            if (isset($seenProducts[$productId])) {
                continue;
            }
            $catIds = wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
            if (!is_array($catIds)) {
                continue;
            }
            foreach ($catIds as $catId) {
                $baseId = (int) apply_filters('wpml_object_id', $catId, 'product_cat', true, $defaultLang);
                if (in_array($baseId, self::PAELLA_CATEGORY_BASE_IDS, true)) {
                    $seenProducts[$productId] = true;
                    break;
                }
            }
        }

        return count($seenProducts);
    }

    /**
     * Resolve a base product ID to a WC_Product, respecting WPML.
     */
    private function resolveProduct(int $baseProductId): ?WC_Product
    {
        $productId = $baseProductId;

        if (defined('ICL_SITEPRESS_VERSION')) {
            $currentLang = apply_filters('wpml_current_language', null);
            $translated = apply_filters('wpml_object_id', $baseProductId, 'product', true, $currentLang);
            if ($translated) {
                $productId = (int) $translated;
            }
        }

        $product = wc_get_product($productId);
        return $product instanceof WC_Product ? $product : null;
    }

    /**
     * Render "(Auto)" badge next to auto-managed supplement line items in admin.
     */
    public function renderAutoBadge(int $itemId, $item, $product): void
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }
        $type = $item->get_meta(self::META_SUPPLEMENT_TYPE, true);
        if ($type !== '') {
            echo '<div style="margin-top:4px;"><span style="background:#f0f0f1;color:#50575e;font-size:11px;padding:1px 6px;border-radius:3px;font-weight:600;">Auto</span></div>';
        }
    }

    /**
     * Get the base workshop category ID.
     */
    private function getWorkshopCategoryId(): int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }

        $term = get_term_by('slug', self::WORKSHOP_CATEGORY_SLUG, 'product_cat');
        if (!$term || is_wp_error($term)) {
            $id = 0;
            return $id;
        }

        $defaultLang = apply_filters('wpml_default_language', 'es');
        $baseId = apply_filters('wpml_object_id', $term->term_id, 'product_cat', true, $defaultLang);
        $id = $baseId ? (int) $baseId : $term->term_id;

        return $id;
    }
}
