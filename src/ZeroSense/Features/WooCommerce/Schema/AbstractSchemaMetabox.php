<?php
namespace ZeroSense\Features\WooCommerce\Schema;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;

/**
 * Abstract Schema Metabox
 * 
 * Base class for schema metaboxes on order edit screen.
 * Each schema gets its own independent metabox.
 */
abstract class AbstractSchemaMetabox implements FeatureInterface
{
    /**
     * Get the associated schema admin page
     */
    abstract protected function getSchemaAdminPage(): AbstractSchemaAdminPage;
    
    /**
     * Get metabox ID
     */
    protected function getMetaboxId(): string
    {
        return 'zs-ops-' . $this->getSchemaAdminPage()->getSchemaKey() . '-metabox';
    }
    
    /**
     * Get metabox title
     */
    protected function getMetaboxTitle(): string
    {
        return $this->getSchemaAdminPage()->getSchemaTitle();
    }
    
    /**
     * Get metabox priority
     */
    protected function getMetaboxPriority(): string
    {
        return 'high';
    }
    
    /**
     * Get metabox context
     */
    protected function getMetaboxContext(): string
    {
        return 'normal';
    }

    // FeatureInterface implementation
    
    public function getName(): string
    {
        return $this->getMetaboxTitle() . ' ' . __('Metabox', 'zero-sense');
    }

