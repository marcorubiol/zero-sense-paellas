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
    }

    public function getPriority(): int
    {
        return 25;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
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

        $schema = get_option(self::OPTION_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            $schema = $this->getDefaultSchema();
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
                            <th style="width: 260px;"><?php esc_html_e('Key', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Label (base language)', 'zero-sense'); ?></th>
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
                            <tr>
                                <td>
                                    <input type="text" name="zs_ops_material_schema[key][]" value="<?php echo esc_attr($key); ?>" class="regular-text" placeholder="e.g. work_tables">
                                </td>
                                <td>
                                    <input type="text" name="zs_ops_material_schema[label][]" value="<?php echo esc_attr($label); ?>" class="regular-text" style="width:100%;" placeholder="e.g. Work tables">
                                </td>
                                <td>
                                    <select name="zs_ops_material_schema[type][]" style="width:100%;">
                                        <?php foreach ($allowedTypes as $typeValue => $typeLabel): ?>
                                            <option value="<?php echo esc_attr($typeValue); ?>" <?php selected($type, $typeValue); ?>><?php echo esc_html($typeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button zs-ops-schema-remove"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="max-width:1100px; display:flex; gap:10px; align-items:center;">
                    <button type="button" class="button" id="zs-ops-schema-add"><?php esc_html_e('Add field', 'zero-sense'); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'zero-sense'); ?></button>
                </p>
            </form>

            <script>
                (function() {
                    var tbody = document.getElementById('zs-ops-schema-rows');
                    var addBtn = document.getElementById('zs-ops-schema-add');
                    if (!tbody || !addBtn) return;

                    function bindRemoveButtons(scope) {
                        var buttons = (scope || document).querySelectorAll('.zs-ops-schema-remove');
                        buttons.forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var tr = btn.closest('tr');
                                if (tr) tr.remove();
                            });
                        });
                    }

                    bindRemoveButtons();

                    addBtn.addEventListener('click', function() {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '' +
                            '<td><input type="text" name="zs_ops_material_schema[key][]" class="regular-text" placeholder="e.g. new_field"></td>' +
                            '<td><input type="text" name="zs_ops_material_schema[label][]" class="regular-text" style="width:100%;" placeholder="e.g. New field"></td>' +
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
                            '<td><button type="button" class="button zs-ops-schema-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button></td>';

                        tbody.appendChild(tr);
                        bindRemoveButtons(tr);
                    });
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

        $count = max(count($keys), count($labels), count($types));
        for ($i = 0; $i < $count; $i++) {
            $key = isset($keys[$i]) ? sanitize_key((string) $keys[$i]) : '';
            $label = isset($labels[$i]) ? sanitize_text_field((string) $labels[$i]) : '';
            $type = isset($types[$i]) ? sanitize_key((string) $types[$i]) : 'text';

            if ($key === '') {
                continue;
            }

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
            $schema = $this->getDefaultSchema();
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
            'qty_int' => __('Quantity (integer)', 'zero-sense'),
            'bool' => __('Yes/No', 'zero-sense'),
            'textarea' => __('Textarea', 'zero-sense'),
        ];
    }

    private function getDefaultSchema(): array
    {
        return [
            ['key' => 'vehicle', 'label' => 'Vehicle', 'type' => 'text'],
            ['key' => 'black_tablecloths', 'label' => 'Black tablecloths', 'type' => 'qty_int'],
            ['key' => 'cart', 'label' => 'Cart', 'type' => 'bool'],
            ['key' => 'work_tables', 'label' => 'Work tables', 'type' => 'qty_int'],
            ['key' => 'paella_pans', 'label' => 'Paella pans', 'type' => 'qty_int'],
            ['key' => 'burners', 'label' => 'Burners', 'type' => 'qty_int'],
            ['key' => 'tripods_legs', 'label' => 'Tripods / legs', 'type' => 'qty_int'],
            ['key' => 'butane', 'label' => 'Butane', 'type' => 'qty_int'],
            ['key' => 'hose', 'label' => 'Hose', 'type' => 'bool'],
            ['key' => 'parasols', 'label' => 'Parasols', 'type' => 'qty_int'],
            ['key' => 'tent', 'label' => 'Tent', 'type' => 'bool'],
            ['key' => 'lighting', 'label' => 'Lighting', 'type' => 'qty_int'],
            ['key' => 'water_fountain_8l', 'label' => 'Water fountain (8L)', 'type' => 'qty_int'],
            ['key' => 'trash_buckets', 'label' => 'Trash buckets', 'type' => 'qty_int'],
            ['key' => 'coolers', 'label' => 'Coolers', 'type' => 'qty_int'],
            ['key' => 'other', 'label' => 'Other', 'type' => 'textarea'],
        ];
    }
}
