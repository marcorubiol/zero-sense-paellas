<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use WP_Post;
use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ManualOverride;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ReservationManager;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertResolutionManager;

class InventoryMetabox
{
    const NONCE_FIELD = 'zs_inventory_nonce';
    const NONCE_ACTION = 'zs_inventory_save';
    
    /**
     * Registra el metabox
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        // Priority 50: Run AFTER WooCommerce saves order items (priority 10-40)
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 50, 2);
        add_action('wp_ajax_zs_save_inventory', [$this, 'ajaxSave']);
        add_action('wp_ajax_zs_clear_inventory_overrides', [$this, 'ajaxClearOverrides']);
        add_action('wp_ajax_zs_resolve_inventory_alert', [$this, 'ajaxResolveAlert']);
        add_action('wp_ajax_zs_undo_inventory_alert_resolution', [$this, 'ajaxUndoAlertResolution']);
    }
    
    /**
     * Añade el metabox
     */
    public function addMetabox(): void
    {
        add_meta_box(
            'zs_inventory_materials',
            __('Inventory & Materials', 'zero-sense'),
            [$this, 'render'],
            wc_get_page_screen_id('shop-order'),
            'normal',
            'high'
        );
    }
    
    /**
     * Renderiza el metabox
     */
    public function render(\WC_Order $order): void
    {
        $postId = $order->get_id();
        
        if (!$order) {
            return;
        }
        
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        
        // Calcular materiales automáticamente solo si el pedido tiene items cargados
        // En el render inicial, los items pueden no estar disponibles aún
        $orderItems = $order->get_items();
        if (!empty($orderItems)) {
            $calculated = MaterialCalculator::calculate($order);
        } else {
            // Si no hay items, usar array vacío (se calculará al guardar)
            $calculated = [];
        }
        
        // Obtener overrides manuales
        $overrides = ManualOverride::get($postId);
        
        // Aplicar overrides
        $final = ManualOverride::apply($calculated, $overrides);
        
        // Calcular alertas de stock
        $alerts = !empty($calculated) ? AlertCalculator::calculateAlerts($order, $final) : [];
        
        // Obtener resoluciones de alertas
        $resolutions = AlertResolutionManager::getResolutions($postId);
        
        // Obtener todas las definiciones de materiales
        $materials = MaterialDefinitions::getAll();
        $parentCategories = MaterialDefinitions::getParentCategories();
        
        // Agrupar por parent_category y luego por category
        $groupedByParent = [];
        foreach ($materials as $material) {
            $parentCat = $material['parent_category'] ?? 'altres';
            $cat = $material['category'] ?? 'altres';
            
            if (!isset($groupedByParent[$parentCat])) {
                $groupedByParent[$parentCat] = [];
            }
            if (!isset($groupedByParent[$parentCat][$cat])) {
                $groupedByParent[$parentCat][$cat] = [];
            }
            $groupedByParent[$parentCat][$cat][] = $material;
        }
        
        // Nombres de categorías
        $categoryLabels = [
            'paelles' => 'Paelles',
            'cremadors' => 'Cremadors',
            'equipament_cuina' => 'Equipament Cuina',
            'roba_personal' => 'Roba Personal',
            'textils_neteja' => 'Textils i Neteja',
            'caixes_contenidors' => 'Caixes i Contenidors',
            'refrigeracio' => 'Refrigeració',
            'utensilis_servir' => 'Utensilis Servir',
            'mobiliari_esdeveniments' => 'Mobiliari Esdeveniments',
            'vaixella_menatge' => 'Vaixella i Menatge',
            'altres' => 'Altres',
        ];
        
        ?>
        <div class="zs-inventory-metabox">
            <style>
                .zs-inventory-metabox input[type="number"] {
                    width: 60px;
                }
                .zs-inventory-metabox input[type="number"]:disabled {
                    background: transparent;
                    border: none;
                    color: #2c3338;
                    font-weight: 500;
                    cursor: default;
                    -moz-appearance: textfield;
                }
                
                /* Hide arrows in Chrome, Safari, Edge when disabled */
                .zs-inventory-metabox input[type="number"]:disabled::-webkit-outer-spin-button,
                .zs-inventory-metabox input[type="number"]:disabled::-webkit-inner-spin-button {
                    -webkit-appearance: none;
                    margin: 0;
                }
                
                .zs-inventory-header {
                    display: flex;
                    justify-content: flex-end;
                    align-items: center;
                    margin-bottom: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #ddd;
                }
                .zs-inventory-controls {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                }
                .zs-inventory-lock-btn,
                .zs-inventory-recalc-btn,
                .zs-inventory-save-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 6px 12px;
                    border: 1px solid #ddd;
                    background: white;
                    cursor: pointer;
                    border-radius: 3px;
                    font-size: 13px;
                    transition: all 0.2s ease;
                }
                .zs-inventory-lock-btn:hover,
                .zs-inventory-recalc-btn:hover,
                .zs-inventory-save-btn:hover,
                .zs-inventory-lock-btn:focus,
                .zs-inventory-recalc-btn:focus,
                .zs-inventory-save-btn:focus {
                    background: #f5f5f5;
                    border-color: #999;
                    box-shadow: none;
                    outline: none;
                }
                .zs-inventory-lock-btn[data-locked="true"] {
                    color: #d63638;
                }
                .zs-inventory-lock-btn[data-locked="false"] {
                    color: #2271b1;
                }
                .zs-inventory-save-btn {
                    color: #00a32a;
                }
                .zs-inventory-save-btn:hover {
                    color: #008a20;
                }
                .zs-inventory-metabox table th:nth-child(2),
                .zs-inventory-metabox table td:nth-child(2) {
                    width: 200px;
                    text-align: right;
                }
                .zs-inventory-quantity-wrapper {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    justify-content: flex-end;
                }
                .zs-inventory-badge-container {
                    display: inline-block;
                    min-width: 45px;
                    text-align: center;
                }
                .zs-inventory-badge {
                    display: inline-block;
                    padding: 2px 6px;
                    font-size: 10px;
                    font-weight: 600;
                    border-radius: 3px;
                    text-transform: uppercase;
                }
                .zs-inventory-badge-auto {
                    background: #d7f0ff;
                    color: #0073aa;
                }
                .zs-inventory-badge-manual {
                    background: #fff3cd;
                    color: #856404;
                }
                .zs-inventory-reset-icon {
                    cursor: pointer;
                    color: #666;
                    transition: all 0.2s ease;
                    font-size: 16px;
                    line-height: 1;
                }
                .zs-inventory-reset-icon:hover {
                    transform: rotate(-45deg);
                    color: #2271b1;
                }
                .zs-inventory-reset-icon.hidden {
                    display: none;
                }
                .zs-inventory-description {
                    font-size: 11px;
                    color: #666;
                    font-style: italic;
                }
                .zs-inventory-accordion {
                    margin-bottom: 15px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    overflow: hidden;
                }
                .zs-inventory-accordion-header {
                    cursor: pointer;
                    padding: 12px 15px;
                    background: #fff;
                    font-weight: 600;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    user-select: none;
                }
                .zs-inventory-accordion-header:hover {
                    background: #f6f7f7;
                }
                .zs-inventory-accordion-icon {
                    transition: transform 0.2s ease;
                    font-size: 16px;
                }
                .zs-inventory-accordion.collapsed .zs-inventory-accordion-icon {
                    transform: rotate(-90deg);
                }
                .zs-inventory-accordion-content {
                    max-height: 5000px;
                    overflow: hidden;
                    transition: max-height 0.3s ease;
                }
                .zs-inventory-accordion.collapsed .zs-inventory-accordion-content {
                    max-height: 0;
                }
                /* Category headers - higher specificity to override WordPress striped rows */
                .zs-inventory-metabox table.widefat tr.zs-inventory-category-header,
                .zs-inventory-metabox table.striped tr.zs-inventory-category-header {
                    background: #e8e8e9 !important;
                    font-weight: 600;
                    font-size: 12px;
                    text-transform: uppercase;
                    color: #1d2327;
                }
                .zs-inventory-category-header td {
                    padding: 10px 12px !important;
                    background: #e8e8e9 !important;
                }
                .zs-inventory-accordion-content table {
                    border: none !important;
                    box-shadow: none !important;
                    margin: 0 !important;
                }
                .zs-inventory-accordion-content table thead {
                    border-bottom: 1px solid #ddd;
                }
                .zs-inventory-accordion-content table tbody tr:last-child td {
                    border-bottom: none;
                }
                .zs-inventory-save-btn.is-saving {
                    opacity: 0.7;
                    pointer-events: none;
                }
                .zs-inventory-toast {
                    position: fixed;
                    bottom: 30px;
                    right: 30px;
                    padding: 14px 20px;
                    border-radius: 4px;
                    font-size: 14px;
                    font-weight: 500;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    z-index: 999;
                    min-width: 250px;
                    background: white;
                    color: #1d2327;
                    border-left: 4px solid;
                    display: none;
                }
                .zs-inventory-toast.show {
                    display: block;
                    animation: slideIn 0.3s ease;
                }
                .zs-inventory-toast.success {
                    border-left-color: #46b450;
                }
                .zs-inventory-toast.error {
                    border-left-color: #dc3232;
                }
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateX(100px);
                    }
                    to {
                        opacity: 1;
                        transform: translateX(0);
                    }
                }
                
                /* Stock Alerts */
                .zs-stock-alerts-banner {
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    margin-bottom: 15px;
                }
                .zs-alert-details {
                    max-height: 2000px;
                    overflow: hidden;
                    padding: 10px 15px 15px 15px;
                    transition: max-height 0.25s ease-out, padding 0.25s ease-out;
                }
                .zs-stock-alerts-banner.collapsed .zs-alert-details {
                    max-height: 0;
                    padding: 0 15px;
                    transition: max-height 0.25s ease-out, padding 0.25s ease-out;
                }
                .zs-stock-alerts-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    cursor: pointer;
                    user-select: none;
                    padding: 10px 15px;
                    transition: all 0.2s ease;
                }
                .zs-stock-alerts-header:hover {
                    background-color: #f8f9fa;
                }
                .zs-stock-alerts-banner:not(.collapsed) .zs-stock-alerts-header {
                    padding: 15px;
                    border-bottom: 1px solid #f0f0f1;
                }
                .zs-stock-alerts-banner.collapsed .zs-stock-alerts-header {
                    border-bottom: none;
                }
                .zs-stock-alerts-banner.collapsed {
                    filter: grayscale(0.5);
                }
                .zs-stock-alerts-banner.collapsed .zs-stock-alerts-title {
                    font-size: 12px;
                    font-weight: 500;
                    opacity: 0.85;
                }
                .zs-stock-alerts-banner.collapsed .zs-stock-alerts-summary {
                    font-size: 11px;
                }
                .zs-stock-alerts-banner.collapsed .zs-alert-count {
                    gap: 3px;
                }
                .zs-stock-alerts-banner.collapsed .zs-alert-count .dashicons {
                    width: 14px;
                    height: 14px;
                    font-size: 14px;
                }
                .zs-stock-alerts-title {
                    font-weight: 600;
                    font-size: 14px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .zs-stock-alerts-toggle {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    font-size: 12px;
                    color: #666;
                }
                .zs-stock-alerts-toggle .dashicons {
                    width: 16px;
                    height: 16px;
                    font-size: 16px;
                    transition: transform 0.2s ease;
                }
                .zs-stock-alerts-banner.collapsed .zs-stock-alerts-toggle .dashicons {
                    width: 14px;
                    height: 14px;
                    font-size: 14px;
                }
                .zs-stock-alerts-banner.collapsed .zs-stock-alerts-toggle .dashicons {
                    transform: rotate(-90deg);
                }
                .zs-stock-alerts-summary {
                    display: flex;
                    gap: 15px;
                    font-size: 13px;
                }
                .zs-alert-count {
                    display: flex;
                    align-items: center;
                    gap: 5px;
                }
                .zs-alert-count .dashicons {
                    width: 18px;
                    height: 18px;
                    font-size: 18px;
                }
                .zs-alert-count .dashicons.alert-critical {
                    color: #dc3545;
                }
                .zs-alert-count .dashicons.alert-max-capacity {
                    color: #fd7e14;
                }
                .zs-alert-count .dashicons.alert-low-stock {
                    color: #ffc107;
                }
                .zs-alert-count .dashicons.alert-resolved {
                    color: #46b450;
                }
                .zs-alert-item {
                    padding: 8px 0;
                    font-size: 13px;
                    line-height: 1.6;
                }
                .zs-alert-item-header {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    font-weight: 500;
                }
                .zs-alert-item-header .dashicons {
                    width: 18px;
                    height: 18px;
                    font-size: 18px;
                    flex-shrink: 0;
                    margin-top: 2px;
                }
                .zs-alert-item-header .dashicons.alert-critical {
                    color: #dc3545;
                }
                .zs-alert-item-header .dashicons.alert-max-capacity {
                    color: #fd7e14;
                }
                .zs-alert-item-header .dashicons.alert-low-stock {
                    color: #ffc107;
                }
                .zs-alert-inline-message .dashicons {
                    width: 18px;
                    height: 18px;
                    font-size: 18px;
                }
                .zs-alert-inline-message .dashicons.alert-critical {
                    color: #dc3545;
                }
                .zs-alert-inline-message .dashicons.alert-max-capacity {
                    color: #fd7e14;
                }
                .zs-alert-inline-message .dashicons.alert-low-stock {
                    color: #ffc107;
                }
                .zs-alert-item-details {
                    margin-left: 22px;
                    color: #666;
                    font-size: 12px;
                }
                .zs-alert-conflict-link {
                    color: #2271b1;
                    text-decoration: none;
                }
                .zs-alert-conflict-link:hover {
                    text-decoration: underline;
                }
                
                /* Inline Alert Icons */
                .zs-material-alert-icon {
                    display: inline-flex;
                    align-items: center;
                    margin-left: 6px;
                    cursor: help;
                }
                .zs-material-alert-icon .dashicons {
                    width: 18px;
                    height: 18px;
                    font-size: 18px;
                }
                .zs-material-alert-icon .dashicons.alert-critical {
                    color: #dc3545;
                }
                .zs-material-alert-icon .dashicons.alert-max-capacity {
                    color: #fd7e14;
                }
                .zs-material-alert-icon .dashicons.alert-low-stock {
                    color: #ffc107;
                }
                .zs-material-alert-icon .dashicons.alert-resolved {
                    color: #46b450;
                }
                .zs-alert-resolution {
                    margin-top: 6px;
                    padding: 8px;
                    background: #f0f6fc;
                    border-left: 3px solid #2271b1;
                    font-size: 12px;
                }
                .zs-alert-resolution-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 4px;
                }
                .zs-alert-resolution-info {
                    color: #666;
                }
                .zs-alert-resolution-notes {
                    margin-top: 4px;
                    font-style: italic;
                }
                .zs-alert-resolve-btn {
                    display: inline-block;
                    padding: 5px 12px;
                    background: transparent;
                    color: #50575e;
                    border: 1px solid #50575e;
                    border-radius: 3px;
                    font-size: 12px;
                    cursor: pointer;
                    white-space: nowrap;
                    flex-shrink: 0;
                    transition: all 0.2s ease;
                }
                .zs-alert-resolve-btn:hover {
                    background: #50575e;
                    color: white;
                }
                .zs-alert-notes-input {
                    flex: 1;
                    padding: 6px 8px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                    font-size: 12px;
                    min-width: 0;
                }
                .zs-alert-resolve-controls {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                    margin-top: 8px;
                }
                .zs-alert-undo-btn {
                    color: #2271b1;
                    text-decoration: none;
                    font-size: 11px;
                    cursor: pointer;
                }
                .zs-alert-undo-btn:hover {
                    text-decoration: underline;
                }
            </style>
            
            <?php if (!empty($alerts)): ?>
                <?php
                // Contar alertas por tipo (incluyendo resueltas)
                $criticalCount = 0;
                $maxCapacityCount = 0;
                $lowStockCount = 0;
                $resolvedCount = 0;
                
                foreach ($alerts as $materialKey => $alert) {
                    $isResolved = AlertResolutionManager::isResolved($postId, $materialKey);
                    
                    if ($isResolved) {
                        $resolvedCount++;
                    } else {
                        switch ($alert['alert_type']) {
                            case AlertCalculator::ALERT_CRITICAL:
                                $criticalCount++;
                                break;
                            case AlertCalculator::ALERT_MAX_CAPACITY:
                                $maxCapacityCount++;
                                break;
                            case AlertCalculator::ALERT_LOW_STOCK:
                                $lowStockCount++;
                                break;
                        }
                    }
                }
                
                $totalAlerts = $criticalCount + $maxCapacityCount + $lowStockCount + $resolvedCount;
                
                if ($totalAlerts > 0):
                    $eventDate = $order->get_meta('zs_event_date', true);
                    $serviceAreaId = (int) $order->get_meta('zs_event_service_location', true);
                    $serviceArea = $serviceAreaId ? get_term($serviceAreaId, 'service-area') : null;
                    $serviceAreaName = $serviceArea && !is_wp_error($serviceArea) ? $serviceArea->name : __('Unknown', 'zero-sense');
                ?>
                <div class="zs-stock-alerts-banner">
                    <div class="zs-stock-alerts-header">
                        <div style="display: flex; align-items: center; gap: 15px; flex: 1;">
                            <div class="zs-stock-alerts-title">
                                <span>⚠️</span>
                                <span><?php printf(__('Stock Alerts (%d)', 'zero-sense'), $totalAlerts); ?></span>
                                <?php if ($eventDate): ?>
                                    <span style="font-weight: normal; color: #666;">
                                        - <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($eventDate))); ?>
                                        - <?php echo esc_html($serviceAreaName); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="zs-stock-alerts-summary">
                            <?php if ($criticalCount > 0): ?>
                                <div class="zs-alert-count">
                                    <span class="dashicons <?php echo AlertCalculator::getAlertIcon(AlertCalculator::ALERT_CRITICAL); ?> alert-critical"></span>
                                    <span><?php echo $criticalCount; ?> <?php echo AlertCalculator::getAlertLabel(AlertCalculator::ALERT_CRITICAL); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($maxCapacityCount > 0): ?>
                                <div class="zs-alert-count">
                                    <span class="dashicons <?php echo AlertCalculator::getAlertIcon(AlertCalculator::ALERT_MAX_CAPACITY); ?> alert-max-capacity"></span>
                                    <span><?php echo $maxCapacityCount; ?> <?php echo AlertCalculator::getAlertLabel(AlertCalculator::ALERT_MAX_CAPACITY); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($lowStockCount > 0): ?>
                                <div class="zs-alert-count">
                                    <span class="dashicons <?php echo AlertCalculator::getAlertIcon(AlertCalculator::ALERT_LOW_STOCK); ?> alert-low-stock"></span>
                                    <span><?php echo $lowStockCount; ?> <?php echo AlertCalculator::getAlertLabel(AlertCalculator::ALERT_LOW_STOCK); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($resolvedCount > 0): ?>
                                <div class="zs-alert-count">
                                    <span class="dashicons dashicons-yes-alt alert-resolved"></span>
                                    <span><?php echo $resolvedCount; ?> <?php _e('Resolved', 'zero-sense'); ?></span>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div class="zs-stock-alerts-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                    </div>
                    
                    <div class="zs-alert-details">
                        <?php 
                        // Ordenar alertas por severidad
                        $sortedAlerts = [];
                        foreach ($alerts as $materialKey => $alert) {
                            $isResolved = AlertResolutionManager::isResolved($postId, $materialKey);
                            $sortOrder = 999; // Default para resolved
                            
                            if (!$isResolved) {
                                switch ($alert['alert_type']) {
                                    case AlertCalculator::ALERT_CRITICAL:
                                        $sortOrder = 1;
                                        break;
                                    case AlertCalculator::ALERT_MAX_CAPACITY:
                                        $sortOrder = 2;
                                        break;
                                    case AlertCalculator::ALERT_LOW_STOCK:
                                        $sortOrder = 3;
                                        break;
                                }
                            }
                            
                            $sortedAlerts[] = [
                                'material_key' => $materialKey,
                                'alert' => $alert,
                                'is_resolved' => $isResolved,
                                'sort_order' => $sortOrder
                            ];
                        }
                        
                        // Ordenar por sort_order
                        usort($sortedAlerts, function($a, $b) {
                            return $a['sort_order'] - $b['sort_order'];
                        });
                        
                        foreach ($sortedAlerts as $item):
                            $materialKey = $item['material_key'];
                            $alert = $item['alert'];
                            $isItemResolved = $item['is_resolved'];
                        ?>
                            <?php
                            $materialDef = $materials[$materialKey] ?? null;
                            // Fallback: try to find by searching all materials if exact key doesn't match
                            if (!$materialDef) {
                                foreach ($materials as $key => $mat) {
                                    if (strtolower($key) === strtolower($materialKey)) {
                                        $materialDef = $mat;
                                        break;
                                    }
                                }
                            }
                            // Use label if found, otherwise format the key nicely
                            if ($materialDef && isset($materialDef['label'])) {
                                $materialLabel = $materialDef['label'];
                            } else {
                                // Format key: replace underscores with spaces and capitalize
                                $materialLabel = ucwords(str_replace('_', ' ', $materialKey));
                            }
                            
                            if ($isItemResolved) {
                                $iconClass = 'dashicons-yes-alt';
                                $alertClass = 'alert-resolved';
                            } else {
                                $iconClass = AlertCalculator::getAlertIcon($alert['alert_type']);
                                $alertClass = '';
                                switch ($alert['alert_type']) {
                                    case AlertCalculator::ALERT_CRITICAL:
                                        $alertClass = 'alert-critical';
                                        break;
                                    case AlertCalculator::ALERT_MAX_CAPACITY:
                                        $alertClass = 'alert-max-capacity';
                                        break;
                                    case AlertCalculator::ALERT_LOW_STOCK:
                                        $alertClass = 'alert-low-stock';
                                        break;
                                }
                            }
                            ?>
                            <div class="zs-alert-item">
                                <div class="zs-alert-item-header">
                                    <span class="dashicons <?php echo $iconClass; ?> <?php echo $alertClass; ?>"></span>
                                    <strong><?php echo esc_html($materialLabel); ?>:</strong>
                                    <span>
                                        <?php if ($isItemResolved): ?>
                                            <?php 
                                            $resolution = $resolutions[$materialKey] ?? null;
                                            if ($resolution) {
                                                $resolvedBy = get_userdata($resolution['resolved_by']);
                                                $resolvedByName = $resolvedBy ? $resolvedBy->display_name : __('Unknown', 'zero-sense');
                                                printf(__('Resolved by %s', 'zero-sense'), esc_html($resolvedByName));
                                                if (!empty($resolution['notes'])) {
                                                    echo ' - ' . esc_html($resolution['notes']);
                                                }
                                            }
                                            ?>
                                        <?php else: ?>
                                            <?php if ($alert['alert_type'] === AlertCalculator::ALERT_CRITICAL): ?>
                                                <?php printf(__('Insufficient stock: %d units needed in total for all events on this day, only %d available', 'zero-sense'), $alert['total_needed'], $alert['total_stock']); ?>
                                            <?php elseif ($alert['alert_type'] === AlertCalculator::ALERT_MAX_CAPACITY): ?>
                                                <?php printf(__('Max capacity reached: %d/%d units used for all events on this day', 'zero-sense'), $alert['total_needed'], $alert['total_stock']); ?>
                                            <?php else: ?>
                                                <?php printf(__('Low stock: %d%% capacity used', 'zero-sense'), $alert['usage_percent']); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if (!$isItemResolved && !empty($alert['conflicts'])): ?>
                                    <div class="zs-alert-item-details">
                                        <?php _e('Conflicts with other orders:', 'zero-sense'); ?>
                                        <?php foreach ($alert['conflicts'] as $idx => $conflict): ?>
                                            <?php if ($idx > 0) echo ', '; ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $conflict['order_id'] . '&action=edit')); ?>" 
                                               target="_blank" 
                                               style="color: #2271b1;">
                                                #<?php echo $conflict['order_id']; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="zs-inventory-header">
                <div class="zs-inventory-controls">
                    <button type="button" class="zs-inventory-recalc-btn" title="<?php esc_attr_e('Recalculate all from order data', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Recalculate all', 'zero-sense'); ?>
                    </button>
                    <button type="button" class="zs-inventory-lock-btn" data-locked="true" title="<?php esc_attr_e('Click to unlock for editing', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-lock"></span>
                        <span class="lock-text"><?php esc_html_e('Unlock', 'zero-sense'); ?></span>
                    </button>
                    <button type="button" class="zs-inventory-save-btn" style="display:none;" title="<?php esc_attr_e('Save changes and lock table', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Save & Lock', 'zero-sense'); ?>
                    </button>
                </div>
            </div>
            
            <?php foreach ($groupedByParent as $parentKey => $categories): ?>
                <div class="zs-inventory-accordion">
                    <div class="zs-inventory-accordion-header">
                        <span><?php echo esc_html($parentCategories[$parentKey] ?? $parentKey); ?></span>
                        <span class="dashicons dashicons-arrow-down-alt2 zs-inventory-accordion-icon"></span>
                    </div>
                    <div class="zs-inventory-accordion-content">
                        <table class="widefat striped">
                            <tbody>
                                <?php foreach ($categories as $catKey => $materialsInCat): ?>
                                    <tr class="zs-inventory-category-header">
                                        <td style="font-weight: 300;"><?php echo esc_html($categoryLabels[$catKey] ?? $catKey); ?></td>
                                        <td style="text-align: right; width: 200px; font-weight: 300;"><?php esc_html_e('Qty', 'zero-sense'); ?></td>
                                    </tr>
                                    <?php foreach ($materialsInCat as $material): ?>
                                        <?php
                                        $materialKey = $material['key'];
                                        $autoValue = $calculated[$materialKey] ?? 0;
                                        $overrideValue = $overrides[$materialKey] ?? null;
                                        $finalValue = $final[$materialKey] ?? 0;
                                        $hasOverride = $overrideValue !== null && $overrideValue !== '';
                                        ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo esc_html($material['label']); ?></strong>
                                                </div>
                                                <?php if (!empty($material['description'])): ?>
                                                    <div class="zs-inventory-description"><?php echo esc_html($material['description']); ?></div>
                                                <?php endif; ?>
                                                
                                                <?php 
                                                // Check if this material has an alert
                                                $materialAlert = $alerts[$materialKey] ?? null;
                                                $materialResolution = $resolutions[$materialKey] ?? null;
                                                $isResolved = $materialResolution && ($materialResolution['resolved'] ?? false);
                                                ?>
                                                
                                                <?php if ($materialAlert): ?>
                                                    <?php if ($isResolved): ?>
                                                        <div class="zs-alert-resolution">
                                                            <div class="zs-alert-resolution-header">
                                                                <strong><?php _e('Resolved', 'zero-sense'); ?></strong>
                                                                <a href="#" class="zs-alert-undo-btn" 
                                                                   data-order-id="<?php echo esc_attr($postId); ?>"
                                                                   data-material-key="<?php echo esc_attr($materialKey); ?>">
                                                                    <?php _e('Undo', 'zero-sense'); ?>
                                                                </a>
                                                            </div>
                                                            <div class="zs-alert-resolution-info">
                                                                <?php 
                                                                $resolvedBy = get_userdata($materialResolution['resolved_by']);
                                                                $resolvedByName = $resolvedBy ? $resolvedBy->display_name : __('Unknown', 'zero-sense');
                                                                $resolvedAt = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($materialResolution['resolved_at']));
                                                                printf(__('By: %s on %s', 'zero-sense'), esc_html($resolvedByName), esc_html($resolvedAt));
                                                                ?>
                                                            </div>
                                                            <?php if (!empty($materialResolution['notes'])): ?>
                                                                <div class="zs-alert-resolution-notes">
                                                                    <?php echo esc_html($materialResolution['notes']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="zs-alert-inline-message" style="margin-top: 8px; font-size: 12px; color: #666; display: flex; align-items: flex-start; gap: 6px;">
                                                            <?php
                                                            $alertIconClass = AlertCalculator::getAlertIcon($materialAlert['alert_type']);
                                                            $alertCssClass = '';
                                                            switch ($materialAlert['alert_type']) {
                                                                case AlertCalculator::ALERT_CRITICAL:
                                                                    $alertCssClass = 'alert-critical';
                                                                    break;
                                                                case AlertCalculator::ALERT_MAX_CAPACITY:
                                                                    $alertCssClass = 'alert-max-capacity';
                                                                    break;
                                                                case AlertCalculator::ALERT_LOW_STOCK:
                                                                    $alertCssClass = 'alert-low-stock';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="dashicons <?php echo $alertIconClass; ?> <?php echo $alertCssClass; ?>" style="flex-shrink: 0; margin-top: 1px;"></span>
                                                            <div style="flex: 1;">
                                                                <?php if ($materialAlert['alert_type'] === AlertCalculator::ALERT_CRITICAL): ?>
                                                                    <?php printf(__('Insufficient stock: %d units needed in total for all events on this day, only %d available', 'zero-sense'), $materialAlert['total_needed'], $materialAlert['total_stock']); ?>
                                                                <?php elseif ($materialAlert['alert_type'] === AlertCalculator::ALERT_MAX_CAPACITY): ?>
                                                                    <?php printf(__('Max capacity reached: %d/%d units used for all events on this day', 'zero-sense'), $materialAlert['total_needed'], $materialAlert['total_stock']); ?>
                                                                <?php else: ?>
                                                                    <?php printf(__('Low stock: %d%% capacity used', 'zero-sense'), $materialAlert['usage_percent']); ?>
                                                                <?php endif; ?>
                                                            
                                                                <?php if (!empty($materialAlert['conflicts'])): ?>
                                                                    <div style="margin-top: 4px;">
                                                                        <?php _e('Conflicts with other orders:', 'zero-sense'); ?>
                                                                        <?php foreach ($materialAlert['conflicts'] as $idx => $conflict): ?>
                                                                            <?php if ($idx > 0) echo ', '; ?>
                                                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $conflict['order_id'] . '&action=edit')); ?>" 
                                                                               target="_blank" 
                                                                               style="color: #2271b1;">
                                                                                #<?php echo $conflict['order_id']; ?>
                                                                            </a>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                
                                                                <div class="zs-alert-resolve-controls">
                                                                    <input type="text" 
                                                                           class="zs-alert-notes-input" 
                                                                           placeholder="<?php esc_attr_e('Notes (optional): e.g., Rented 2 extra units...', 'zero-sense'); ?>"
                                                                           data-material-key="<?php echo esc_attr($materialKey); ?>">
                                                                    <button type="button" 
                                                                            class="zs-alert-resolve-btn"
                                                                            data-order-id="<?php echo esc_attr($postId); ?>"
                                                                            data-material-key="<?php echo esc_attr($materialKey); ?>">
                                                                        ✓ <?php _e('Mark as Resolved', 'zero-sense'); ?>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="zs-inventory-quantity-wrapper">
                                                    <span class="dashicons dashicons-update zs-inventory-reset-icon hidden" 
                                                       data-material="<?php echo esc_attr($materialKey); ?>"
                                                       data-has-override="<?php echo $hasOverride ? '1' : '0'; ?>"
                                                       title="<?php esc_attr_e('Reset to auto', 'zero-sense'); ?>">
                                                    </span>
                                                    <span class="zs-inventory-badge-container">
                                                        <?php if ($hasOverride): ?>
                                                            <span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>
                                                        <?php elseif ($autoValue > 0): ?>
                                                            <span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <input 
                                                        type="number" 
                                                        name="zs_inventory[<?php echo esc_attr($materialKey); ?>]"
                                                        value="<?php 
                                                            $displayValue = $hasOverride ? $overrideValue : $autoValue;
                                                            // Show 0 explicitly if it's a manual override, otherwise empty if 0
                                                            if ($hasOverride && $displayValue == 0) {
                                                                echo '0';
                                                            } else {
                                                                echo esc_attr($displayValue ?: '');
                                                            }
                                                        ?>"
                                                        data-auto="<?php echo esc_attr($autoValue); ?>"
                                                        min="0"
                                                        class="zs-inventory-input <?php echo $hasOverride ? 'zs-inventory-override' : ''; ?>"
                                                        disabled
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <p style="margin-top: 15px; color: #666; font-size: 13px;">
                <strong><?php esc_html_e('Note:', 'zero-sense'); ?></strong>
                <?php esc_html_e('Unlock the table to make changes. Click Save to store changes via AJAX.', 'zero-sense'); ?>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var isLocked = true;
            var dirtyFields = new Set();
            var orderId = <?php echo (int) $postId; ?>;
            var nonce = '<?php echo wp_create_nonce('zs_inventory_ajax'); ?>';
            
            // Lock/Unlock toggle
            $('.zs-inventory-lock-btn').on('click', function(e) {
                e.preventDefault();
                isLocked = !isLocked;
                var $lockBtn = $(this);
                var $saveBtn = $('.zs-inventory-save-btn');
                var $inputs = $('.zs-inventory-input');
                var $resetIcons = $('.zs-inventory-reset-icon');
                
                if (isLocked) {
                    // Locked state: show Unlock button, hide Save & Lock
                    $inputs.prop('disabled', true);
                    $resetIcons.addClass('hidden');
                    $lockBtn.attr('data-locked', 'true');
                    $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                    $lockBtn.find('.lock-text').text('<?php echo esc_js(__('Unlock', 'zero-sense')); ?>');
                    $lockBtn.attr('title', '<?php echo esc_js(__('Click to unlock for editing', 'zero-sense')); ?>');
                    $lockBtn.show();
                    $saveBtn.hide();
                } else {
                    // Unlocked state: hide Unlock button, show Save & Lock
                    $inputs.prop('disabled', false);
                    // Only show reset icons for fields with overrides
                    $resetIcons.each(function() {
                        if ($(this).data('has-override') == '1') {
                            $(this).removeClass('hidden');
                        }
                    });
                    $lockBtn.attr('data-locked', 'false');
                    $lockBtn.hide();
                    $saveBtn.show();
                }
            });
            
            // Reset individual material to auto
            $(document).on('click', '.zs-inventory-reset-icon', function(e) {
                e.preventDefault();
                if (isLocked) return;
                
                var materialKey = $(this).data('material');
                var $input = $('input[name="zs_inventory[' + materialKey + ']"]');
                var autoValue = $input.data('auto');
                
                // Set to empty if auto is 0
                var displayValue = (autoValue == '0') ? '' : autoValue;
                $input.val(displayValue).removeClass('zs-inventory-override');
                
                // Update badge
                var $td = $input.parent();
                var $container = $td.find('.zs-inventory-badge-container');
                if (autoValue > 0) {
                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                } else {
                    $container.html('');
                }
                
                $(this).addClass('hidden');
            });
            
            // Recalculate all
            $('.zs-inventory-recalc-btn').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to recalculate all materials?', 'zero-sense')); ?>')) {
                    return;
                }
                
                var $btn = $(this);
                $btn.prop('disabled', true);
                
                // Clear all overrides from database via AJAX
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zs_clear_inventory_overrides',
                        nonce: nonce,
                        order_id: orderId
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update UI
                            $('.zs-inventory-input').each(function() {
                                var $input = $(this);
                                var autoValue = $input.data('auto');
                                var displayValue = (autoValue == '0') ? '' : autoValue;
                                $input.val(displayValue).removeClass('zs-inventory-override');
                                
                                // Update badges
                                var $td = $input.parent();
                                var $container = $td.find('.zs-inventory-badge-container');
                                if (autoValue > 0) {
                                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                                } else {
                                    $container.html('');
                                }
                            });
                            
                            // Remove all reset icons
                            $('.zs-inventory-reset-icon').remove();
                            
                            showToast('<?php echo esc_js(__('All materials recalculated', 'zero-sense')); ?>', 'success');
                        } else {
                            showToast('<?php echo esc_js(__('Error clearing overrides', 'zero-sense')); ?>', 'error');
                        }
                    },
                    error: function() {
                        showToast('<?php echo esc_js(__('Connection error', 'zero-sense')); ?>', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Track manual changes
            $('.zs-inventory-metabox').on('input', '.zs-inventory-input', function() {
                if (isLocked) return;
                
                var $input = $(this);
                var currentValue = $input.val();
                var autoValue = $input.data('auto');
                var materialKey = $input.attr('name').match(/\[([^\]]+)\]/)[1];
                var $td = $input.parent();
                
                // Track dirty field
                dirtyFields.add(materialKey);
                
                // Normalize values (treat empty as 0)
                var normalizedValue = (currentValue === '' || currentValue === null) ? 0 : parseInt(currentValue);
                var normalizedAuto = (autoValue === '' || autoValue === null) ? 0 : parseInt(autoValue);
                
                // Check if value differs from auto
                if (normalizedValue != normalizedAuto) {
                    // User set a value different from auto (including 0 when auto is non-zero)
                    $input.addClass('zs-inventory-override');
                    
                    // Show '0' explicitly if manual override is 0
                    if (normalizedValue === 0) {
                        $input.val('0');
                    }
                    
                    // Update badge to manual
                    var $container = $td.find('.zs-inventory-badge-container');
                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>');
                    
                    // Show reset icon
                    var $resetIcon = $td.find('.zs-inventory-reset-icon');
                    $resetIcon.removeClass('hidden');
                } else {
                    // Value matches auto
                    $input.removeClass('zs-inventory-override');
                    
                    // Update badge: only show AUTO if auto value is > 0
                    var $container = $td.find('.zs-inventory-badge-container');
                    if (normalizedAuto > 0) {
                        $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                    } else {
                        // Auto is 0 and value is 0: no badge
                        $container.html('');
                    }
                    
                    // Hide reset icon
                    var $resetIcon = $td.find('.zs-inventory-reset-icon');
                    $resetIcon.addClass('hidden');
                }
                
            });
            
            // Save & Lock
            $('.zs-inventory-save-btn').on('click', function() {
                var $btn = $(this);
                $btn.addClass('is-saving');
                
                // Collect all field values
                var data = {};
                $('.zs-inventory-input').each(function() {
                    var $input = $(this);
                    var materialKey = $input.attr('name').match(/\[([^\]]+)\]/)[1];
                    var value = $input.val();
                    var autoValue = $input.data('auto');
                    
                    // Only send if different from auto or explicitly set
                    if (value !== '' && value != autoValue) {
                        data[materialKey] = value;
                    }
                });
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zs_save_inventory',
                        nonce: nonce,
                        order_id: orderId,
                        inventory: data
                    },
                    success: function(response) {
                        if (response.success) {
                            dirtyFields.clear();
                            
                            // Lock the table after saving
                            isLocked = true;
                            var $lockBtn = $('.zs-inventory-lock-btn');
                            var $inputs = $('.zs-inventory-input');
                            var $resetIcons = $('.zs-inventory-reset-icon');
                            
                            $inputs.prop('disabled', true);
                            $resetIcons.addClass('hidden');
                            $lockBtn.attr('data-locked', 'true');
                            $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                            $lockBtn.find('.lock-text').text('<?php echo esc_js(__('Unlock', 'zero-sense')); ?>');
                            $lockBtn.show();
                            $btn.hide();
                            
                            showToast('<?php echo esc_js(__('Inventory saved and locked', 'zero-sense')); ?>', 'success');
                        } else {
                            showToast('<?php echo esc_js(__('Error saving inventory', 'zero-sense')); ?>', 'error');
                        }
                    },
                    error: function() {
                        showToast('<?php echo esc_js(__('Connection error', 'zero-sense')); ?>', 'error');
                    },
                    complete: function() {
                        $btn.removeClass('is-saving');
                    }
                });
            });
            
            // Show toast notification
            function showToast(message, type) {
                var $toast = $('<div class="zs-inventory-toast ' + type + '">' + message + '</div>');
                $('body').append($toast);
                setTimeout(function() { $toast.addClass('show'); }, 10);
                setTimeout(function() { 
                    $toast.removeClass('show');
                    setTimeout(function() { $toast.remove(); }, 300);
                }, 3000);
            }
            
            // Accordion toggle
            $('.zs-inventory-accordion-header').on('click', function() {
                $(this).closest('.zs-inventory-accordion').toggleClass('collapsed');
            });
            
            // Stock Alerts collapse - restore state from localStorage
            var alertsCollapsed = localStorage.getItem('zs_stock_alerts_collapsed');
            if (alertsCollapsed === 'true') {
                $('.zs-stock-alerts-banner').addClass('collapsed');
            }
            
            // Stock Alerts collapse toggle with persistence
            $(document).on('click', '.zs-stock-alerts-header', function(e) {
                var $banner = $(this).closest('.zs-stock-alerts-banner');
                $banner.toggleClass('collapsed');
                
                // Save state to localStorage
                var isCollapsed = $banner.hasClass('collapsed');
                localStorage.setItem('zs_stock_alerts_collapsed', isCollapsed);
            });
            
            // Mark alert as resolved
            $(document).on('click', '.zs-alert-resolve-btn', function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                var materialKey = $btn.data('material-key');
                var $notesInput = $('.zs-alert-notes-input[data-material-key="' + materialKey + '"]');
                var notes = $notesInput.val();
                
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'zero-sense')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zs_resolve_inventory_alert',
                        nonce: nonce,
                        order_id: orderId,
                        material_key: materialKey,
                        notes: notes
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload page to show resolved state
                            location.reload();
                        } else {
                            showToast(response.data.message || '<?php echo esc_js(__('Error resolving alert', 'zero-sense')); ?>', 'error');
                            $btn.prop('disabled', false).html('✓ <?php echo esc_js(__('Mark as Resolved', 'zero-sense')); ?>');
                        }
                    },
                    error: function() {
                        showToast('<?php echo esc_js(__('Connection error', 'zero-sense')); ?>', 'error');
                        $btn.prop('disabled', false).html('✓ <?php echo esc_js(__('Mark as Resolved', 'zero-sense')); ?>');
                    }
                });
            });
            
            // Undo alert resolution
            $(document).on('click', '.zs-alert-undo-btn', function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                var materialKey = $btn.data('material-key');
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to undo this resolution?', 'zero-sense')); ?>')) {
                    return;
                }
                
                $btn.text('<?php echo esc_js(__('Undoing...', 'zero-sense')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zs_undo_inventory_alert_resolution',
                        nonce: nonce,
                        order_id: orderId,
                        material_key: materialKey
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload page to show unresolved state
                            location.reload();
                        } else {
                            showToast(response.data.message || '<?php echo esc_js(__('Error undoing resolution', 'zero-sense')); ?>', 'error');
                            $btn.text('<?php echo esc_js(__('Undo', 'zero-sense')); ?>');
                        }
                    },
                    error: function() {
                        showToast('<?php echo esc_js(__('Connection error', 'zero-sense')); ?>', 'error');
                        $btn.text('<?php echo esc_js(__('Undo', 'zero-sense')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Guarda los datos del metabox
     */
    public function save(int $postId, $post = null): void
    {
        // Get order - works for both HPOS and legacy
        $order = wc_get_order($postId);
        
        if (!$order) {
            return;
        }
        
        if (!current_user_can('edit_shop_order', $postId)) {
            return;
        }
        
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }
        
        // Calcular valores automáticos primero
        $calculated = MaterialCalculator::calculate($order);
        
        // Obtener overrides existentes
        $existingOverrides = ManualOverride::get($postId);
        
        // Procesar campos del formulario
        $newOverrides = $_POST['zs_inventory'] ?? [];
        
        // Si el formulario está vacío (locked), mantener overrides existentes
        if (empty($newOverrides)) {
            $final = ManualOverride::apply($calculated, $existingOverrides);
            ReservationManager::createOrUpdate($postId, $final);
            return;
        }
        
        // Filtrar solo los campos que realmente difieren del cálculo automático
        $actualOverrides = $existingOverrides; // Empezar con los existentes
        
        foreach ($newOverrides as $materialKey => $value) {
            $autoValue = $calculated[$materialKey] ?? 0;
            $normalizedValue = ($value === '' || $value === null) ? 0 : (int) $value;
            
            if ($normalizedValue != $autoValue) {
                // Difiere del auto: guardar como override
                $actualOverrides[$materialKey] = $normalizedValue;
            } else {
                // Coincide con auto: eliminar override si existe
                unset($actualOverrides[$materialKey]);
            }
        }
        
        // Guardar overrides actualizados
        ManualOverride::save($postId, $actualOverrides);
        
        // Aplicar overrides finales
        $final = ManualOverride::apply($calculated, $actualOverrides);
        
        // Crear/actualizar reservas
        ReservationManager::createOrUpdate($postId, $final);
    }
    
    /**
     * Guarda los datos del metabox vía AJAX
     */
    public function ajaxSave(): void
    {
        check_ajax_referer('zs_inventory_ajax', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $inventory = $_POST['inventory'] ?? [];
        
        if (!$orderId) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }
        
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        // Obtener overrides existentes
        $existingOverrides = ManualOverride::get($orderId);
        
        // Merge con nuevos overrides (preservar los existentes)
        $mergedOverrides = array_merge($existingOverrides, $inventory);
        
        // Guardar overrides combinados
        ManualOverride::save($orderId, $mergedOverrides);
        
        // Calcular materiales finales
        $calculated = MaterialCalculator::calculate($order);
        $final = ManualOverride::apply($calculated, $mergedOverrides);
        
        // Crear/actualizar reservas
        ReservationManager::createOrUpdate($orderId, $final);
        
        wp_send_json_success(['message' => 'Inventory saved successfully']);
    }
    
    /**
     * Elimina todos los overrides manuales (Recalculate All)
     */
    public function ajaxClearOverrides(): void
    {
        check_ajax_referer('zs_inventory_ajax', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $orderId = (int) ($_POST['order_id'] ?? 0);
        
        if (!$orderId) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }
        
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        // Eliminar todos los overrides
        ManualOverride::removeAll($orderId);
        
        // Recalcular materiales finales (solo automáticos)
        $calculated = MaterialCalculator::calculate($order);
        
        // Actualizar reservas con valores automáticos
        ReservationManager::createOrUpdate($orderId, $calculated);
        
        wp_send_json_success(['message' => 'All overrides cleared successfully']);
    }
    
    /**
     * Marca una alerta como resuelta (AJAX)
     */
    public function ajaxResolveAlert(): void
    {
        check_ajax_referer('zs_inventory_ajax', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $materialKey = sanitize_text_field($_POST['material_key'] ?? '');
        $notes = sanitize_text_field($_POST['notes'] ?? '');
        
        if (!$orderId || !$materialKey) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
        
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        // Guardar resolución
        AlertResolutionManager::saveResolution($orderId, $materialKey, $notes);
        
        wp_send_json_success(['message' => 'Alert resolved successfully']);
    }
    
    /**
     * Deshace una resolución de alerta (AJAX)
     */
    public function ajaxUndoAlertResolution(): void
    {
        check_ajax_referer('zs_inventory_ajax', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $materialKey = sanitize_text_field($_POST['material_key'] ?? '');
        
        if (!$orderId || !$materialKey) {
            wp_send_json_error(['message' => 'Invalid parameters']);
        }
        
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        // Eliminar resolución
        AlertResolutionManager::removeResolution($orderId, $materialKey);
        
        wp_send_json_success(['message' => 'Alert resolution undone successfully']);
    }
}
