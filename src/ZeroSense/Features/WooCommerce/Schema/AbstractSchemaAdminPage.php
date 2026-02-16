<?php
namespace ZeroSense\Features\WooCommerce\Schema;

use ZeroSense\Core\FeatureInterface;

/**
 * Abstract Schema Admin Page
 * 
 * Base class for all schema admin pages.
 * Provides complete CRUD functionality for schema fields with:
 * - Drag & drop reordering
 * - Hide/unhide fields
 * - Delete unused hidden fields
 * - Usage tracking
 * - WPML integration
 */
abstract class AbstractSchemaAdminPage implements FeatureInterface
{
    /**
     * Get the schema key (e.g., 'material', 'workspace')
     */
    abstract public function getSchemaKey(): string;
    
    /**
     * Get the schema title (e.g., 'Material & Logistics')
     */
    abstract public function getSchemaTitle(): string;
    
    /**
     * Get the schema description
     */
    abstract public function getSchemaDescription(): string;
    
    /**
     * Get the WordPress option name for storing schema
     */
    abstract public function getOptionName(): string;
    
    /**
     * Get the order meta key for storing field data
     */
    abstract public function getMetaKey(): string;
    
    /**
     * Get the admin menu slug
     */
    abstract public function getMenuSlug(): string;
    
    /**
     * Get the admin menu title (short version)
     */
    abstract public function getMenuTitle(): string;
    
    /**
     * Get the form action name for saving
     */
    protected function getFormAction(): string
    {
        return 'zs_schema_save_' . $this->getSchemaKey();
    }
    
    /**
     * Get the CSS handle for admin styles
     */
    protected function getCssHandle(): string
    {
        return 'zero-sense-admin-schema-' . $this->getSchemaKey();
    }
    
    /**
     * Get the screen ID for this admin page
     */
    protected function getScreenId(): string
    {
        return 'woocommerce_page_' . $this->getMenuSlug();
    }
    
    /**
     * Get the form field name prefix
     */
    protected function getFormFieldName(): string
    {
        return 'zs_schema_' . $this->getSchemaKey();
    }
    
    /**
     * Get the WPML string context for field labels
     */
    protected function getWpmlContext(): string
    {
        return 'ops_' . $this->getSchemaKey() . '_label_';
    }

    // FeatureInterface implementation
    
    public function getName(): string
    {
        return $this->getSchemaTitle();
    }

