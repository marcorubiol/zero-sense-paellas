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
    const NONCE_FIELD = 'zs_equipment_nonce';
    const NONCE_ACTION = 'zs_equipment_save';
    
    /**
     * Registra el metabox
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        // Priority 50: Run AFTER WooCommerce saves order items (priority 10-40)
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 50, 2);
        add_action('wp_ajax_zs_save_equipment', [$this, 'ajaxSave']);
        add_action('wp_ajax_zs_clear_equipment_overrides', [$this, 'ajaxClearOverrides']);
        add_action('wp_ajax_zs_resolve_stock_alert', [$this, 'ajaxResolveAlert']);
        add_action('wp_ajax_zs_undo_stock_alert', [$this, 'ajaxUndoAlertResolution']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $isOrderScreen = ($screen->post_type === 'shop_order')
            || ($screen->id === 'woocommerce_page_wc-orders')
            || ($screen->id === wc_get_page_screen_id('shop-order'));

        if (!$isOrderScreen) {
            return;
        }

        $cssPath = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-inventory.css';
        $cssVer  = file_exists($cssPath) ? (string) filemtime($cssPath) : ZERO_SENSE_VERSION;

        wp_enqueue_style(
            'zero-sense-admin-inventory',
            plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-inventory.css',
            ['zero-sense-admin-components'],
            $cssVer
        );
    }
    
    /**
     * Añade el metabox
     */
    public function addMetabox(): void
    {
        add_meta_box(
            'zs_event_equipment',
            __('Event Equipment', 'zero-sense'),
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
        $totalGuests = (int) $order->get_meta('zs_event_total_guests', true);
        
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
        
        // Obtener overrides manuales y de cascada
        $overrides = ManualOverride::get($postId);
        $cascadeOverrides = ManualOverride::getCascade($postId);
        
        // Aplicar overrides (cascada primero, luego usuario — usuario tiene prioridad)
        $final = ManualOverride::apply($calculated, array_merge($cascadeOverrides, $overrides));
        
        // Calcular alertas de stock solo si el pedido está confirmado
        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        $alerts = (!empty($calculated) && in_array($order->get_status(), $allowedStatuses))
            ? AlertCalculator::calculateAlerts($order, $final)
            : [];
        
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
            'paelles'          => 'Paelles',
            'cassoles'         => 'Cassoles',
            'equipament_foc'   => 'Equipament de Foc',
            'caixes'           => 'Caixes',
            'suport_muntatge'  => 'Suport Muntatge',
            'roba_personal'    => 'Vestimenta Staff',
            'textils_neteja'   => 'Vestimenta Taules',
            'altres'           => 'Altres',
        ];
        
        ?>
        <div class="zs-inventory-metabox">
            
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
                                    <span><?php echo $resolvedCount; ?> <?php _e('Alert Resolved', 'zero-sense'); ?></span>
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
                                <div class="zs-alert-item-title">
                                    <span class="dashicons <?php echo $iconClass; ?> <?php echo $alertClass; ?>"></span>
                                    <strong><?php echo esc_html($materialLabel); ?></strong>
                                    <a href="#" class="zs-alert-goto-material" data-material-key="<?php echo esc_attr($materialKey); ?>">
                                        <?php _e('manage', 'zero-sense'); ?> ⤵
                                    </a>
                                </div>
                                <div class="zs-alert-item-message">
                                    <?php if ($isItemResolved): ?>
                                        <?php 
                                        $resolution = $resolutions[$materialKey] ?? null;
                                        if ($resolution) {
                                            $resolvedBy = get_userdata($resolution['resolved_by']);
                                            $resolvedByName = $resolvedBy ? $resolvedBy->display_name : __('Unknown', 'zero-sense');
                                            printf(__('Alert Resolved by %s', 'zero-sense'), esc_html($resolvedByName));
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
                                </div>
                                <?php if (!$isItemResolved && !empty($alert['conflicts'])): ?>
                                    <div class="zs-alert-item-conflicts">
                                        <?php _e('Conflicts with other orders:', 'zero-sense'); ?>
                                        <?php foreach ($alert['conflicts'] as $idx => $conflict): ?>
                                            <?php if ($idx > 0) echo ', '; ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $conflict['order_id'] . '&action=edit')); ?>" 
                                               target="_blank" 
                                               style="color: #2271b1;">
                                                #<?php echo $conflict['order_id']; ?><span class="dashicons dashicons-external" style="font-size: 12px; width: 12px; height: 12px; margin-left: 2px; vertical-align: middle;"></span>
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
                                        $cascadeValue = $cascadeOverrides[$materialKey] ?? null;
                                        $finalValue = $final[$materialKey] ?? 0;
                                        $hasOverride = $overrideValue !== null && $overrideValue !== '';
                                        $hasCascade = !$hasOverride && $cascadeValue !== null && $cascadeValue !== '';
                                        $dependentKeys = ['cremador_50cm','cremador_60cm','cremador_70cm','cremador_90cm','potes_tripodes','buta','vitro_petita','catifes'];
                                        $isDependent = in_array($materialKey, $dependentKeys, true);
                                        ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo esc_html($material['label']); ?></strong>
                                                </div>
                                                <?php if (!empty($material['description'])): ?>
                                                    <div class="zs-inventory-description"><?php echo esc_html($material['description']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($material['dependency_label'])): ?>
                                                    <div class="zs-inventory-dependency-label"><?php echo esc_html($material['dependency_label']); ?></div>
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
                                                                <strong><?php _e('Alert Resolved', 'zero-sense'); ?></strong>
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
                                                                        ✓ <?php _e('Mark as Alert Resolved', 'zero-sense'); ?>
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
                                                       data-has-cascade="<?php echo $hasCascade ? '1' : '0'; ?>"
                                                       title="<?php esc_attr_e('Reset to auto', 'zero-sense'); ?>">
                                                    </span>
                                                    <?php if ($isDependent && !$hasOverride): ?>
                                                    <span class="dashicons dashicons-lock zs-inventory-dep-lock"
                                                       data-material="<?php echo esc_attr($materialKey); ?>"
                                                       title="<?php esc_attr_e('This value is auto-calculated. Click to override manually.', 'zero-sense'); ?>">
                                                    </span>
                                                    <?php endif; ?>
                                                    <span class="zs-inventory-badge-container">
                                                        <?php if ($hasOverride): ?>
                                                            <span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>
                                                        <?php elseif ($hasCascade || $autoValue > 0): ?>
                                                            <span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <input 
                                                        type="number" 
                                                        name="zs_inventory[<?php echo esc_attr($materialKey); ?>]"
                                                        value="<?php 
                                                            if ($hasOverride) {
                                                                $displayValue = $overrideValue;
                                                                echo ($displayValue == 0) ? '0' : esc_attr($displayValue);
                                                            } elseif ($hasCascade) {
                                                                echo esc_attr($cascadeValue);
                                                            } else {
                                                                echo esc_attr($autoValue ?: '');
                                                            }
                                                        ?>"
                                                        data-auto="<?php echo esc_attr($autoValue); ?>"
                                                        data-dependent="<?php echo $isDependent ? '1' : '0'; ?>"
                                                        min="0"
                                                        class="zs-inventory-input <?php echo $hasOverride ? 'zs-inventory-override' : ''; ?>"
                                                        <?php if ($hasCascade): ?>data-cascade="1"<?php endif; ?>
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
            var totalGuests = <?php echo (int) $totalGuests; ?>;

            // Initialize data-expected for cascade fields on page load
            $('.zs-inventory-input[data-cascade="1"]').each(function() {
                var $inp = $(this);
                var val = parseInt($inp.val()) || 0;
                $inp.data('expected', val);
            });

            // Paella dependencies mapping
            var paellaCremadorMap = {
                'paella_135cm': 'cremador_90cm',
                'paella_115cm': 'cremador_90cm',
                'paella_100cm': 'cremador_90cm',
                'paella_90cm': 'cremador_70cm',
                'paella_80cm': 'cremador_70cm',
                'paella_70cm': 'cremador_60cm',
                'paella_65cm': 'cremador_50cm',
                'paella_55cm': 'cremador_50cm'
            };

            // Recalculate dependencies when paella quantities change
            function recalculatePaellaDependencies() {
                var totalPaellas = 0;
                var cremadorCounts = {};
                
                // Reset cremador counts
                for (var paellaKey in paellaCremadorMap) {
                    var cremadorKey = paellaCremadorMap[paellaKey];
                    cremadorCounts[cremadorKey] = 0;
                }
                
                // Calculate paellas and required cremadors
                for (var paellaKey in paellaCremadorMap) {
                    var $input = $('input[name="zs_inventory[' + paellaKey + ']"]');
                    if ($input.length) {
                        var val = parseInt($input.val()) || 0;
                        if (val > 0) {
                            totalPaellas += val;
                            var cremadorKey = paellaCremadorMap[paellaKey];
                            cremadorCounts[cremadorKey] += val;
                        }
                    }
                }
                
                // Update cremadors
                var totalCremadors = 0;
                for (var cremadorKey in cremadorCounts) {
                    var count = cremadorCounts[cremadorKey];
                    totalCremadors += count;
                    updateInputAndTriggerEvent(cremadorKey, count);
                }
                
                // Update potes_tripodes
                updateInputAndTriggerEvent('potes_tripodes', totalCremadors);
                
                // Update buta
                var butaCount = totalCremadors;
                if (totalGuests > 60 && totalCremadors > 0) {
                    butaCount += 1;
                }
                updateInputAndTriggerEvent('buta', butaCount);
                
                // Update catifes (1 per cremador)
                updateInputAndTriggerEvent('catifes', totalCremadors);
            }

            function updateInputAndTriggerEvent(materialKey, newValue) {
                var $input = $('input[name="zs_inventory[' + materialKey + ']"]');
                if (!$input.length) return;

                // Skip if user has explicitly overridden this field
                if ($input.data('user-override') == '1') return;

                var currentValue = parseInt($input.val()) || 0;
                if (currentValue === newValue) return;

                // Update value directly (no trigger to avoid marking as manual)
                var valToSet = newValue === 0 ? '' : newValue;
                $input.val(valToSet).data('expected', newValue);

                // Update badge: show AUTO if value matches auto, otherwise keep as-is
                var autoValue = parseInt($input.data('auto')) || 0;
                var $td = $input.parent();
                var $container = $td.find('.zs-inventory-badge-container');
                if (newValue === autoValue) {
                    $container.html(autoValue > 0 ? '<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>' : '');
                    $input.removeClass('zs-inventory-override');
                } else {
                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                    $input.removeClass('zs-inventory-override');
                }
            }

            // Cassola keys
            var cassolaKeys = [
                'cassola_5l', 'cassola_6l', 'cassola_9l',
                'cassola_xata_11l', 'cassola_xata_13l', 'cassola_15l', 'cassola_33l'
            ];

            function recalculateVitro() {
                var totalCassoles = 0;
                cassolaKeys.forEach(function(key) {
                    var val = parseInt($('input[name="zs_inventory[' + key + ']"]').val()) || 0;
                    totalCassoles += val;
                });
                updateInputAndTriggerEvent('vitro', totalCassoles);
            }

            // Bind the recalculation to paella inputs
            var paellaSelectors = Object.keys(paellaCremadorMap).map(function(key) {
                return 'input[name="zs_inventory[' + key + ']"]';
            }).join(', ');

            $('.zs-inventory-metabox').on('input', paellaSelectors, function() {
                if (isLocked) return;
                recalculatePaellaDependencies();
            });

            // Bind vitro recalculation to cassola inputs
            var cassolaSelectors = cassolaKeys.map(function(key) {
                return 'input[name="zs_inventory[' + key + ']"]';
            }).join(', ');

            $('.zs-inventory-metabox').on('input', cassolaSelectors, function() {
                if (isLocked) return;
                recalculateVitro();
            });
            
            // Per-field lock click: unlock a dependent field for editing
            $(document).on('click', '.zs-inventory-dep-lock', function(e) {
                e.preventDefault();
                if (isLocked) return;
                var materialKey = $(this).data('material');
                var $input = $('input[name="zs_inventory[' + materialKey + ']"]');
                // Enable editing but keep current badge (AUTO) — MAN only on actual value change
                $input.prop('disabled', false);
                var $td = $input.parent();
                // Show reset icon so user can re-lock
                $td.find('.zs-inventory-reset-icon').attr('data-has-override', '1').removeClass('hidden');
                $(this).hide();
                $input.focus();
            });

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
                    $('.zs-inventory-dep-lock').hide();
                    $lockBtn.attr('data-locked', 'true');
                    $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                    $lockBtn.find('.lock-text').text('<?php echo esc_js(__('Unlock', 'zero-sense')); ?>');
                    $lockBtn.attr('title', '<?php echo esc_js(__('Click to unlock for editing', 'zero-sense')); ?>');
                    $lockBtn.show();
                    $saveBtn.hide();
                } else {
                    // Unlocked state
                    $inputs.each(function() {
                        var $inp = $(this);
                        var isDependent = $inp.data('dependent') == '1';
                        var isUserOverride = $inp.hasClass('zs-inventory-override');
                        var $wrapper = $inp.parent();

                        if (isDependent && !isUserOverride) {
                            $inp.prop('disabled', true);
                            $wrapper.find('.zs-inventory-dep-lock').show();
                        } else {
                            $inp.prop('disabled', false);
                            if (isUserOverride) {
                                $wrapper.find('.zs-inventory-reset-icon').removeClass('hidden');
                            }
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
                
                // If dependent field: remove user-override flag, re-disable and show lock icon
                if ($input.data('dependent') == '1') {
                    $input.removeData('user-override').prop('disabled', true);
                    $td.find('.zs-inventory-dep-lock').show();
                }
                
                $(this).attr('data-has-override', '0').addClass('hidden');
                
                // Cascade recalculation
                if (materialKey in paellaCremadorMap) {
                    // Resetting a paella → recalculate all dependents
                    recalculatePaellaDependencies();
                } else if ($input.data('dependent') == '1') {
                    // Resetting a dependent → recalculate from current primaries
                    recalculatePaellaDependencies();
                    recalculateVitro();
                }
                if (cassolaKeys.indexOf(materialKey) !== -1) {
                    recalculateVitro();
                }
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
                        action: 'zs_clear_equipment_overrides',
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
                                $input.val(displayValue).removeClass('zs-inventory-override').removeData('user-override');
                                
                                // Dependent fields: re-disable but do NOT show dep-lock (panel stays locked)
                                if ($input.data('dependent') == '1') {
                                    $input.prop('disabled', true);
                                }
                                
                                // Update badges
                                var $td = $input.parent();
                                var $container = $td.find('.zs-inventory-badge-container');
                                if (autoValue > 0) {
                                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                                } else {
                                    $container.html('');
                                }
                            });
                            
                            // Hide all reset icons and dep-lock icons (panel stays locked)
                            $('.zs-inventory-reset-icon').addClass('hidden');
                            $('.zs-inventory-dep-lock').hide();
                            
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
            
            // Ensure explicit '0' is shown when user leaves a field with empty/0 value that differs from auto
            $('.zs-inventory-metabox').on('change', '.zs-inventory-input', function() {
                if (isLocked) return;
                var $input = $(this);
                var currentValue = $input.val();
                var refVal = $input.data('expected') !== undefined ? $input.data('expected') : $input.data('auto');
                var normalizedValue = (currentValue === '' || currentValue === null) ? 0 : parseInt(currentValue);
                var normalizedRef = (refVal === '' || refVal === null) ? 0 : parseInt(refVal);
                if (normalizedValue !== normalizedRef && normalizedValue === 0) {
                    $input.val('0');
                }
            });

            // Track manual changes
            $('.zs-inventory-metabox').on('input', '.zs-inventory-input', function() {
                if (isLocked) return;
                
                var $input = $(this);
                var currentValue = $input.val();
                var materialKey = $input.attr('name').match(/\[([^\]]+)\]/)[1];
                var $td = $input.parent();
                
                // Track dirty field
                dirtyFields.add(materialKey);
                
                // For dependent fields, compare against cascade value (data-expected) if available
                var referenceValue = $input.data('expected') !== undefined ? $input.data('expected') : $input.data('auto');
                var normalizedValue = (currentValue === '' || currentValue === null) ? 0 : parseInt(currentValue);
                var normalizedRef = (referenceValue === '' || referenceValue === null) ? 0 : parseInt(referenceValue);
                
                // Check if value differs from reference
                if (normalizedValue != normalizedRef) {
                    // User set a value different from auto (including 0 when auto is non-zero)
                    $input.addClass('zs-inventory-override');
                    // If this is a dependent field being edited by the user, mark it as user override
                    if ($input.data('dependent') == '1') {
                        $input.data('user-override', '1');
                    }
                    
                    // Update badge to manual
                    var $container = $td.find('.zs-inventory-badge-container');
                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>');
                    
                    // Show reset icon
                    var $resetIcon = $td.find('.zs-inventory-reset-icon');
                    $resetIcon.removeClass('hidden');
                } else {
                    // Value matches expected (auto or cascade)
                    $input.removeClass('zs-inventory-override');
                    if ($input.data('dependent') == '1') {
                        $input.removeData('user-override');
                    }
                    
                    var $container = $td.find('.zs-inventory-badge-container');
                    if (normalizedRef > 0) {
                        $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                    } else {
                        $container.html('');
                    }
                    
                    var $resetIcon = $td.find('.zs-inventory-reset-icon');
                    $resetIcon.addClass('hidden');
                }
                
            });
            
            // Save & Lock
            $('.zs-inventory-save-btn').on('click', function() {
                var $btn = $(this);
                $btn.addClass('is-saving');
                
                // Collect ALL non-auto fields so PHP can fully replace stored overrides
                // (not just dirty fields — omitting a key means "reset to auto")
                var data = {};
                var cascadeData = {};
                $('.zs-inventory-input').each(function() {
                    var $input = $(this);
                    var materialKey = $input.attr('name').match(/\[([^\]]+)\]/)[1];
                    var value = $input.val();
                    var autoValue = $input.data('auto');
                    
                    if (value === '' || value == autoValue) return;
                    
                    // Dependent fields without explicit user override go to cascade bucket
                    if ($input.data('dependent') == '1' && $input.data('user-override') != '1') {
                        cascadeData[materialKey] = value;
                    } else {
                        data[materialKey] = value;
                    }
                });
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zs_save_equipment',
                        nonce: nonce,
                        order_id: orderId,
                        inventory: data,
                        inventory_cascade: cascadeData
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
                            $('.zs-inventory-dep-lock').hide();
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
            
            // Stock Alerts collapse - default closed, open only if user explicitly opened it
            var alertsCollapsed = localStorage.getItem('zs_stock_alerts_collapsed');
            if (alertsCollapsed !== 'false') {
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
            
            // Go to material from alert
            $(document).on('click', '.zs-alert-goto-material', function(e) {
                e.preventDefault();
                
                var materialKey = $(this).data('material-key');
                
                // Find the material row by data attribute or input name
                var $materialRow = $('input[name="zs_inventory[' + materialKey + ']"]').closest('tr');
                
                if ($materialRow.length) {
                    // Find parent accordion
                    var $accordion = $materialRow.closest('.zs-inventory-accordion');
                    
                    // Expand accordion if collapsed
                    if ($accordion.hasClass('collapsed')) {
                        $accordion.removeClass('collapsed');
                    }
                    
                    // Scroll to material row with smooth animation
                    $('html, body').animate({
                        scrollTop: $materialRow.offset().top - 100
                    }, 500);
                    
                    // Highlight the row briefly
                    $materialRow.css('background-color', '#fff3cd');
                    setTimeout(function() {
                        $materialRow.css('background-color', '');
                    }, 2000);
                }
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
                        action: 'zs_resolve_stock_alert',
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
                            $btn.prop('disabled', false).html('✓ <?php echo esc_js(__('Mark as Alert Resolved', 'zero-sense')); ?>');
                        }
                    },
                    error: function() {
                        showToast('<?php echo esc_js(__('Connection error', 'zero-sense')); ?>', 'error');
                        $btn.prop('disabled', false).html('✓ <?php echo esc_js(__('Mark as Alert Resolved', 'zero-sense')); ?>');
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
                        action: 'zs_undo_stock_alert',
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
        
        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        
        // Si el formulario está vacío (locked), mantener overrides existentes
        if (empty($newOverrides)) {
            if (in_array($order->get_status(), $allowedStatuses)) {
                $final = ManualOverride::apply($calculated, $existingOverrides);
                ReservationManager::createOrUpdate($postId, $final);
            } else {
                ReservationManager::deleteAll($postId);
            }
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
        
        // Crear/actualizar reservas solo si el pedido está confirmado
        if (in_array($order->get_status(), $allowedStatuses)) {
            ReservationManager::createOrUpdate($postId, $final);
        } else {
            ReservationManager::deleteAll($postId);
        }
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
        $inventoryCascade = $_POST['inventory_cascade'] ?? [];
        
        if (!$orderId) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }
        
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }
        
        // Reemplazar overrides completamente (JS envía todos los campos no-auto)
        // Un campo ausente significa "reset a auto"
        ManualOverride::save($orderId, $inventory);
        ManualOverride::saveCascade($orderId, $inventoryCascade);
        
        // Calcular materiales finales
        $calculated = MaterialCalculator::calculate($order);
        $cascadeOverrides = ManualOverride::getCascade($orderId);
        $final = ManualOverride::apply($calculated, array_merge($cascadeOverrides, $inventory));
        
        // Crear/actualizar reservas solo si el pedido está confirmado
        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        if (in_array($order->get_status(), $allowedStatuses)) {
            ReservationManager::createOrUpdate($orderId, $final);
        } else {
            ReservationManager::deleteAll($orderId);
        }
        
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
        
        // Eliminar todos los overrides (usuario y cascada)
        ManualOverride::removeAll($orderId);
        ManualOverride::saveCascade($orderId, []);
        
        // Recalcular materiales finales (solo automáticos)
        $calculated = MaterialCalculator::calculate($order);
        
        // Actualizar reservas solo si el pedido está confirmado
        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        if (in_array($order->get_status(), $allowedStatuses)) {
            ReservationManager::createOrUpdate($orderId, $calculated);
        } else {
            ReservationManager::deleteAll($orderId);
        }
        
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
