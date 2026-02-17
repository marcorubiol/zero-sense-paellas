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
                .zs-inventory-metabox input[type="number"]:disabled {
                    background: transparent;
                    border: none;
                    color: #2c3338;
                    font-weight: 500;
                    cursor: default;
                }
                .zs-inventory-override {
                    border: 2px solid #ff9800 !important;
                    background: #fff3e0 !important;
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
                .zs-inventory-recalc-btn {
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
                .zs-inventory-lock-btn:focus,
                .zs-inventory-recalc-btn:focus {
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
                .zs-inventory-metabox table th:nth-child(2),
                .zs-inventory-metabox table td:nth-child(2) {
                    width: 150px;
                }
                .zs-inventory-metabox table th:nth-child(3),
                .zs-inventory-metabox table td:nth-child(3) {
                    width: 50px;
                }
                .zs-inventory-badge {
                    display: inline-block;
                    padding: 2px 6px;
                    font-size: 10px;
                    font-weight: 600;
                    border-radius: 3px;
                    text-transform: uppercase;
                    margin-left: 6px;
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
                    color: #2271b1;
                    transition: transform 0.2s ease;
                }
                .zs-inventory-reset-icon:hover {
                    transform: rotate(-45deg);
                    color: #135e96;
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
            </style>
            
            <div class="zs-inventory-header">
                <div>
                    <strong><?php esc_html_e('Inventory & Materials', 'zero-sense'); ?></strong>
                </div>
                <div class="zs-inventory-controls">
                    <button type="button" class="zs-inventory-recalc-btn" title="<?php esc_attr_e('Recalculate all from order data', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Recalculate All', 'zero-sense'); ?>
                    </button>
                    <button type="button" class="zs-inventory-lock-btn" data-locked="true" title="<?php esc_attr_e('Click to unlock for editing', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-lock"></span>
                        <span class="lock-text"><?php esc_html_e('Locked', 'zero-sense'); ?></span>
                    </button>
                </div>
            </div>
            
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Material', 'zero-sense'); ?></th>
                        <th><?php esc_html_e('Final Quantity', 'zero-sense'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $catKey => $catLabel): ?>
                        <?php if (isset($groupedMaterials[$catKey])): ?>
                            <tr class="zs-inventory-category-header">
                                <td colspan="4"><?php echo esc_html($catLabel); ?></td>
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
                                <input 
                                    type="number" 
                                    name="zs_inventory[<?php echo esc_attr($materialKey); ?>]"
                                    value="<?php echo esc_attr($hasOverride ? $overrideValue : $autoValue); ?>"
                                    data-auto="<?php echo esc_attr($autoValue); ?>"
                                    min="0"
                                    class="zs-inventory-input <?php echo $hasOverride ? 'zs-inventory-override' : ''; ?>"
                                    disabled
                                />
                                <?php if ($hasOverride): ?>
                                    <span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>
                                <?php elseif ($autoValue > 0): ?>
                                    <span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasOverride): ?>
                                    <span class="dashicons dashicons-update zs-inventory-reset-icon hidden" 
                                       data-material="<?php echo esc_attr($materialKey); ?>"
                                       title="<?php esc_attr_e('Reset to auto', 'zero-sense'); ?>">
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 15px; color: #666; font-size: 13px;">
                <strong><?php esc_html_e('Note:', 'zero-sense'); ?></strong>
                <?php esc_html_e('Leave empty to use automatic calculation. Enter a value to override manually.', 'zero-sense'); ?>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var isLocked = true;
            
            // Lock/Unlock toggle
            $('.zs-inventory-lock-btn').on('click', function(e) {
                e.preventDefault();
                isLocked = !isLocked;
                var $btn = $(this);
                var $inputs = $('.zs-inventory-input');
                var $resetIcons = $('.zs-inventory-reset-icon');
                
                if (isLocked) {
                    $inputs.prop('disabled', true);
                    $resetIcons.addClass('hidden');
                    $btn.attr('data-locked', 'true');
                    $btn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                    $btn.find('.lock-text').text('<?php echo esc_js(__('Locked', 'zero-sense')); ?>');
                    $btn.attr('title', '<?php echo esc_js(__('Click to unlock for editing', 'zero-sense')); ?>');
                } else {
                    $inputs.prop('disabled', false);
                    $resetIcons.removeClass('hidden');
                    $btn.attr('data-locked', 'false');
                    $btn.find('.dashicons').removeClass('dashicons-lock').addClass('dashicons-unlock');
                    $btn.find('.lock-text').text('<?php echo esc_js(__('Unlocked', 'zero-sense')); ?>');
                    $btn.attr('title', '<?php echo esc_js(__('Click to lock', 'zero-sense')); ?>');
                }
            });
            
            // Reset individual material to auto
            $(document).on('click', '.zs-inventory-reset-icon', function(e) {
                e.preventDefault();
                if (isLocked) return;
                
                var materialKey = $(this).data('material');
                var $input = $('input[name="zs_inventory[' + materialKey + ']"]');
                var autoValue = $input.data('auto');
                
                $input.val(autoValue).removeClass('zs-inventory-override');
                
                // Update badge
                var $td = $input.parent();
                $td.find('.zs-inventory-badge').remove();
                if (autoValue > 0) {
                    $input.after('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                }
                
                $(this).remove();
            });
            
            // Recalculate all
            $('.zs-inventory-recalc-btn').on('click', function(e) {
                e.preventDefault();
                
                $('.zs-inventory-input').each(function() {
                    var $input = $(this);
                    var autoValue = $input.data('auto');
                    $input.val(autoValue).removeClass('zs-inventory-override');
                    
                    // Update badges
                    var $td = $input.parent();
                    $td.find('.zs-inventory-badge').remove();
                    if (autoValue > 0) {
                        $input.after('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
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
                
                if (currentValue !== '' && currentValue != autoValue) {
                    $input.addClass('zs-inventory-override');
                    
                    // Update badge to manual
                    $td.find('.zs-inventory-badge').remove();
                    $input.after('<span class="zs-inventory-badge zs-inventory-badge-manual">MAN</span>');
                    
                    // Add reset icon if not present
                    var $resetTd = $input.closest('tr').find('td:last');
                    if (!$resetTd.find('.zs-inventory-reset-icon').length) {
                        $resetTd.html('<span class="dashicons dashicons-update zs-inventory-reset-icon" data-material="' + materialKey + '" title="<?php echo esc_js(__('Reset to auto', 'zero-sense')); ?>"></span>');
                    }
                } else {
                    $input.removeClass('zs-inventory-override');
                    
                    // Update badge to auto
                    $td.find('.zs-inventory-badge').remove();
                    if (autoValue > 0) {
                        $input.after('<span class="zs-inventory-badge zs-inventory-badge-auto">AUTO</span>');
                    }
                    
                    // Remove reset icon
                    $input.closest('tr').find('.zs-inventory-reset-icon').remove();
                }
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
        
        // Guardar overrides manuales
        $overrides = $_POST['zs_inventory'] ?? [];
        ManualOverride::save($postId, $overrides);
        
        // Calcular materiales finales
        $calculated = MaterialCalculator::calculate($order);
        $final = ManualOverride::apply($calculated, $overrides);
        
        // Crear/actualizar reservas
        ReservationManager::createOrUpdate($postId, $final);
    }
}
