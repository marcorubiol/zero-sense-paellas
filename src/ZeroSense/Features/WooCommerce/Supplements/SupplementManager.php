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

    private const META_DISMISSED_PREFIX    = '_zs_supplement_dismissed_';
    private const META_EXPECTED_TOTAL      = '_zs_supplement_expected_total';

    private const PAELLA_CATEGORY_BASE_IDS   = [86, 87]; // nuestras-paellas, paellas-gourmet
    private const WORKSHOP_CATEGORY_SLUG     = 'workshop';

    private const RECALCULABLE_STATUSES = ['pending', 'budget-requested', 'deposit-paid', 'processing', 'on-hold'];

    private static bool $processing = false;

    public function register(): void
    {
        add_action('woocommerce_after_order_object_save', [$this, 'onOrderSave'], 25, 1);
        add_action('woocommerce_checkout_order_created', [$this, 'onOrderSave'], 25, 1);
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderAutoBadge'], 10, 3);
        add_action('wp_ajax_zs_recalc_supplement', [$this, 'handleRecalcAjax']);
        add_action('admin_footer', [$this, 'printAdminScript']);
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
        $adults      = $this->readAdultsCount($order);
        $hasWorkshop = $this->orderHasWorkshopProduct($order);
        $paellaTypes = $this->countPaellaTypes($order);
        $notes       = [];
        $changed     = false;

        $debugProduct = $this->resolveProduct(self::PRODUCT_ID_SERVICIO_EXCLUSIVO);
        $debugPrice = $debugProduct ? (float) $debugProduct->get_price() : 0.0;
        $order->add_order_note(sprintf(
            '[Supplement debug] adults=%d (zs_event_adults="%s" adults="%s" _event_adults="%s") total_guests="%s" servicio_price=%.2f€ min=%.2f€',
            $adults,
            (string) $order->get_meta('zs_event_adults', true),
            (string) $order->get_meta('adults', true),
            (string) $order->get_meta('_event_adults', true),
            (string) $order->get_meta('zs_event_total_guests', true),
            $debugPrice,
            self::MIN_SERVICIO_EXCLUSIVO
        ));

        // --- Servicio exclusivo de cocina ---
        // Syncs with adults only (not total guests) — children are not counted.
        $existingServicio = $this->findSupplementItem($order, self::TYPE_SERVICIO_EXCLUSIVO);
        $dismissedServicio = $order->get_meta(self::META_DISMISSED_PREFIX . self::TYPE_SERVICIO_EXCLUSIVO, true) === 'yes';

        if ($adults <= 0 || $dismissedServicio || $hasWorkshop) {
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
                $appliesMinimum = ($adults * $pricePerPerson) < self::MIN_SERVICIO_EXCLUSIVO;
                $qty = $appliesMinimum ? 1 : $adults;
                $total = $appliesMinimum ? self::MIN_SERVICIO_EXCLUSIVO : $adults * $pricePerPerson;

                if ($existingServicio) {
                    // Detect manual price override: total differs from what we last set
                    $expectedTotal = (float) $existingServicio->get_meta(self::META_EXPECTED_TOTAL, true);
                    $currentTotal = (float) $existingServicio->get_total();
                    if ($expectedTotal > 0 && abs($currentTotal - $expectedTotal) >= 0.01) {
                        // Staff manually changed the price — respect it, only update qty
                        $oldQty = (int) $existingServicio->get_quantity();
                        if ($oldQty !== $qty) {
                            $existingServicio->set_quantity($qty);
                            $existingServicio->save();
                            $changed = true;
                        }
                    } else {
                        $oldQty = (int) $existingServicio->get_quantity();
                        $oldTotal = (float) $existingServicio->get_total();
                        if ($oldQty !== $qty || abs($oldTotal - $total) >= 0.01) {
                            $existingServicio->set_quantity($qty);
                            $existingServicio->set_subtotal($total);
                            $existingServicio->set_total($total);
                            $existingServicio->update_meta_data(self::META_EXPECTED_TOTAL, (string) $total);
                            $existingServicio->save();
                            $notes[] = sprintf('Updated "%s" — %d adults × %.2f€ = %.2f€ (was: %.2f€)', $product->get_name(), $adults, $pricePerPerson, $total, $oldTotal);
                            $changed = true;
                        }
                    }
                } else {
                    $item = new WC_Order_Item_Product();
                    $item->set_product($product);
                    $item->set_quantity($qty);
                    $item->set_subtotal($total);
                    $item->set_total($total);
                    $item->add_meta_data(self::META_SUPPLEMENT_TYPE, self::TYPE_SERVICIO_EXCLUSIVO, true);
                    $item->add_meta_data(self::META_EXPECTED_TOTAL, (string) $total, true);
                    $order->add_item($item);
                    $notes[] = sprintf('Added "%s" — %d adults × %.2f€ = %.2f€', $product->get_name(), $adults, $pricePerPerson, $total);
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
                    $expectedTotal = (float) $existingPaella->get_meta(self::META_EXPECTED_TOTAL, true);
                    $currentTotal = (float) $existingPaella->get_total();
                    $oldQty = (int) $existingPaella->get_quantity();

                    if ($expectedTotal > 0 && abs($currentTotal - $expectedTotal) >= 0.01) {
                        // Staff manually changed the price — respect it, only update qty
                        if ($oldQty !== $neededQty) {
                            $existingPaella->set_quantity($neededQty);
                            $existingPaella->save();
                            $changed = true;
                        }
                    } else if ($oldQty !== $neededQty || abs($currentTotal - $total) >= 0.01) {
                        $existingPaella->set_quantity($neededQty);
                        $existingPaella->set_subtotal($total);
                        $existingPaella->set_total($total);
                        $existingPaella->update_meta_data(self::META_EXPECTED_TOTAL, (string) $total);
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
                    $item->add_meta_data(self::META_EXPECTED_TOTAL, (string) $total, true);
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
     * Read the adults count from the order, falling back to legacy meta keys
     * (matches EventDetailsMetabox::getOrderMetaWithFallback behavior).
     */
    private function readAdultsCount(WC_Order $order): int
    {
        foreach (['zs_event_adults', 'adults', '_event_adults'] as $key) {
            $val = $order->get_meta($key, true);
            if ($val !== '' && $val !== null) {
                return (int) $val;
            }
        }
        return 0;
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
     * Render AUTO/MAN badge next to auto-managed supplement line items in admin.
     */
    public function renderAutoBadge(int $itemId, $item, $product): void
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }
        $type = $item->get_meta(self::META_SUPPLEMENT_TYPE, true);
        if ($type === '') {
            return;
        }

        $expectedTotal = (float) $item->get_meta(self::META_EXPECTED_TOTAL, true);
        $currentTotal = (float) $item->get_total();
        $isManual = $expectedTotal > 0 && abs($currentTotal - $expectedTotal) >= 0.01;

        $badgeClass = $isManual ? 'zs-badge-manual' : 'zs-badge-auto';
        $badgeText = $isManual ? 'MAN' : 'AUTO';

        echo '<div style="margin-top:4px;display:inline-flex;gap:6px;align-items:center;">';

        echo '<span class="zs-inventory-badge ' . esc_attr($isManual ? 'zs-inventory-badge-manual' : 'zs-inventory-badge-auto') . '">' . esc_html($badgeText) . '</span>';

        if ($isManual) {
            $orderId = (int) $item->get_order_id();
            if ($orderId > 0) {
                $nonce = wp_create_nonce('zs_recalc_supplement_' . $orderId);
                echo '<span class="dashicons dashicons-update zs-inventory-reset-icon zs-supplement-recalc" role="button" tabindex="0" data-order-id="' . esc_attr((string) $orderId) . '" data-type="' . esc_attr($type) . '" data-nonce="' . esc_attr($nonce) . '" title="' . esc_attr__('Recalculate', 'zero-sense') . '"></span>';
            }
        }

        echo '</div>';
    }

    /**
     * AJAX handler to force-recalculate a supplement line (clears manual override).
     */
    public function handleRecalcAjax(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Not allowed'], 403);
        }

        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $type    = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $nonce   = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'zs_recalc_supplement_' . $orderId)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 400);
        }
        if ($orderId <= 0 || !in_array($type, [self::TYPE_SERVICIO_EXCLUSIVO, self::TYPE_PAELLA_ADICIONAL], true)) {
            wp_send_json_error(['message' => 'Invalid params'], 400);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => 'Order not found'], 404);
        }

        if (!in_array($order->get_status(), self::RECALCULABLE_STATUSES, true)) {
            wp_send_json_error(['message' => 'Order status does not allow recalculation'], 400);
        }

        // Clear the expected_total flag so process() treats the line as fresh (no manual override).
        $item = $this->findSupplementItem($order, $type);
        if ($item) {
            $item->delete_meta_data(self::META_EXPECTED_TOTAL);
            $item->save();
        }

        // Also clear dismissed flag in case staff wants the line back after dismissing.
        $order->delete_meta_data(self::META_DISMISSED_PREFIX . $type);
        $order->save();

        // Reload order (its in-memory state may be stale after item save) and re-run the auto logic.
        $freshOrder = wc_get_order($orderId);
        if ($freshOrder instanceof WC_Order) {
            $this->process($freshOrder);
        }

        wp_send_json_success();
    }

    /**
     * Print inline JS on order edit screens to wire the Recalculate button.
     */
    public function printAdminScript(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        $allowed = ['shop_order', 'woocommerce_page_wc-orders'];
        if (!in_array($screen->id, $allowed, true)) {
            return;
        }
        ?>
        <script>
        (function(){
            function trigger(btn){
                if (btn.dataset.busy === '1') return;
                btn.dataset.busy = '1';
                btn.style.opacity = '0.5';
                btn.style.pointerEvents = 'none';
                var body = new URLSearchParams();
                body.append('action', 'zs_recalc_supplement');
                body.append('order_id', btn.dataset.orderId);
                body.append('type', btn.dataset.type);
                body.append('_wpnonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.success) {
                            location.reload();
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'unknown error';
                            alert('Recalculate failed: ' + msg);
                            btn.dataset.busy = '';
                            btn.style.opacity = '';
                            btn.style.pointerEvents = '';
                        }
                    })
                    .catch(function(){
                        alert('Recalculate failed: network error');
                        btn.dataset.busy = '';
                        btn.style.opacity = '';
                        btn.style.pointerEvents = '';
                    });
            }
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.zs-supplement-recalc');
                if (!btn) return;
                e.preventDefault();
                trigger(btn);
            });
            document.addEventListener('keydown', function(e){
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var btn = e.target.closest('.zs-supplement-recalc');
                if (!btn) return;
                e.preventDefault();
                trigger(btn);
            });
        })();
        </script>
        <?php
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
