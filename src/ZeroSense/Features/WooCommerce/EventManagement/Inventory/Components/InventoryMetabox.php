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
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
        
        add_meta_box(
            'zs_inventory_materials',
            __('Inventory & Materials', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'normal',
            'high'
        );
    }
    
    /**
     * Renderiza el metabox
     */
    public function render(WP_Post $post): void
    {
        $order = wc_get_order($post->ID);
        
        if (!$order) {
            return;
        }
        
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        
        // Calcular materiales automáticamente
        $calculated = MaterialCalculator::calculate($order);
        
        // Obtener overrides manuales
        $overrides = ManualOverride::get($post->ID);
        
        // Aplicar overrides
        $final = ManualOverride::apply($calculated, $overrides);
        
        // Obtener todas las definiciones de materiales
        $materials = MaterialDefinitions::getAll();
        
        ?>
        <div class="zs-inventory-metabox">
            <style>
                .zs-inventory-metabox table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .zs-inventory-metabox th,
                .zs-inventory-metabox td {
                    padding: 8px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }
                .zs-inventory-metabox th {
                    background: #f5f5f5;
                    font-weight: 600;
                }
                .zs-inventory-metabox input[type="number"] {
                    width: 80px;
                }
                .zs-inventory-auto {
                    color: #666;
                    font-size: 12px;
                }
                .zs-inventory-override {
                    border: 2px solid #ff9800 !important;
                    background: #fff3e0;
                }
                .zs-inventory-reset {
                    margin-left: 5px;
                    cursor: pointer;
                    color: #d63638;
                    text-decoration: none;
                }
                .zs-inventory-reset:hover {
                    color: #a00;
                }
            </style>
            
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Material', 'zero-sense'); ?></th>
                        <th><?php esc_html_e('Auto', 'zero-sense'); ?></th>
                        <th><?php esc_html_e('Quantity', 'zero-sense'); ?></th>
                        <th><?php esc_html_e('Unit', 'zero-sense'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materials as $material): ?>
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
                            </td>
                            <td>
                                <span class="zs-inventory-auto">
                                    <?php echo esc_html($autoValue); ?>
                                </span>
                            </td>
                            <td>
                                <input 
                                    type="number" 
                                    name="zs_inventory[<?php echo esc_attr($materialKey); ?>]"
                                    value="<?php echo esc_attr($hasOverride ? $overrideValue : ''); ?>"
                                    placeholder="<?php echo esc_attr($autoValue); ?>"
                                    min="0"
                                    class="<?php echo $hasOverride ? 'zs-inventory-override' : ''; ?>"
                                />
                            </td>
                            <td>
                                <?php echo esc_html($material['unit']); ?>
                            </td>
                            <td>
                                <?php if ($hasOverride): ?>
                                    <a href="#" 
                                       class="zs-inventory-reset" 
                                       data-material="<?php echo esc_attr($materialKey); ?>"
                                       title="<?php esc_attr_e('Reset to auto', 'zero-sense'); ?>">
                                        🔄
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
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
            // Reset button
            $('.zs-inventory-reset').on('click', function(e) {
                e.preventDefault();
                var materialKey = $(this).data('material');
                var input = $('input[name="zs_inventory[' + materialKey + ']"]');
                input.val('').removeClass('zs-inventory-override');
                $(this).remove();
            });
            
            // Add override class on input
            $('.zs-inventory-metabox input[type="number"]').on('input', function() {
                if ($(this).val() !== '') {
                    $(this).addClass('zs-inventory-override');
                } else {
                    $(this).removeClass('zs-inventory-override');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Guarda los datos del metabox
     */
    public function save(int $postId, WP_Post $post): void
    {
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }
        
        $order = wc_get_order($postId);
        
        if (!$order) {
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
