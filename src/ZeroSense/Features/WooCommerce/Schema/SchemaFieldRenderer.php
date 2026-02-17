<?php
namespace ZeroSense\Features\WooCommerce\Schema;

/**
 * Schema Field Renderer
 * 
 * Renders schema fields in order metaboxes.
 * Handles all field types: text, textarea, qty_int, bool
 */
class SchemaFieldRenderer
{
    /**
     * Render a field input
     */
    public static function renderField(string $schemaKey, string $fieldKey, array $fieldConfig, $value): void
    {
        $type = $fieldConfig['type'] ?? 'text';
        $label = $fieldConfig['label'] ?? '';
        $status = $fieldConfig['status'] ?? 'active';
        $isHidden = $status === 'hidden';
        
        $inputName = 'zs_schema_' . $schemaKey . '[' . $fieldKey . ']';
        $inputId = 'zs_schema_' . $schemaKey . '_' . $fieldKey;
        
        $rowClass = $isHidden ? 'zs-ops-schema-hidden-field' : '';
        ?>
        <tr class="<?php echo esc_attr($rowClass); ?>">
            <th style="width:260px;">
                <label for="<?php echo esc_attr($inputId); ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($isHidden) : ?>
                        <span class="zs-ops-hidden-badge" title="<?php esc_attr_e('This field is hidden in schema but has data', 'zero-sense'); ?>">
                            <?php esc_html_e('Hidden', 'zero-sense'); ?>
                        </span>
                    <?php endif; ?>
                </label>
            </th>
            <td>
                <?php self::renderInput($type, $inputId, $inputName, $value); ?>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Render the appropriate input based on field type
     */
    private static function renderInput(string $type, string $inputId, string $inputName, $value): void
    {
        switch ($type) {
            case 'bool':
                self::renderBoolField($inputId, $inputName, $value);
                break;
            case 'qty_int':
                self::renderQtyField($inputId, $inputName, $value);
                break;
            case 'textarea':
                self::renderTextareaField($inputId, $inputName, $value);
                break;
            default:
                self::renderTextField($inputId, $inputName, $value);
                break;
        }
    }
    
    /**
     * Render boolean/checkbox field
     */
    private static function renderBoolField(string $inputId, string $inputName, $value): void
    {
        ?>
        <input type="hidden" name="<?php echo esc_attr($inputName); ?>" value="0">
        <label class="zs-switch">
            <input
                type="checkbox"
                id="<?php echo esc_attr($inputId); ?>"
                name="<?php echo esc_attr($inputName); ?>"
                value="1"
                <?php checked((string) $value, '1'); ?>
            >
            <span class="zs-slider"></span>
        </label>
        <?php
    }
    
    /**
     * Render quantity/integer field
     */
    private static function renderQtyField(string $inputId, string $inputName, $value): void
    {
        ?>
        <input
            type="number"
            id="<?php echo esc_attr($inputId); ?>"
            name="<?php echo esc_attr($inputName); ?>"
            value="<?php echo esc_attr((string) $value); ?>"
            min="0"
            step="1"
            class="small-text"
        >
        <?php
    }
    
    /**
     * Render textarea field
     */
    private static function renderTextareaField(string $inputId, string $inputName, $value): void
    {
        ?>
        <textarea
            id="<?php echo esc_attr($inputId); ?>"
            name="<?php echo esc_attr($inputName); ?>"
            rows="3"
            class="widefat"><?php echo esc_textarea(is_string($value) ? $value : ''); ?></textarea>
        <?php
    }
    
    /**
     * Render text field
     */
    private static function renderTextField(string $inputId, string $inputName, $value): void
    {
        ?>
        <input
            type="text"
            id="<?php echo esc_attr($inputId); ?>"
            name="<?php echo esc_attr($inputName); ?>"
            value="<?php echo esc_attr(is_string($value) ? $value : ''); ?>"
            class="widefat"
        >
        <?php
    }
}
