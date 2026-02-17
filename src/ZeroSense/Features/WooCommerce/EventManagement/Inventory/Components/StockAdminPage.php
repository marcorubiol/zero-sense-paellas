<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\StockManager;

class StockAdminPage
{
    /**
     * Registra la página de administración
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_zs_update_stock', [$this, 'ajaxUpdateStock']);
    }
    
    /**
     * Añade la página al menú
     */
    public function addMenuPage(): void
    {
        add_submenu_page(
            'event-operations',
            __('Stock Management', 'zero-sense'),
            __('Stock Management', 'zero-sense'),
            'manage_woocommerce',
            'zs-stock-management',
            [$this, 'render']
        );
    }
    
    /**
     * Encola assets
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'event-operations_page_zs-stock-management') {
            return;
        }
        
        $baseUrl = defined('ZERO_SENSE_URL') ? ZERO_SENSE_URL : plugin_dir_url(dirname(dirname(dirname(dirname(dirname(dirname(__FILE__)))))));
        
        wp_enqueue_style('dashicons');
        
        wp_enqueue_style(
            'zs-stock-admin',
            $baseUrl . 'src/ZeroSense/Features/WooCommerce/EventManagement/Inventory/assets/css/stock-admin.css',
            ['dashicons'],
            '1.3.4'
        );
        
        wp_enqueue_script(
            'zs-stock-admin',
            $baseUrl . 'src/ZeroSense/Features/WooCommerce/EventManagement/Inventory/assets/js/stock-admin.js',
            ['jquery'],
            '1.2.0',
            true
        );
        
        wp_localize_script('zs-stock-admin', 'zsStockAdmin', [
            'nonce' => wp_create_nonce('zs_stock_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
    }
    
    /**
     * Renderiza la página
     */
    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Obtener service areas
        $serviceAreas = get_terms([
            'taxonomy' => 'service-area',
            'hide_empty' => false,
        ]);
        
        // Obtener materiales
        $materials = MaterialDefinitions::getAll();
        $parentCategories = MaterialDefinitions::getParentCategories();
        
        // Agrupar por parent_category
        $groupedByParent = [];
        foreach ($materials as $material) {
            $parentCat = $material['parent_category'] ?? 'altres';
            if (!isset($groupedByParent[$parentCat])) {
                $groupedByParent[$parentCat] = [];
            }
            $groupedByParent[$parentCat][] = $material;
        }
        
        // Obtener stock actual
        $stock = StockManager::getAllStockMatrix();
        
        // Labels de categorías
        $categoryLabels = [
            'paelles' => 'Paelles',
            'cremadors' => 'Cremadors',
            'equipament_cuina' => 'Equipament de Cuina',
            'roba_personal' => 'Roba i Vestimenta',
            'textils_neteja' => 'Textils i Neteja',
            'caixes_contenidors' => 'Caixes i Contenidors',
            'refrigeracio' => 'Refrigeració',
            'utensilis_servir' => 'Utensilis per Servir',
            'mobiliari_esdeveniments' => 'Mobiliari i Esdeveniments',
            'vaixella_menatge' => 'Vaixella i Menatge',
            'altres' => 'Altres',
        ];
        
        ?>
        <div class="wrap zs-stock-admin-page">
            <h1><?php esc_html_e('Stock Management', 'zero-sense'); ?></h1>
            
            <!-- Explicación -->
            <div class="zs-stock-help" style="background: #fff; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 15px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <p style="margin: 0 0 4px 0;">
                    <strong><?php esc_html_e('Manage your total inventory per service area.', 'zero-sense'); ?></strong>
                </p>
                <p style="margin: 0 0 8px 0;">
                    <?php esc_html_e('Materials are organized by category in collapsible sections. Click Unlock to edit quantities, then Save & Lock when done.', 'zero-sense'); ?>
                </p>
                <p style="margin: 0; font-size: 13px; color: #666;">
                    💡 <?php esc_html_e('Use search to filter materials. Materials are automatically reserved for orders based on event date.', 'zero-sense'); ?>
                </p>
            </div>
            
