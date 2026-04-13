<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use WP_Post;

class VehicleAssignmentMetabox
{
    private const META_KEY    = 'zs_event_vehicles';
    private const NONCE_FIELD  = 'zs_vehicle_assignment_nonce';
    private const NONCE_ACTION = 'zs_vehicle_assignment_save';
    private const VEHICLE_CPT  = 'zs_vehicle';
    private const META_PLATE   = 'zs_vehicle_plate';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_zs_save_vehicle_assignment', [$this, 'ajaxSaveVehicleAssignment']);
        add_action('wp_ajax_zs_create_vehicle', [$this, 'ajaxCreateVehicle']);
    }

    public function addMetabox(): void
    {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'zs_event_vehicle_assignment',
            __('Event Vehicles', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'normal',
            'default'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_order_screen = ($screen->post_type === 'shop_order') ||
                           ($screen->id === 'woocommerce_page_wc-orders') ||
                           ($screen->id === wc_get_page_screen_id('shop-order'));

        if (!$is_order_screen) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('selectWoo');
    }

    public function render($post_or_order): void
    {
        if ($post_or_order instanceof WP_Post) {
            $order = wc_get_order($post_or_order->ID);
        } elseif ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } else {
            return;
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $assignedIds = $order->get_meta(self::META_KEY, true);
        if (!is_array($assignedIds)) {
            $assignedIds = [];
        }

        $allVehicles = $this->getAllVehicles();

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <script>
        var zsAllVehicles = <?php echo wp_json_encode($allVehicles); ?>;
        </script>
        <div class="zs-conflict-banner zs-conflict-vehicles" style="display:none; background:#fcf0f0; border-left:4px solid #d63638; padding:8px 12px; margin-bottom:12px;"></div>
        <div class="zs-mb-wrapper">
            <div class="zs-mb-divider">
                <div class="zs-vehicle-section">
                    <?php foreach ($assignedIds as $vehicleId): ?>
                        <?php
                        $vehicleId  = (int) $vehicleId;
                        $vehiclePost = $vehicleId > 0 ? get_post($vehicleId) : null;
                        $name  = $vehiclePost ? $vehiclePost->post_title : '';
                        $plate = $vehicleId > 0 ? get_post_meta($vehicleId, self::META_PLATE, true) : '';
                        ?>
                        <div class="zs-assignment-row" data-vehicle-id="<?php echo esc_attr($vehicleId); ?>">
                            <input type="hidden" name="zs_event_vehicles[]" value="<?php echo esc_attr($vehicleId); ?>" class="zs-vehicle-hidden-input">

                            <div class="zs-assignment-display">
                                <strong><?php echo esc_html($name); ?></strong>
                                <?php if ($plate): ?>
                                    <span class="zs-assignment-info"><?php echo esc_html($plate); ?></span>
                                <?php endif; ?>
                            </div>

                            <select class="zs-vehicle-select zs-hidden">
                                <option value=""><?php esc_html_e('Select vehicle...', 'zero-sense'); ?></option>
                                <?php foreach ($allVehicles as $v): ?>
                                    <option value="<?php echo esc_attr($v['id']); ?>"
                                            <?php selected($vehicleId, $v['id']); ?>
                                            data-plate="<?php echo esc_attr($v['plate']); ?>">
                                        <?php echo esc_html($v['name']); ?>
                                        <?php if ($v['plate']): ?>(<?php echo esc_html($v['plate']); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="zs-btn is-neutral zs-vehicle-edit">
                                <?php esc_html_e('Change', 'zero-sense'); ?>
                            </button>
                            <button type="button" class="zs-btn is-destructive zs-vehicle-remove">
                                <?php esc_html_e('Remove', 'zero-sense'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>

                    <a href="#" class="zs-vehicle-add zs-mb-link">
                        + <?php esc_html_e('Add vehicle', 'zero-sense'); ?>
                    </a>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            'use strict';

            $(document).ready(function() {

                var zsConflictNonce = '<?php echo wp_create_nonce('zs_check_conflicts'); ?>';

                function getOrderId() {
                    return $('#post_ID').val() || $('input[name="post_ID"]').val() || $('input[name="order_id"]').val();
                }

                function getEventDate() {
                    return $('input[name="event_date"]').val() || '';
                }

                function showVehicleConflicts(conflicts) {
                    var $banner = $('.zs-conflict-vehicles');
                    if (!conflicts || conflicts.length === 0) {
                        $banner.hide().empty();
                        return;
                    }
                    var html = '<strong><?php echo esc_js(__('Vehicle conflict!', 'zero-sense')); ?></strong><ul style="margin:4px 0 0 18px;list-style:disc;">';
                    $.each(conflicts, function(i, c) {
                        html += '<li>' + c.resource_name + ' → <a href="' + c.order_edit_url + '" target="_blank">#' + c.order_id + '</a></li>';
                    });
                    html += '</ul>';
                    $banner.html(html).show();
                }

                function checkVehicleConflicts() {
                    var orderId = getOrderId();
                    var eventDate = getEventDate();
                    if (!orderId || !eventDate) { showVehicleConflicts([]); return; }

                    var ids = [];
                    $('.zs-vehicle-hidden-input').each(function() {
                        var v = $(this).val();
                        if (v) ids.push(v);
                    });
                    if (ids.length === 0) { showVehicleConflicts([]); return; }

                    $.post(ajaxurl, {
                        action: 'zs_check_resource_conflicts',
                        nonce: zsConflictNonce,
                        order_id: orderId,
                        event_date: eventDate,
                        vehicle_ids: ids
                    }, function(response) {
                        if (response.success) {
                            showVehicleConflicts(response.data.conflicts);
                        }
                    });
                }

                // Check conflicts on page load and store initial values
                checkVehicleConflicts();
                $('.zs-assignment-row[data-vehicle-id]').each(function() {
                    $(this).data('prev-vehicle-id', $(this).attr('data-vehicle-id'));
                });

                function getAssignedVehicleIds(excludeRow) {
                    var ids = [];
                    $('.zs-vehicle-hidden-input').each(function() {
                        if (excludeRow && $(this).closest('.zs-assignment-row')[0] === excludeRow[0]) return;
                        var v = $(this).val();
                        if (v) ids.push(String(v));
                    });
                    return ids;
                }

                function buildVehicleOptions($select, currentId) {
                    var assigned = getAssignedVehicleIds($select.closest('.zs-assignment-row')[0] ? $select.closest('.zs-assignment-row') : null);
                    $select.find('option:not([value=""])').remove();
                    if (typeof zsAllVehicles !== 'undefined') {
                        $.each(zsAllVehicles, function(i, v) {
                            var vid = String(v.id);
                            if (assigned.indexOf(vid) !== -1 && vid !== String(currentId)) return;
                            var label = v.name + (v.plate ? ' (' + v.plate + ')' : '');
                            $select.append($('<option></option>').val(v.id).text(label).data('plate', v.plate));
                        });
                    }
                }

                function saveAssignments($excludeRow) {
                    var ids = [];
                    $('.zs-vehicle-hidden-input').each(function() {
                        if ($excludeRow && $(this).closest('.zs-assignment-row')[0] === $excludeRow[0]) return;
                        var v = $(this).val();
                        if (v) ids.push(v);
                    });

                    var orderId = getOrderId();
                    if (!orderId) return $.Deferred().resolve().promise();

                    return $.post(ajaxurl, {
                        action: 'zs_save_vehicle_assignment',
                        nonce: '<?php echo wp_create_nonce('zs_save_vehicle'); ?>',
                        order_id: orderId,
                        vehicle_ids: ids
                    });
                }

                // Change button
                $(document).on('click', '.zs-vehicle-edit', function() {
                    var $btn     = $(this);
                    var $row     = $btn.closest('.zs-assignment-row');
                    var $display = $row.find('.zs-assignment-display');
                    var $select  = $row.find('.zs-vehicle-select');

                    if ($select.is(':visible')) {
                        var val = $select.val();
                        if (val) {
                            var $opt   = $select.find('option:selected');
                            var name   = $opt.text().replace(/\s*\(.*\)\s*$/, '').trim();
                            var plate  = $opt.data('plate') || '';

                            $row.find('.zs-vehicle-hidden-input').val(val);
                            $display.find('strong').text(name);
                            $display.find('.zs-assignment-info').text(plate);

                            if ($select.hasClass('select2-hidden-accessible')) $select.selectWoo('destroy');
                            $select.addClass('zs-hidden');
                            $display.removeClass('zs-hidden');

                            $btn.text('<?php echo esc_js(__('Saving...', 'zero-sense')); ?>').prop('disabled', true);
                            var prevVal = $row.data('prev-vehicle-id') || '';
                            saveAssignments(null).done(function(response) {
                                if (response && response.success) {
                                    $btn.text('<?php echo esc_js(__('Change', 'zero-sense')); ?>').prop('disabled', false);
                                    $row.data('prev-vehicle-id', val);
                                    checkVehicleConflicts();
                                } else {
                                    // Conflict — revert
                                    if (response && response.data && response.data.conflicts) {
                                        showVehicleConflicts(response.data.conflicts);
                                    }
                                    $row.find('.zs-vehicle-hidden-input').val(prevVal);
                                    if (prevVal) {
                                        // Restore previous display
                                        var prevPost = null;
                                        if (typeof zsAllVehicles !== 'undefined') {
                                            $.each(zsAllVehicles, function(i, v) {
                                                if (String(v.id) === String(prevVal)) { prevPost = v; return false; }
                                            });
                                        }
                                        if (prevPost) {
                                            $display.find('strong').text(prevPost.name);
                                            $display.find('.zs-assignment-info').text(prevPost.plate || '');
                                        }
                                    } else {
                                        $row.remove();
                                    }
                                    $btn.text('<?php echo esc_js(__('Change', 'zero-sense')); ?>').prop('disabled', false);
                                }
                            }).fail(function() {
                                $btn.text('<?php echo esc_js(__('Change', 'zero-sense')); ?>').prop('disabled', false);
                            });
                        } else {
                            if ($select.hasClass('select2-hidden-accessible')) $select.selectWoo('destroy');
                            $select.addClass('zs-hidden');
                            $display.removeClass('zs-hidden');
                            $btn.text('<?php echo esc_js(__('Change', 'zero-sense')); ?>');
                        }
                    } else {
                        var currentId = $row.find('.zs-vehicle-hidden-input').val();
                        buildVehicleOptions($select, currentId);
                        $display.addClass('zs-hidden');
                        $select.removeClass('zs-hidden');
                        if ($select.hasClass('select2-hidden-accessible')) $select.selectWoo('destroy');
                        $select.selectWoo({
                            width: '100%',
                            tags: true,
                            createTag: function(params) {
                                var term = $.trim(params.term);
                                if (term === '') return null;
                                return { id: 'new:' + term, text: term + ' (<?php echo esc_js(__('Create new', 'zero-sense')); ?>)', newTag: true };
                            }
                        }).selectWoo('open');
                        $btn.text('<?php echo esc_js(__('Save', 'zero-sense')); ?>');
                    }
                });

                // Handle new vehicle creation on select change
                $(document).on('change', '.zs-vehicle-select', function() {
                    var $select = $(this);
                    var val     = $select.val();
                    if (!val || val.indexOf('new:') !== 0) return;

                    var vehicleName = val.replace('new:', '');
                    $.post(ajaxurl, {
                        action: 'zs_create_vehicle',
                        nonce: '<?php echo wp_create_nonce('zs_create_vehicle'); ?>',
                        name: vehicleName
                    }, function(response) {
                        if (response.success) {
                            $select.find('option[value="' + val + '"]').remove();
                            var newOpt = new Option(vehicleName, response.data.id, true, true);
                            $select.append(newOpt).trigger('change');
                            $select.closest('.zs-assignment-row').find('.zs-vehicle-edit').click();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
                            $select.val('').trigger('change');
                        }
                    });
                });

                // Add vehicle
                $(document).on('click', '.zs-vehicle-add', function(e) {
                    e.preventDefault();
                    var $btn = $(this);

                    var $newRow = $('<div class="zs-assignment-row"></div>');
                    var $hidden  = $('<input type="hidden" class="zs-vehicle-hidden-input" name="zs_event_vehicles[]" value="">');
                    var $display = $('<div class="zs-assignment-display zs-hidden"><strong></strong><span class="zs-assignment-info"></span></div>');
                    var $select  = $('<select class="zs-vehicle-select"></select>');
                    $select.append('<option value=""><?php echo esc_js(__('Select vehicle...', 'zero-sense')); ?></option>');

                    buildVehicleOptions($select, null);

                    var $editBtn   = $('<button type="button" class="zs-btn is-neutral zs-vehicle-edit"><?php echo esc_js(__('Save', 'zero-sense')); ?></button>');
                    var $removeBtn = $('<button type="button" class="zs-btn is-destructive zs-vehicle-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button>');

                    $newRow.append($hidden).append($display).append($select).append($editBtn).append($removeBtn);
                    $btn.before($newRow);

                    $select.selectWoo({
                        width: '100%',
                        tags: true,
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') return null;
                            return { id: 'new:' + term, text: term + ' (<?php echo esc_js(__('Create new', 'zero-sense')); ?>)', newTag: true };
                        }
                    }).selectWoo('open');
                });

                // Remove vehicle
                $(document).on('click', '.zs-vehicle-remove', function() {
                    var $btn = $(this);
                    var $row = $btn.closest('.zs-assignment-row');

                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Removing...', 'zero-sense')); ?>');
                    $row.css({ opacity: '0.5', transition: 'opacity 0.3s ease' });

                    saveAssignments($row).done(function(response) {
                        if (response && response.success) {
                            $row.slideUp(300, function() { $row.remove(); checkVehicleConflicts(); });
                        } else {
                            $row.css('opacity', '1');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Remove', 'zero-sense')); ?>');
                        }
                    }).fail(function() {
                        $row.css('opacity', '1');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Remove', 'zero-sense')); ?>');
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function ajaxSaveVehicleAssignment(): void
    {
        check_ajax_referer('zs_save_vehicle', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Insufficient permissions');
        }

        $orderId    = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $vehicleIds = isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])
            ? array_map('absint', $_POST['vehicle_ids'])
            : [];

        if (!$orderId) {
            wp_send_json_error('Invalid order ID');
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        $vehicleIds = array_filter($vehicleIds);

        // Check for conflicts before saving
        if (!empty($vehicleIds)) {
            $eventDate = $order->get_meta('zs_event_date', true);
            if ($eventDate) {
                $conflicts = ResourceConflictValidator::findVehicleConflicts($orderId, $eventDate, $vehicleIds);
                if (!empty($conflicts)) {
                    wp_send_json_error([
                        'message' => 'Vehicle conflict detected',
                        'conflicts' => $conflicts,
                    ]);
                }
            }
        }

        if (empty($vehicleIds)) {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, array_values($vehicleIds));
        }

        $order->save();
        wp_send_json_success(['message' => 'Vehicle assignment saved']);
    }

    public function ajaxCreateVehicle(): void
    {
        check_ajax_referer('zs_create_vehicle', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (empty($name)) {
            wp_send_json_error('Name is required');
        }

        $postId = wp_insert_post([
            'post_title'  => $name,
            'post_type'   => self::VEHICLE_CPT,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId)) {
            wp_send_json_error($postId->get_error_message());
        }

        wp_send_json_success(['id' => $postId, 'name' => $name]);
    }

    public function save(int $orderId): void
    {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce((string) $_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('edit_shop_order', $orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $rawIds     = isset($_POST['zs_event_vehicles']) && is_array($_POST['zs_event_vehicles'])
            ? array_map('absint', $_POST['zs_event_vehicles'])
            : [];
        $vehicleIds = array_values(array_filter($rawIds));

        if (empty($vehicleIds)) {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, $vehicleIds);
        }

        $order->save();
    }

    private function getAllVehicles(): array
    {
        $posts = get_posts([
            'post_type'   => self::VEHICLE_CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);

        $vehicles = [];
        foreach ($posts as $post) {
            $vehicles[] = [
                'id'    => $post->ID,
                'name'  => $post->post_title,
                'plate' => get_post_meta($post->ID, self::META_PLATE, true) ?: '',
            ];
        }

        return $vehicles;
    }
}
