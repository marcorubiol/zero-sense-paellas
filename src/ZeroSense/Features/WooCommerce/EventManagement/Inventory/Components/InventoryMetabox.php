<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use WP_Post;
use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ManualOverride;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ReservationManager;

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
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20, 2);
        add_action('wp_ajax_zs_save_inventory', [$this, 'ajaxSave']);
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
        
        // Calcular materiales automáticamente
        $calculated = MaterialCalculator::calculate($order);
        
        // Obtener overrides manuales
        $overrides = ManualOverride::get($postId);
        
        // Aplicar overrides
        $final = ManualOverride::apply($calculated, $overrides);
        
        // Obtener todas las definiciones de materiales
        $materials = MaterialDefinitions::getAll();
        
        // Agrupar por categoría
        $categories = [
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
        
        $groupedMaterials = [];
        foreach ($materials as $material) {
            $cat = $material['category'] ?? 'altres';
            if (!isset($groupedMaterials[$cat])) {
                $groupedMaterials[$cat] = [];
            }
            $groupedMaterials[$cat][] = $material;
        }
        
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
                }
                .zs-inventory-category-header {
                    background: #f0f0f1;
                    font-weight: 600;
                    font-size: 13px;
                    text-transform: uppercase;
                    color: #1d2327;
                }
                .zs-inventory-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #ddd;
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
                    display: block;
                    margin-top: 2px;
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
            </style>
            
            <div class="zs-inventory-header">
                <div>
                    <strong><?php esc_html_e('Inventory & Materials', 'zero-sense'); ?></strong>
                </div>
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
            
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Material', 'zero-sense'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Quantity', 'zero-sense'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $catKey => $catLabel): ?>
                        <?php if (isset($groupedMaterials[$catKey])): ?>
                            <tr class="zs-inventory-category-header">
                                <td colspan="2"><?php echo esc_html($catLabel); ?></td>
                            </tr>
                            <?php foreach ($groupedMaterials[$catKey] as $material): ?>
                        <?php
                        $materialKey = $material['key'];
                        $autoValue = $calculated[$materialKey] ?? 0;
                        $overrideValue = $overrides[$materialKey] ?? null;
                        $finalValue = $final[$materialKey] ?? 0;
                        $hasOverride = $overrideValue !== null && $overrideValue !== '';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($material['label']); ?></strong>
                                <?php if (!empty($material['description'])): ?>
                                    <span class="zs-inventory-description"><?php echo esc_html($material['description']); ?></span>
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
                                        value="<?php echo esc_attr(($hasOverride ? $overrideValue : $autoValue) ?: ''); ?>"
                                        data-auto="<?php echo esc_attr($autoValue); ?>"
                                        min="0"
                                        class="zs-inventory-input <?php echo $hasOverride ? 'zs-inventory-override' : ''; ?>"
                                        disabled
                                    />
                                </div>
                            </td>
                        </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
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
                
                $('.zs-inventory-input').each(function() {
                    var $input = $(this);
                    var autoValue = $input.data('auto');
                    // Set to empty if auto is 0
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
                
                alert('<?php echo esc_js(__('All materials recalculated from order data.', 'zero-sense')); ?>');
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
                
                // Treat 0 as empty
                var normalizedValue = (currentValue === '' || currentValue == '0') ? '' : currentValue;
                var normalizedAuto = (autoValue == '0') ? '' : autoValue;
                
                if (normalizedValue !== '' && normalizedValue != normalizedAuto) {
                    $input.addClass('zs-inventory-override');
                    
                    // Update badge to manual
                    var $wrapper = $td.find('.zs-inventory-quantity-wrapper');
                    var $container = $wrapper.find('.zs-inventory-badge-container');
                    $container.html('<span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>');
                    
                    // Show reset icon
                    var $resetIcon = $wrapper.find('.zs-inventory-reset-icon');
                    $resetIcon.removeClass('hidden');
                } else {
                    $input.removeClass('zs-inventory-override');
                    
                    // Update badge to auto
                    var $wrapper = $td.find('.zs-inventory-quantity-wrapper');
                    var $container = $wrapper.find('.zs-inventory-badge-container');
                    if (autoValue > 0) {
                        $container.html('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                    } else {
                        $container.html('');
                    }
                    
                    // Hide reset icon
                    var $resetIcon = $wrapper.find('.zs-inventory-reset-icon');
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
                
                setTimeout(function() {
                    $toast.addClass('show');
                }, 100);
                
                setTimeout(function() {
                    $toast.removeClass('show');
                    setTimeout(function() {
                        $toast.remove();
                    }, 300);
                }, 3000);
            }
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
        
        // Guardar overrides manuales
        $overrides = $_POST['zs_inventory'] ?? [];
        ManualOverride::save($postId, $overrides);
        
        // Calcular materiales finales
        $calculated = MaterialCalculator::calculate($order);
        $final = ManualOverride::apply($calculated, $overrides);
        
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
        
        // Guardar overrides manuales
        ManualOverride::save($orderId, $inventory);
        
        // Calcular materiales finales
        $calculated = MaterialCalculator::calculate($order);
        $final = ManualOverride::apply($calculated, $inventory);
        
        // Crear/actualizar reservas
        ReservationManager::createOrUpdate($orderId, $final);
        
        wp_send_json_success(['message' => 'Inventory saved successfully']);
    }
}
