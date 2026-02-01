<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

class OpsMaterialSchemaAdminPage implements FeatureInterface
{
    private const OPTION_SCHEMA = 'zs_ops_material_schema';

    public function getName(): string
    {
        return __('Material & Logistics Schema', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Configure the Material & Logistics fields available on WooCommerce orders.', 'zero-sense');
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
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_zs_ops_material_schema_save', [$this, 'handleSave']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueStyles']);
    }

    public function getPriority(): int
    {
        return 25;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
    }

    public function enqueueStyles(): void
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'woocommerce_page_zs_ops_material_schema') {
            $css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-material-logistics.css';
            $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : ZERO_SENSE_VERSION;
            
            wp_enqueue_style(
                'zero-sense-admin-material-logistics',
                plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-material-logistics.css',
                [],
                $css_ver
            );
        }
    }

    public function addAdminMenu(): void
    {
        global $submenu;

        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $menuItem) {
                if (isset($menuItem[2]) && $menuItem[2] === 'zs_ops_material_schema') {
                    return;
                }
            }
        }

        add_submenu_page(
            'woocommerce',
            __('Material & Logistics Schema', 'zero-sense'),
            __('Material & Logistics', 'zero-sense'),
            'manage_options',
            'zs_ops_material_schema',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        $schema = get_option(self::OPTION_SCHEMA, []);
        if (!is_array($schema)) {
            $schema = [];
        }

        $allowedTypes = $this->getAllowedTypes();

        $updated = isset($_GET['updated']) && sanitize_text_field(wp_unslash((string) $_GET['updated'])) === '1';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Material & Logistics Schema', 'zero-sense')); ?></h1>

            <?php if ($updated): ?>
                <div class="notice notice-success"><p><?php esc_html_e('Schema saved.', 'zero-sense'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('zs_ops_material_schema_save'); ?>
                <input type="hidden" name="action" value="zs_ops_material_schema_save">

                <table class="widefat striped" style="max-width: 1100px;">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><?php esc_html_e('Order', 'zero-sense'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('Key', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Label', 'zero-sense'); ?></th>
                            <th style="width: 220px;"><?php esc_html_e('Type', 'zero-sense'); ?></th>
                            <th style="width: 90px;"></th>
                        </tr>
                    </thead>
                    <tbody id="zs-ops-schema-rows">
                        <?php foreach ($schema as $row):
                            $key = isset($row['key']) ? (string) $row['key'] : '';
                            $label = isset($row['label']) ? (string) $row['label'] : '';
                            $type = isset($row['type']) ? (string) $row['type'] : 'text';
                            ?>
                            <tr class="zs-ops-sortable-row">
                                <td>
                                    <span class="zs-ops-drag-handle dashicons dashicons-menu"></span>
                                </td>
                                <td>
                                    <span class="zs-ops-key-display"><?php echo esc_html($key); ?></span>
                                    <span class="zs-ops-key-info" title="<?php esc_attr_e('Generated automatically from label', 'zero-sense'); ?>">
                                        <span class="dashicons dashicons-info"></span>
                                    </span>
                                    <input type="hidden" name="zs_ops_material_schema[key][]" value="<?php echo esc_attr($key); ?>" class="zs-ops-key-field">
                                </td>
                                <td>
                                    <input type="text" name="zs_ops_material_schema[label][]" value="<?php echo esc_attr($label); ?>" class="regular-text zs-ops-label-field" style="width:100%;" placeholder="e.g. Work tables">
                                </td>
                                <td>
                                    <select name="zs_ops_material_schema[type][]" style="width:100%;">
                                        <?php foreach ($allowedTypes as $typeValue => $typeLabel): ?>
                                            <option value="<?php echo esc_attr($typeValue); ?>" <?php selected($type, $typeValue); ?>><?php echo esc_html($typeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button zs-ops-schema-remove">
                                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                        <span class="screen-reader-text"><?php esc_html_e('Remove', 'zero-sense'); ?></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="tablenav top" style="max-width:1100px;">
                    <div class="alignleft actions">
                        <button type="button" class="button" id="zs-ops-schema-add">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Add field', 'zero-sense'); ?>
                        </button>
                    </div>
                    <div class="alignright">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Save', 'zero-sense'); ?>
                        </button>
                    </div>
                    <br class="clear">
                </div>
            </form>

            <script>
                (function() {
                    var tbody = document.getElementById('zs-ops-schema-rows');
                    var addBtn = document.getElementById('zs-ops-schema-add');
                    if (!tbody || !addBtn) return;

                    function generateKeyFromLabel(label) {
                        return label.toLowerCase()
                            .replace(/[^a-z0-9\s-]/g, '')
                            .replace(/\s+/g, '_')
                            .replace(/-+/g, '_')
                            .replace(/^_+|_+$/g, '')
                            .substring(0, 50);
                    }

                    function updateKeyDisplay(labelField, keyDisplay, keyField) {
                        var label = labelField.value.trim();
                        var generatedKey = '';
                        if (label) {
                            generatedKey = generateKeyFromLabel(label);
                        }
                        keyDisplay.textContent = generatedKey;
                        keyField.value = generatedKey;
                    }

                    function bindRemoveButtons(scope) {
                        var buttons = (scope || document).querySelectorAll('.zs-ops-schema-remove');
                        buttons.forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var tr = btn.closest('tr');
                                if (tr) tr.remove();
                            });
                        });
                    }

                    function bindLabelChange(scope) {
                        var labelFields = (scope || document).querySelectorAll('.zs-ops-label-field');
                        labelFields.forEach(function(labelField) {
                            var tr = labelField.closest('tr');
                            var keyDisplay = tr.querySelector('.zs-ops-key-display');
                            var keyField = tr.querySelector('.zs-ops-key-field');
                            
                            labelField.addEventListener('input', function() {
                                updateKeyDisplay(labelField, keyDisplay, keyField);
                            });
                        });
                    }

                    bindRemoveButtons();
                    bindLabelChange();
                    updateSortable();

                    addBtn.addEventListener('click', function() {
                        var tr = document.createElement('tr');
                        tr.className = 'zs-ops-sortable-row';
                        tr.innerHTML = '' +
                            '<td><span class="zs-ops-drag-handle dashicons dashicons-menu"></span></td>' +
                            '<td><span class="zs-ops-key-display"></span><span class="zs-ops-key-info" title="<?php echo esc_js(__('Generated automatically from label', 'zero-sense')); ?>"><span class="dashicons dashicons-info"></span></span><input type="hidden" name="zs_ops_material_schema[key][]" class="zs-ops-key-field"></td>' +
                            '<td><input type="text" name="zs_ops_material_schema[label][]" class="regular-text zs-ops-label-field" style="width:100%;" placeholder="e.g. New field"></td>' +
                            '<td>' +
                                '<select name="zs_ops_material_schema[type][]" style="width:100%;">' +
                                    <?php
                                        $opts = [];
                                        foreach ($allowedTypes as $typeValue => $typeLabel) {
                                            $opts[] = '<option value="' . esc_js($typeValue) . '">' . esc_js($typeLabel) . '</option>';
                                        }
                                        echo json_encode(implode('', $opts));
                                    ?> +
                                '</select>' +
                            '</td>' +
                            '<td><button type="button" class="button zs-ops-schema-remove"><span class="dashicons dashicons-trash" style="vertical-align: middle;"></span><span class="screen-reader-text"><?php echo esc_js(__('Remove', 'zero-sense')); ?></span></button></td>';

                        tbody.appendChild(tr);
                        bindRemoveButtons(tr);
                        bindLabelChange(tr);
                        updateSortable();
                    });

                    function updateSortable() {
                        if (typeof Sortable !== 'undefined' && tbody) {
                            if (window.zsSortable) {
                                window.zsSortable.destroy();
                            }
                            window.zsSortable = Sortable.create(tbody, {
                                handle: '.zs-ops-drag-handle',
                                animation: 150,
                                ghostClass: 'zs-ops-sortable-ghost',
                                chosenClass: 'zs-ops-sortable-chosen',
                                dragClass: 'zs-ops-sortable-drag'
                            });
                        }
                    }

                    // Load SortableJS if not available
                    if (typeof Sortable === 'undefined') {
                        var script = document.createElement('script');
                        script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
                        script.onload = updateSortable;
                        document.head.appendChild(script);
                    }
                })();
            </script>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        check_admin_referer('zs_ops_material_schema_save');

        $raw = $_POST['zs_ops_material_schema'] ?? null;
        if (!is_array($raw)) {
            wp_safe_redirect(admin_url('admin.php?page=zs_ops_material_schema'));
            exit;
        }

        $keys = isset($raw['key']) && is_array($raw['key']) ? $raw['key'] : [];
        $labels = isset($raw['label']) && is_array($raw['label']) ? $raw['label'] : [];
        $types = isset($raw['type']) && is_array($raw['type']) ? $raw['type'] : [];

        $allowedTypeKeys = array_keys($this->getAllowedTypes());

        $schema = [];
        $seen = [];

        $count = max(count($labels), count($types));
        for ($i = 0; $i < $count; $i++) {
            $label = isset($labels[$i]) ? sanitize_text_field((string) $labels[$i]) : '';
            $type = isset($types[$i]) ? sanitize_key((string) $types[$i]) : 'text';

            if ($label === '') {
                continue;
            }

            // Generate key from label
            $key = strtolower($label);
            $key = preg_replace('/[^a-z0-9\s-]/', '', $key);
            $key = preg_replace('/\s+/', '_', $key);
            $key = preg_replace('/-+/', '_', $key);
            $key = preg_replace('/^_+|_+$/', '', $key);
            $key = substr($key, 0, 50);
            $key = sanitize_key($key);

            if (!in_array($type, $allowedTypeKeys, true)) {
                $type = 'text';
            }

            if (isset($seen[$key])) {
                $schema[$seen[$key]] = ['key' => $key, 'label' => $label, 'type' => $type];
                continue;
            }

            $seen[$key] = count($schema);
            $schema[] = ['key' => $key, 'label' => $label, 'type' => $type];
        }

        if ($schema === []) {
            $schema = [];
        }

        update_option(self::OPTION_SCHEMA, $schema, false);

        foreach ($schema as $row) {
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }
            do_action('wpml_register_single_string', 'zero-sense', 'ops_material_label_' . $key, $label);
        }

        $redirect = add_query_arg(
            [
                'page' => 'zs_ops_material_schema',
                'updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private function getAllowedTypes(): array
    {
        return [
            'text' => __('Text', 'zero-sense'),
            'textarea' => __('Textarea', 'zero-sense'),
            'qty_int' => __('Quantity', 'zero-sense'),
            'bool' => __('Checkbox', 'zero-sense'),
        ];
    }
}
