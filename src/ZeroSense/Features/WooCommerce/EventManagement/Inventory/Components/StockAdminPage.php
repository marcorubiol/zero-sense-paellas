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
            '1.3.1'
        );
        
        wp_enqueue_script(
            'zs-stock-admin',
            $baseUrl . 'src/ZeroSense/Features/WooCommerce/EventManagement/Inventory/assets/js/stock-admin.js',
            ['jquery'],
            '1.1.6',
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
        
        // Obtener stock actual
        $stock = StockManager::getAllStockMatrix();
        
        ?>
        <div class="wrap zs-stock-admin-page">
            <h1><?php esc_html_e('Stock Management', 'zero-sense'); ?></h1>
            
            <!-- Explicación -->
            <div class="zs-stock-help" style="background: #fff; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3 style="margin-top: 0;"><?php esc_html_e('How Stock Management Works', 'zero-sense'); ?></h3>
                <p>
                    <strong><?php esc_html_e('This page manages your total inventory per service area.', 'zero-sense'); ?></strong>
                </p>
                <ul style="margin-left: 20px;">
                    <li><strong><?php esc_html_e('Total Stock:', 'zero-sense'); ?></strong> <?php esc_html_e('Enter the total quantity you own for each material in each service area.', 'zero-sense'); ?></li>
                    <li><strong><?php esc_html_e('Automatic Calculation:', 'zero-sense'); ?></strong> <?php esc_html_e('When you create an order, the system automatically calculates required materials based on guest count and products.', 'zero-sense'); ?></li>
                    <li><strong><?php esc_html_e('Manual Override:', 'zero-sense'); ?></strong> <?php esc_html_e('In the order edit screen, you can manually adjust quantities if needed.', 'zero-sense'); ?></li>
                    <li><strong><?php esc_html_e('Reservations:', 'zero-sense'); ?></strong> <?php esc_html_e('Materials are automatically reserved for each order based on event date.', 'zero-sense'); ?></li>
                </ul>
                <p style="margin-bottom: 0;">
                    💡 <em><?php esc_html_e('Tip: Use the search box to quickly find specific materials.', 'zero-sense'); ?></em>
                </p>
            </div>
            
            <!-- Buscador -->
            <div class="zs-stock-header">
                <div class="zs-stock-search">
                    <input 
                        type="text" 
                        id="zs-stock-search" 
                        placeholder="<?php esc_attr_e('Search materials...', 'zero-sense'); ?>"
                        class="regular-text"
                    />
                </div>
                <div class="zs-stock-actions">
                    <button type="button" class="zs-lock-toggle" data-locked="true" title="<?php esc_attr_e('Click to unlock table for editing', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-lock"></span>
                        <span class="lock-text"><?php esc_html_e('Locked', 'zero-sense'); ?></span>
                    </button>
                </div>
            </div>
            
            <!-- Tabla Matricial -->
            <div class="zs-stock-table-wrapper">
                <table class="widefat striped zs-stock-table">
                    <thead>
                        <tr>
                            <th class="zs-sticky-col"><?php esc_html_e('Material', 'zero-sense'); ?></th>
                            <?php foreach ($serviceAreas as $area): ?>
                                <th><?php echo esc_html($area->name); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentCategory = '';
                        foreach ($materials as $material): 
                            // Mostrar header de categoría si cambia
                            if ($currentCategory !== $material['category']):
                                $currentCategory = $material['category'];
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
                                $categoryLabel = $categoryLabels[$currentCategory] ?? ucfirst($currentCategory);
                        ?>
                            <tr class="zs-category-header">
                                <td colspan="<?php echo count($serviceAreas) + 1; ?>">
                                    <?php echo esc_html($categoryLabel); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                            <tr data-material="<?php echo esc_attr($material['key']); ?>" data-category="<?php echo esc_attr($material['category']); ?>">
                                <td class="zs-sticky-col">
                                    <strong><?php echo esc_html($material['label']); ?></strong>
                                    <?php if (!empty($material['description'])): ?>
                                        <br><small style="color: #666; font-weight: normal;"><?php echo esc_html($material['description']); ?></small>
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
            
            <!-- Sticky Footer con botón de guardar -->
            <div class="zs-stock-footer">
                <button type="button" class="button button-primary zs-save-stock">
                    <?php esc_html_e('Save Changes', 'zero-sense'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Actualiza stock
     */
    public function ajaxUpdateStock(): void
    {
        error_log('=== AJAX UPDATE STOCK CALLED ===');
        error_log('$_POST: ' . print_r($_POST, true));
        
        check_ajax_referer('zs_stock_admin', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            error_log('Permission denied');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $changesJson = $_POST['changes'] ?? '';
        error_log('Changes JSON (raw): ' . $changesJson);
        
        // WordPress escapes slashes, need to strip them
        $changesJson = stripslashes($changesJson);
        error_log('Changes JSON (after stripslashes): ' . $changesJson);
        
        $changes = json_decode($changesJson, true);
        error_log('Decoded changes: ' . print_r($changes, true));
        error_log('JSON decode error: ' . json_last_error_msg());
        
        if (empty($changes)) {
            error_log('Changes is empty!');
            wp_send_json_error(['message' => 'No changes to save', 'debug' => [
                'changesJson' => $changesJson,
                'decoded' => $changes,
                'post' => $_POST
            ]]);
        }
        
        StockManager::updateMultiple($changes);
        
        wp_send_json_success(['message' => 'Stock updated successfully']);
    }
}