            <!-- Buscador -->
            <div class="zs-stock-header">
                <div class="zs-stock-search">
                    <div style="position: relative; display: inline-block;">
                        <input 
                            type="text" 
                            id="zs-stock-search" 
                            placeholder="<?php esc_attr_e('Search materials...', 'zero-sense'); ?>"
                            class="regular-text"
                        />
                        <span class="dashicons dashicons-no-alt zs-search-clear" style="display: none; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666;" title="<?php esc_attr_e('Clear search', 'zero-sense'); ?>"></span>
                    </div>
                </div>
                <div class="zs-stock-actions">
                    <button type="button" class="zs-lock-toggle" data-locked="true" title="<?php esc_attr_e('Click to unlock for editing', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-lock"></span>
                        <span class="lock-text"><?php esc_html_e('Unlock', 'zero-sense'); ?></span>
                    </button>
                    <button type="button" class="zs-save-stock" style="display:none;" title="<?php esc_attr_e('Save changes and lock table', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Save & Lock', 'zero-sense'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Acordeones con Tablas -->
            <?php foreach ($groupedByParent as $parentKey => $materialsInParent): ?>
                <div class="zs-stock-accordion">
                    <div class="zs-stock-accordion-header">
                        <span><?php echo esc_html($parentCategories[$parentKey] ?? $parentKey); ?></span>
                        <span class="dashicons dashicons-arrow-down-alt2 zs-stock-accordion-icon"></span>
                    </div>
                    <div class="zs-stock-accordion-content">
                        <div class="zs-stock-table-wrapper">
                            <table class="widefat striped zs-stock-table">
                                <tbody>
                                    <?php 
                                    $currentCategory = '';
                                    foreach ($materialsInParent as $material): 
                                        // Mostrar header de categoría si cambia
                                        if ($currentCategory !== $material['category']):
                                            $currentCategory = $material['category'];
                                            $categoryLabel = $categoryLabels[$currentCategory] ?? ucfirst($currentCategory);
                                    ?>
                                        <tr class="zs-category-header">
                                            <td style="font-weight: 300;"><?php echo esc_html($categoryLabel); ?></td>
                                            <?php foreach ($serviceAreas as $area): ?>
                                                <td style="text-align: center; font-weight: 300;"><?php echo esc_html($area->name); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endif; ?>
                                    
                                        <tr data-material="<?php echo esc_attr($material['key']); ?>" data-category="<?php echo esc_attr($material['category']); ?>" data-parent="<?php echo esc_attr($parentKey); ?>">
                                            <td class="zs-sticky-col">
                                                <div>
                                                    <strong><?php echo esc_html($material['label']); ?></strong>
                                                </div>
                                                <?php if (!empty($material['description'])): ?>
                                                    <div style="color: #666; font-weight: normal; font-size: 11px; font-style: italic; margin-top: 4px;"><?php echo esc_html($material['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($serviceAreas as $area): ?>
                                                <?php
                                                $key = $material['key'] . '|' . $area->term_id;
                                                $quantity = $stock[$key] ?? 0;
                                                ?>
                                                <td>
                                                    <input 
                                                        type="number" 
                                                        class="stock-input"
                                                        data-key="<?php echo esc_attr($key); ?>"
                                                        value="<?php echo esc_attr($quantity); ?>"
                                                        min="0"
                                                        disabled
                                                    />
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX: Actualiza stock
     */
    public function ajaxUpdateStock(): void
    {
        check_ajax_referer('zs_stock_admin', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $changesJson = $_POST['changes'] ?? '';
        $changesJson = stripslashes($changesJson);
        $changes = json_decode($changesJson, true);
        
        if (empty($changes)) {
            wp_send_json_error(['message' => 'No changes to save']);
        }
        
        StockManager::updateMultiple($changes);
        
        wp_send_json_success(['message' => 'Stock updated successfully']);
    }
}