    public function getDescription(): string
    {
        return $this->getSchemaDescription();
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
        // Register with SchemaRegistry
        SchemaRegistry::getInstance()->register($this);
        
        // WordPress hooks
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_' . $this->getFormAction(), [$this, 'handleSave']);
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

    // Admin functionality
    
    public function enqueueStyles(): void
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === $this->getScreenId()) {
            $css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-schema.css';
            $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : ZERO_SENSE_VERSION;
            
            wp_enqueue_style(
                $this->getCssHandle(),
                plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-schema.css',
                [],
                $css_ver
            );
        }
    }

    public function addAdminMenu(): void
    {
        global $submenu;

        // Check if menu item already exists
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $menuItem) {
                if (isset($menuItem[2]) && $menuItem[2] === $this->getMenuSlug()) {
                    return;
                }
            }
        }

        add_submenu_page(
            'woocommerce',
            $this->getSchemaTitle(),
            $this->getMenuTitle(),
            'manage_options',
            $this->getMenuSlug(),
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        $schema = get_option($this->getOptionName(), []);
        if (!is_array($schema)) {
            $schema = [];
        }
        
        $schema = $this->migrateSchema($schema);
        $allowedTypes = $this->getAllowedTypes();
        $updated = isset($_GET['updated']) && sanitize_text_field(wp_unslash((string) $_GET['updated'])) === '1';
        
        $formFieldName = $this->getFormFieldName();
        ?>
        <div class="wrap" data-schema-admin="<?php echo esc_attr($this->getSchemaKey()); ?>">
            <h1><?php echo esc_html($this->getSchemaTitle()); ?></h1>
            <p><?php echo esc_html($this->getSchemaDescription()); ?></p>

            <?php if ($updated): ?>
                <div class="notice notice-success"><p><?php esc_html_e('Schema saved.', 'zero-sense'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field($this->getFormAction()); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($this->getFormAction()); ?>">

                <h3 style="margin-top: 20px; margin-bottom: 4px;"><?php esc_html_e('Visible Fields', 'zero-sense'); ?></h3>
                <p style="margin-top: 0; margin-bottom: 12px; color: #666;"><?php esc_html_e('These fields are visible in all orders. Drag to reorder them or click "Hide" to move them to the hidden section.', 'zero-sense'); ?></p>

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
                        <?php 
                        foreach ($schema as $row):
                            $status = isset($row['status']) ? (string) $row['status'] : 'active';
                            if ($status === 'hidden') continue;
                            
                            $this->renderFieldRow($row, $formFieldName, $allowedTypes);
                        endforeach;
                        ?>
                    </tbody>
                </table>

                <div class="tablenav top" style="max-width:1100px;">
                    <div class="alignleft actions">
                        <button type="button" class="button" id="zs-ops-schema-add">
                            <?php esc_html_e('Add field', 'zero-sense'); ?>
                        </button>
                    </div>
                    <div class="alignright">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save', 'zero-sense'); ?>
                        </button>
                    </div>
                    <br class="clear">
                </div>
                
                <?php
                $hiddenFields = array_filter($schema, function($row) {
                    return isset($row['status']) && $row['status'] === 'hidden';
                });
                
                if (!empty($hiddenFields)) : ?>
                    <h3 style="margin-top: 30px; margin-bottom: 4px;"><?php esc_html_e('Hidden Fields', 'zero-sense'); ?> <span style="color: #666; font-size: 13px; font-weight: normal;">(<?php echo count($hiddenFields); ?>)</span></h3>
                    <p style="margin-top: 0; margin-bottom: 12px; color: #666;"><?php esc_html_e('These fields are hidden from new orders but remain visible in orders that already have data. Click "Unhide" to make them visible again, or "Delete" to permanently remove fields with no data.', 'zero-sense'); ?></p>
                    <table class="widefat" style="max-width:1100px; margin-top: 12px;">
                        <thead>
                            <tr>
                                <th style="width: 180px;"><?php esc_html_e('Key', 'zero-sense'); ?></th>
                                <th><?php esc_html_e('Label', 'zero-sense'); ?></th>
                                <th style="width: 150px;"><?php esc_html_e('Type', 'zero-sense'); ?></th>
                                <th style="width: 100px;"><?php esc_html_e('Usage', 'zero-sense'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Actions', 'zero-sense'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="zs-ops-schema-hidden-rows">
                            <?php foreach ($hiddenFields as $row):
                                $this->renderHiddenFieldRow($row, $formFieldName, $allowedTypes);
                            endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </form>

            <?php $this->renderJavaScript($formFieldName, $allowedTypes); ?>
        </div>
        <?php
    }

    protected function renderFieldRow(array $row, string $formFieldName, array $allowedTypes): void
    {
        $key = isset($row['key']) ? (string) $row['key'] : '';
        $label = isset($row['label']) ? (string) $row['label'] : '';
        $type = isset($row['type']) ? (string) $row['type'] : 'text';
        $status = isset($row['status']) ? (string) $row['status'] : 'active';
        $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : time();
        $usageCount = $this->getFieldUsageCount($key);
        ?>
        <tr class="zs-ops-sortable-row" data-field-key="<?php echo esc_attr($key); ?>">
            <td>
                <span class="zs-ops-drag-handle dashicons dashicons-menu"></span>
            </td>
            <td>
                <span class="zs-ops-key-display"><?php echo esc_html($key); ?></span>
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[key][]" value="<?php echo esc_attr($key); ?>" class="zs-ops-key-field">
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[status][]" value="<?php echo esc_attr($status); ?>" class="zs-ops-status-field">
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[created_at][]" value="<?php echo esc_attr($createdAt); ?>">
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($formFieldName); ?>[label][]" value="<?php echo esc_attr($label); ?>" class="regular-text zs-ops-label-field" style="width:100%;" placeholder="e.g. Work tables">
            </td>
            <td>
                <select name="<?php echo esc_attr($formFieldName); ?>[type][]" style="width:100%;">
                    <?php foreach ($allowedTypes as $typeValue => $typeLabel): ?>
                        <option value="<?php echo esc_attr($typeValue); ?>" <?php selected($type, $typeValue); ?>><?php echo esc_html($typeLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="zs-ops-usage-count">
                <span class="zs-ops-usage-badge"><?php echo esc_html(sprintf(_n('%d order', '%d orders', $usageCount, 'zero-sense'), $usageCount)); ?></span>
            </td>
            <td>
                <button type="button" class="button zs-ops-toggle-visibility" data-current-status="<?php echo esc_attr($status); ?>" title="<?php esc_attr_e('Hide field', 'zero-sense'); ?>">
                    <span class="dashicons dashicons-hidden"></span>
                    <span class="zs-ops-toggle-text"><?php esc_html_e('Hide', 'zero-sense'); ?></span>
                </button>
            </td>
        </tr>
        <?php
    }

    protected function renderHiddenFieldRow(array $row, string $formFieldName, array $allowedTypes): void
    {
        $key = isset($row['key']) ? (string) $row['key'] : '';
        $label = isset($row['label']) ? (string) $row['label'] : '';
        $type = isset($row['type']) ? (string) $row['type'] : 'text';
        $status = 'hidden';
        $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : time();
        $usageCount = $this->getFieldUsageCount($key);
        ?>
        <tr class="zs-ops-row-hidden" data-field-key="<?php echo esc_attr($key); ?>">
            <td>
                <span class="zs-ops-key-display"><?php echo esc_html($key); ?></span>
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[key][]" value="<?php echo esc_attr($key); ?>" class="zs-ops-key-field">
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[status][]" value="<?php echo esc_attr($status); ?>" class="zs-ops-status-field">
                <input type="hidden" name="<?php echo esc_attr($formFieldName); ?>[created_at][]" value="<?php echo esc_attr($createdAt); ?>">
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr($formFieldName); ?>[label][]" value="<?php echo esc_attr($label); ?>" class="regular-text zs-ops-label-field" style="width:100%;" placeholder="e.g. Work tables">
            </td>
            <td>
                <select name="<?php echo esc_attr($formFieldName); ?>[type][]" style="width:100%;">
                    <?php foreach ($allowedTypes as $typeValue => $typeLabel): ?>
                        <option value="<?php echo esc_attr($typeValue); ?>" <?php selected($type, $typeValue); ?>><?php echo esc_html($typeLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="zs-ops-usage-count">
                <span class="zs-ops-usage-badge"><?php echo esc_html(sprintf(_n('%d order', '%d orders', $usageCount, 'zero-sense'), $usageCount)); ?></span>
            </td>
            <td>
                <button type="button" class="button zs-ops-toggle-visibility" data-current-status="<?php echo esc_attr($status); ?>" title="<?php esc_attr_e('Unhide field', 'zero-sense'); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                    <span class="zs-ops-toggle-text"><?php esc_html_e('Unhide', 'zero-sense'); ?></span>
                </button>
                <?php if ($usageCount === 0) : ?>
                    <button type="button" class="button zs-ops-delete-field" title="<?php esc_attr_e('Delete field permanently', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    protected function renderJavaScript(string $formFieldName, array $allowedTypes): void
    {
        ?>
        <script>
            (function() {
                var tbody = document.getElementById('zs-ops-schema-rows');
                var addBtn = document.getElementById('zs-ops-schema-add');
                if (!tbody || !addBtn) return;

                function bindToggleVisibility(scope) {
                    var buttons = (scope || document).querySelectorAll('.zs-ops-toggle-visibility');
                    buttons.forEach(function(btn) {
                        if (btn.dataset.bound) return;
                        btn.dataset.bound = '1';
                        
                        btn.addEventListener('click', function() {
                            var tr = btn.closest('tr');
                            var statusField = tr.querySelector('.zs-ops-status-field');
                            var currentStatus = btn.getAttribute('data-current-status');
                            var newStatus = currentStatus === 'hidden' ? 'active' : 'hidden';
                            
                            statusField.value = newStatus;
                            btn.setAttribute('data-current-status', newStatus);
                            
                            var icon = btn.querySelector('.dashicons');
                            var text = btn.querySelector('.zs-ops-toggle-text');
                            
                            if (newStatus === 'hidden') {
                                icon.classList.remove('dashicons-hidden');
                                icon.classList.add('dashicons-visibility');
                                text.textContent = <?php echo json_encode(__('Unhide', 'zero-sense')); ?>;
                                btn.setAttribute('title', <?php echo json_encode(__('Unhide field', 'zero-sense')); ?>);
                                tr.classList.add('zs-ops-row-hidden');
                                tr.style.opacity = '0.6';
                            } else {
                                icon.classList.remove('dashicons-visibility');
                                icon.classList.add('dashicons-hidden');
                                text.textContent = <?php echo json_encode(__('Hide', 'zero-sense')); ?>;
                                btn.setAttribute('title', <?php echo json_encode(__('Hide field', 'zero-sense')); ?>);
                                tr.classList.remove('zs-ops-row-hidden');
                                tr.style.opacity = '1';
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
                            '<input type="hidden" name="<?php echo esc_js($formFieldName); ?>[key][]" class="zs-ops-key-field" value="">' +
                            '<input type="hidden" name="<?php echo esc_js($formFieldName); ?>[status][]" class="zs-ops-status-field" value="active">' +
                            '<input type="hidden" name="<?php echo esc_js($formFieldName); ?>[created_at][]" value="0">' +
                        '</td>' +
                        '<td><input type="text" name="<?php echo esc_js($formFieldName); ?>[label][]" class="regular-text zs-ops-label-field" style="width:100%;" placeholder="e.g. New field"></td>' +
                        '<td>' +
                            '<select name="<?php echo esc_js($formFieldName); ?>[type][]" style="width:100%;">' +
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
                                '<span class="dashicons dashicons-hidden"></span>' +
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

                if (typeof Sortable === 'undefined') {
                    var script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
                    script.onload = updateSortable;
                    document.head.appendChild(script);
                }
            })();
        </script>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        check_admin_referer($this->getFormAction());

        $formFieldName = $this->getFormFieldName();
        $raw = $_POST[$formFieldName] ?? null;
        if (!is_array($raw)) {
            wp_safe_redirect(admin_url('admin.php?page=' . $this->getMenuSlug()));
            exit;
        }

        $existingSchema = get_option($this->getOptionName(), []);
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

        update_option($this->getOptionName(), $schema, false);

        // Register strings with WPML
        foreach ($schema as $row) {
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }
            do_action('wpml_register_single_string', 'zero-sense', $this->getWpmlContext() . $key, $label);
        }

        $redirect = add_query_arg(
            [
                'page' => $this->getMenuSlug(),
                'updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    protected function getAllowedTypes(): array
    {
        return [
            'text' => __('Text', 'zero-sense'),
            'textarea' => __('Textarea', 'zero-sense'),
            'qty_int' => __('Quantity', 'zero-sense'),
            'bool' => __('Checkbox', 'zero-sense'),
        ];
    }

    protected function getFieldUsageCount(string $key): int
    {
        global $wpdb;
        
        $metaKey = $this->getMetaKey();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = %s",
            $metaKey
        ));
        
        $count = 0;
        foreach ($results as $result) {
            clean_post_cache($result->post_id);
            
            $order = wc_get_order($result->post_id);
            if (!$order) {
                continue;
            }
            
            $data = $order->get_meta($metaKey, true);
            if (!is_array($data) || !isset($data[$key])) {
                continue;
            }
            
            $fieldData = $data[$key];
            $value = is_array($fieldData) ? ($fieldData['value'] ?? '') : $fieldData;
            
            if ($value !== '' && $value !== '0' && $value !== 0 && $value !== null) {
                $count++;
            }
        }
        
        return $count;
    }

    protected function migrateSchema(array $schema): array
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
    
    /**
     * Get schema data
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getSchema(): array
    {
        $schema = get_option($this->getOptionName(), []);
        if (!is_array($schema)) {
            return [];
        }
        
        return $this->migrateSchema($schema);
    }
    
    /**
     * Get active fields only
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getActiveFields(): array
    {
        return array_filter($this->getSchema(), function($row) {
            return !isset($row['status']) || $row['status'] === 'active';
        });
    }
    
    /**
     * Get all fields (including hidden ones with data)
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllFields(): array
    {
        return $this->getSchema();
    }
}