    public function getDescription(): string
    {
        return sprintf(
            __('Displays %s fields on order edit screen.', 'zero-sense'),
            $this->getMetaboxTitle()
        );
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20);
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
    }

    // Metabox functionality
    
    public function addMetabox(): void
    {
        $screen = class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
                  wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            $this->getMetaboxId(),
            $this->getMetaboxTitle(),
            [$this, 'render'],
            $screen,
            $this->getMetaboxContext(),
            $this->getMetaboxPriority()
        );
    }

    public function render($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post
            ? wc_get_order($postOrOrder->ID)
            : $postOrOrder;

        if (!$order instanceof WC_Order) {
            return;
        }

        $schema = $this->getSchemaAdminPage();
        $schemaKey = $schema->getSchemaKey();
        $metaKey = $schema->getMetaKey();
        $menuSlug = $schema->getMenuSlug();
        
        $orderId = $order->get_id();
        $savedData = $order->get_meta($metaKey, true);
        $savedData = is_array($savedData) ? $savedData : [];

        $fields = $this->getFieldsForOrder($orderId);

        wp_nonce_field('zs_schema_save_' . $schemaKey, 'zs_schema_nonce_' . $schemaKey);
        ?>
        <table class="widefat striped" style="margin-top:8px;" data-schema-metabox="<?php echo esc_attr($schemaKey); ?>">
            <tbody>
            <?php if ($fields === []) : 
                $url = admin_url('admin.php?page=' . $menuSlug);
            ?>
                <tr>
                    <td colspan="2" style="padding:12px;">
                        <?php echo esc_html(sprintf(__('%s fields are not configured yet.', 'zero-sense'), $this->getMetaboxTitle())); ?> 
                        <a href="<?php echo esc_url($url); ?>"><?php echo esc_html__('Configure schema', 'zero-sense'); ?></a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($fields as $key => $fieldConfig) :
                $raw = $savedData[$key] ?? [];
                $value = is_array($raw) ? ($raw['value'] ?? '') : $raw;
                
                SchemaFieldRenderer::renderField($schemaKey, $key, $fieldConfig, $value);
            endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function save(int $orderId): void
    {
        $schema = $this->getSchemaAdminPage();
        $schemaKey = $schema->getSchemaKey();
        $metaKey = $schema->getMetaKey();
        
        $nonceField = 'zs_schema_nonce_' . $schemaKey;
        $nonceAction = 'zs_schema_save_' . $schemaKey;
        
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonceField])), $nonceAction)) {
            return;
        }

        if (!current_user_can('edit_shop_order', $orderId)) {
            return;
        }

        $postKey = 'zs_schema_' . $schemaKey;
        $incoming = $_POST[$postKey] ?? [];
        
        if (!is_array($incoming)) {
            return;
        }

        // Use WooCommerce order object to save meta data
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $oldData = $order->get_meta($metaKey, true);
        if (!is_array($oldData)) {
            $oldData = [];
        }

        $allFields = $this->getAllFields();
        $saved = [];

        foreach ($allFields as $key => $fieldConfig) {
            $type = $fieldConfig['type'] ?? 'text';
            $rawValue = $incoming[$key] ?? '';
            $sanitizedValue = $this->sanitizeValue($rawValue, $type);
            $saved[$key] = ['value' => $sanitizedValue];

            $oldValue = isset($oldData[$key]) && is_array($oldData[$key]) ? ($oldData[$key]['value'] ?? '') : '';
            if ($oldValue !== $sanitizedValue) {
                FieldChangeTracker::trackFieldChange($orderId, $metaKey . '_' . $key);
            }
        }
        
        $order->delete_meta_data($metaKey);
        $order->update_meta_data($metaKey, $saved);
        $order->save();
        clean_post_cache($orderId);
    }

    /**
     * Get fields for a specific order (includes hidden fields with data)
     */
    protected function getFieldsForOrder(int $orderId): array
    {
        $schema = $this->getSchemaAdminPage();
        $metaKey = $schema->getMetaKey();
        
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return $this->getActiveFields();
        }
        
        $savedData = $order->get_meta($metaKey, true);
        $savedData = is_array($savedData) ? $savedData : [];
        
        $activeFields = $this->getActiveFields();
        $allSchemaFields = $schema->getAllFields();
        
        $fieldsForOrder = $activeFields;
        
        foreach ($allSchemaFields as $row) {
            $key = $row['key'] ?? '';
            $status = $row['status'] ?? 'active';
            
            if ($status === 'hidden' && isset($savedData[$key])) {
                $value = is_array($savedData[$key]) ? ($savedData[$key]['value'] ?? '') : $savedData[$key];
                
                if ($value !== '' && $value !== '0' && $value !== 0) {
                    $label = $row['label'] ?? '';
                    $name = 'ops_' . $schema->getSchemaKey() . '_label_' . $key;
                    $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
                    $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;
                    
                    $fieldsForOrder[$key] = [
                        'label' => $finalLabel,
                        'type' => $row['type'] ?? 'text',
                        'status' => 'hidden',
                    ];
                }
            }
        }
        
        return $fieldsForOrder;
    }

    /**
     * Get all fields (for saving)
     */
    protected function getAllFields(): array
    {
        $schema = $this->getSchemaAdminPage();
        $allFields = $schema->getAllFields();
        
        $fields = [];
        foreach ($allFields as $row) {
            $key = $row['key'] ?? '';
            $label = $row['label'] ?? '';
            
            if ($key === '' || $label === '') {
                continue;
            }
            
            $name = 'ops_' . $schema->getSchemaKey() . '_label_' . $key;
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;
            
            $fields[$key] = [
                'label' => $finalLabel,
                'type' => $row['type'] ?? 'text',
                'status' => $row['status'] ?? 'active',
            ];
        }
        
        return $fields;
    }

    /**
     * Get active fields only
     */
    protected function getActiveFields(): array
    {
        $schema = $this->getSchemaAdminPage();
        $activeFields = $schema->getActiveFields();
        
        $fields = [];
        foreach ($activeFields as $row) {
            $key = $row['key'] ?? '';
            $label = $row['label'] ?? '';
            $type = $row['type'] ?? 'text';
            
            if ($key === '' || $label === '') {
                continue;
            }
            
            $name = 'ops_' . $schema->getSchemaKey() . '_label_' . $key;
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;
            
            $fields[$key] = [
                'label' => $finalLabel,
                'type' => $type,
                'status' => 'active',
            ];
        }
        
        return $fields;
    }

    /**
     * Sanitize field value based on type
     */
    protected function sanitizeValue($value, string $type): string
    {
        switch ($type) {
            case 'bool':
                return $value === '1' || $value === 1 ? '1' : '0';
            case 'qty_int':
                return (string) absint($value);
            case 'textarea':
                return sanitize_textarea_field((string) $value);
            default:
                return sanitize_text_field((string) $value);
        }
    }
}
