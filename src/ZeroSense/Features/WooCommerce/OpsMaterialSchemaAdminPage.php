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
        
        $schema = $this->migrateSchema($schema);

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
                            <th style="width: 180px;"><?php esc_html_e('Key', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Label', 'zero-sense'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('Type', 'zero-sense'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Usage', 'zero-sense'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('Actions', 'zero-sense'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="zs-ops-schema-rows">
                        <?php foreach ($schema as $row):
                            $key = isset($row['key']) ? (string) $row['key'] : '';
                            $label = isset($row['label']) ? (string) $row['label'] : '';
                            $type = isset($row['type']) ? (string) $row['type'] : 'text';
                            $status = isset($row['status']) ? (string) $row['status'] : 'active';
                            $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : time();
                            $usageCount = $this->getFieldUsageCount($key);
                            $isHidden = $status === 'hidden';
                            $rowClass = 'zs-ops-sortable-row' . ($isHidden ? ' zs-ops-row-hidden' : '');
                            ?>
                            <tr class="<?php echo esc_attr($rowClass); ?>" data-field-key="<?php echo esc_attr($key); ?>">
                                <td>
                                    <span class="zs-ops-drag-handle dashicons dashicons-menu"></span>
                                </td>
                                <td>
                                    <span class="zs-ops-key-display"><?php echo esc_html($key); ?></span>
                                    <input type="hidden" name="zs_ops_material_schema[key][]" value="<?php echo esc_attr($key); ?>" class="zs-ops-key-field">
                                    <input type="hidden" name="zs_ops_material_schema[status][]" value="<?php echo esc_attr($status); ?>" class="zs-ops-status-field">
                                    <input type="hidden" name="zs_ops_material_schema[created_at][]" value="<?php echo esc_attr($createdAt); ?>">
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
                                <td class="zs-ops-usage-count">
                                    <span class="zs-ops-usage-badge"><?php echo esc_html(sprintf(_n('%d order', '%d orders', $usageCount, 'zero-sense'), $usageCount)); ?></span>
                                </td>
                                <td>
                                    <button type="button" class="button zs-ops-toggle-visibility" data-current-status="<?php echo esc_attr($status); ?>" title="<?php echo $isHidden ? esc_attr__('Unhide field', 'zero-sense') : esc_attr__('Hide field', 'zero-sense'); ?>">
                                        <span class="dashicons <?php echo $isHidden ? 'dashicons-visibility' : 'dashicons-hidden'; ?>" style="vertical-align: middle;"></span>
                                        <span class="zs-ops-toggle-text"><?php echo $isHidden ? esc_html__('Unhide', 'zero-sense') : esc_html__('Hide', 'zero-sense'); ?></span>
                                    </button>
                                    <?php if ($isHidden && $usageCount === 0) : ?>
                                        <button type="button" class="button zs-ops-delete-field" title="<?php esc_attr_e('Delete field permanently', 'zero-sense'); ?>" style="margin-left:4px;">
                                            <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                        </button>
                                    <?php endif; ?>
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

                    function bindToggleVisibility(scope) {
                        var buttons = (scope || document).querySelectorAll('.zs-ops-toggle-visibility');
                        buttons.forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var tr = btn.closest('tr');
                                var statusField = tr.querySelector('.zs-ops-status-field');
                                var currentStatus = btn.getAttribute('data-current-status');
                                var newStatus = currentStatus === 'hidden' ? 'active' : 'hidden';
                                
                                statusField.value = newStatus;
                                btn.setAttribute('data-current-status', newStatus);
                                
                                var icon = btn.querySelector('.dashicons');
                                var text = btn.querySelector('.zs-ops-toggle-text');
                                var actionsCell = btn.closest('td');
                                var deleteBtn = actionsCell.querySelector('.zs-ops-delete-field');
                                var usageCount = parseInt(tr.querySelector('.zs-ops-usage-badge').textContent.match(/\d+/)[0]) || 0;
                                
                                if (newStatus === 'hidden') {
                                    icon.classList.remove('dashicons-hidden');
                                    icon.classList.add('dashicons-visibility');
                                    text.textContent = <?php echo json_encode(__('Unhide', 'zero-sense')); ?>;
                                    btn.setAttribute('title', <?php echo json_encode(__('Unhide field', 'zero-sense')); ?>);
                                    tr.classList.add('zs-ops-row-hidden');
                                    
                                    if (usageCount === 0 && !deleteBtn) {
                                        var newDeleteBtn = document.createElement('button');
                                        newDeleteBtn.type = 'button';
                                        newDeleteBtn.className = 'button zs-ops-delete-field';
                                        newDeleteBtn.title = <?php echo json_encode(__('Delete field permanently', 'zero-sense')); ?>;
                                        newDeleteBtn.style.marginLeft = '4px';
                                        newDeleteBtn.innerHTML = '<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>';
                                        actionsCell.appendChild(newDeleteBtn);
                                        bindDeleteButtons(tr);
                                    }
                                } else {
                                    icon.classList.remove('dashicons-visibility');
                                    icon.classList.add('dashicons-hidden');
                                    text.textContent = <?php echo json_encode(__('Hide', 'zero-sense')); ?>;
                                    btn.setAttribute('title', <?php echo json_encode(__('Hide field', 'zero-sense')); ?>);
                                    tr.classList.remove('zs-ops-row-hidden');
                                    
                                    if (deleteBtn) {
                                        deleteBtn.remove();
                                    }
                                }
                            });
                        });
                    }

                    function bindDeleteButtons(scope) {
                        var buttons = (scope || document).querySelectorAll('.zs-ops-delete-field');
                        buttons.forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                if (confirm(<?php echo json_encode(__('Are you sure you want to permanently delete this field? This action cannot be undone.', 'zero-sense')); ?>)) {
                                    var tr = btn.closest('tr');
                                    if (tr) tr.remove();
                                }
                            });
                        });
                    }

                    function bindLabelChange(scope) {
                        var labelFields = (scope || document).querySelectorAll('.zs-ops-label-field');
                        labelFields.forEach(function(labelField) {
                            var tr = labelField.closest('tr');
                            var keyField = tr.querySelector('.zs-ops-key-field');
                            
                            if (!keyField.value) {
                                labelField.addEventListener('input', function() {
                                    var label = labelField.value.trim();
                                    if (label && !keyField.value) {
                                        var baseKey = label.toLowerCase()
                                            .replace(/[^a-z0-9\s-]/g, '')
                                            .replace(/\s+/g, '_')
                                            .replace(/-+/g, '_')
                                            .replace(/^_+|_+$/g, '')
                                            .substring(0, 40);
                                        var keyDisplay = tr.querySelector('.zs-ops-key-display');
                                        keyDisplay.textContent = baseKey + '_[new]';
                                    }
                                });
                            }
                        });
                    }

                    bindToggleVisibility();
                    bindDeleteButtons();
                    bindLabelChange();
                    updateSortable();

                    addBtn.addEventListener('click', function() {
                        var tr = document.createElement('tr');
                        tr.className = 'zs-ops-sortable-row';
                        tr.innerHTML = '' +
                            '<td><span class="zs-ops-drag-handle dashicons dashicons-menu"></span></td>' +
                            '<td>' +
                                '<span class="zs-ops-key-display"></span>' +
                                '<input type="hidden" name="zs_ops_material_schema[key][]" class="zs-ops-key-field" value="">' +
                                '<input type="hidden" name="zs_ops_material_schema[status][]" class="zs-ops-status-field" value="active">' +
                                '<input type="hidden" name="zs_ops_material_schema[created_at][]" value="0">' +
                            '</td>' +
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
                            '<td class="zs-ops-usage-count"><span class="zs-ops-usage-badge"><?php echo esc_js(sprintf(_n('%d order', '%d orders', 0, 'zero-sense'), 0)); ?></span></td>' +
                            '<td>' +
                                '<button type="button" class="button zs-ops-toggle-visibility" data-current-status="active" title="<?php echo esc_js(__('Hide field', 'zero-sense')); ?>">' +
                                    '<span class="dashicons dashicons-hidden" style="vertical-align: middle;"></span>' +
                                    '<span class="zs-ops-toggle-text"><?php echo esc_js(__('Hide', 'zero-sense')); ?></span>' +
                                '</button>' +
                            '</td>';

                        tbody.appendChild(tr);
                        bindToggleVisibility(tr);
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

        $existingSchema = get_option(self::OPTION_SCHEMA, []);
        if (!is_array($existingSchema)) {
            $existingSchema = [];
        }
        
        $existingByKey = [];
        foreach ($existingSchema as $row) {
            if (is_array($row) && isset($row['key'])) {
                $existingByKey[$row['key']] = $row;
            }
        }

        $keys = isset($raw['key']) && is_array($raw['key']) ? $raw['key'] : [];
        $labels = isset($raw['label']) && is_array($raw['label']) ? $raw['label'] : [];
        $types = isset($raw['type']) && is_array($raw['type']) ? $raw['type'] : [];
        $statuses = isset($raw['status']) && is_array($raw['status']) ? $raw['status'] : [];
        $createdAts = isset($raw['created_at']) && is_array($raw['created_at']) ? $raw['created_at'] : [];

        $allowedTypeKeys = array_keys($this->getAllowedTypes());

        $schema = [];
        $seenKeys = [];

        $count = max(count($keys), count($labels), count($types));
        for ($i = 0; $i < $count; $i++) {
            $label = isset($labels[$i]) ? sanitize_text_field((string) $labels[$i]) : '';
            $type = isset($types[$i]) ? sanitize_key((string) $types[$i]) : 'text';
            $incomingKey = isset($keys[$i]) ? sanitize_key((string) $keys[$i]) : '';
            $status = isset($statuses[$i]) ? sanitize_text_field((string) $statuses[$i]) : 'active';
            $createdAt = isset($createdAts[$i]) ? absint($createdAts[$i]) : 0;

            if ($label === '') {
                continue;
            }

            if (!in_array($type, $allowedTypeKeys, true)) {
                $type = 'text';
            }
            
            if (!in_array($status, ['active', 'hidden'], true)) {
                $status = 'active';
            }

            if ($incomingKey !== '' && isset($existingByKey[$incomingKey])) {
                $key = $incomingKey;
                $createdAt = isset($existingByKey[$incomingKey]['created_at']) ? (int) $existingByKey[$incomingKey]['created_at'] : time();
            } else {
                $baseKey = strtolower($label);
                $baseKey = preg_replace('/[^a-z0-9\s-]/', '', $baseKey);
                $baseKey = preg_replace('/\s+/', '_', $baseKey);
                $baseKey = preg_replace('/-+/', '_', $baseKey);
                $baseKey = preg_replace('/^_+|_+$/', '', $baseKey);
                $baseKey = substr($baseKey, 0, 40);
                $baseKey = sanitize_key($baseKey);
                
                $timestamp = time();
                $key = $baseKey . '_' . $timestamp;
                $createdAt = $timestamp;
                
                $counter = 1;
                while (isset($seenKeys[$key])) {
                    $key = $baseKey . '_' . $timestamp . '_' . $counter;
                    $counter++;
                }
            }

            $seenKeys[$key] = true;
            $schema[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'status' => $status,
                'created_at' => $createdAt,
            ];
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

    private function getFieldUsageCount(string $key): int
    {
        global $wpdb;
        
        // The meta_value is serialized PHP array, need to search for the key in serialized format
        // Format in serialized array: s:9:"field_key";a:1:{s:5:"value";...}
        $search_pattern = '%' . $wpdb->esc_like('s:' . strlen($key) . ':"' . $key . '"') . '%';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} 
            WHERE meta_key = %s
            AND meta_value LIKE %s",
            'zs_ops_material',
            $search_pattern
        ));
        
        if ($key === 'otra_prueba_1771244405') {
            error_log('[ZS Schema DEBUG] Final count: ' . $count);
        }
        
        return (int) $count;
    }

    private function migrateSchema(array $schema): array
    {
        $migrated = [];
        
        foreach ($schema as $row) {
            if (!is_array($row)) {
                continue;
            }
            
            $key = isset($row['key']) ? (string) $row['key'] : '';
            $label = isset($row['label']) ? (string) $row['label'] : '';
            $type = isset($row['type']) ? (string) $row['type'] : 'text';
            $status = isset($row['status']) ? (string) $row['status'] : 'active';
            $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : time();
            
            if ($key === '' || $label === '') {
                continue;
            }
            
            if (!in_array($status, ['active', 'hidden'], true)) {
                $status = 'active';
            }
            
            $migrated[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'status' => $status,
                'created_at' => $createdAt,
            ];
        }
        
        return $migrated;
    }
}
