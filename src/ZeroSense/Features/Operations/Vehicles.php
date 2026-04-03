<?php
namespace ZeroSense\Features\Operations;

use ZeroSense\Core\FeatureInterface;
use WP_Post;

class Vehicles implements FeatureInterface
{
    private const CPT = 'zs_vehicle';

    private const META_PLATE = 'zs_vehicle_plate';
    private const META_NOTES = 'zs_vehicle_notes';

    private const NONCE_FIELD = 'zs_vehicle_nonce';
    private const NONCE_ACTION = 'zs_vehicle_save';

    private static bool $usageLoaded = false;
    private static array $usageCounts = [];

    public function getName(): string
    {
        return __('Vehicles', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Manage vehicles available for event assignments.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Operations';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return ['is_admin'];
    }

    public function init(): void
    {
        add_action('init', [$this, 'registerContentTypes']);
        add_action('add_meta_boxes', [$this, 'addVehicleMetabox']);
        add_action('save_post_' . self::CPT, [$this, 'saveVehicleMetabox'], 10, 2);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'addUsageColumn']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'renderUsageColumn'], 10, 2);
    }

    public function registerContentTypes(): void
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name'          => __('Vehicles', 'zero-sense'),
                'singular_name' => __('Vehicle', 'zero-sense'),
                'add_new'       => __('Add vehicle', 'zero-sense'),
                'add_new_item'  => __('Add new vehicle', 'zero-sense'),
                'edit_item'     => __('Edit vehicle', 'zero-sense'),
                'new_item'      => __('New vehicle', 'zero-sense'),
                'view_item'     => __('View vehicle', 'zero-sense'),
                'search_items'  => __('Search vehicles', 'zero-sense'),
                'not_found'     => __('No vehicles found', 'zero-sense'),
            ],
            'public'           => false,
            'show_ui'          => true,
            'show_in_menu'     => 'event-operations',
            'menu_position'    => 66,
            'supports'         => ['title'],
            'capability_type'  => 'post',
            'map_meta_cap'     => true,
        ]);
    }

    public function addVehicleMetabox(): void
    {
        add_meta_box(
            'zs_vehicle_details',
            __('Vehicle details', 'zero-sense'),
            [$this, 'renderVehicleMetabox'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function renderVehicleMetabox(WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $plate = get_post_meta($post->ID, self::META_PLATE, true);
        $notes = get_post_meta($post->ID, self::META_NOTES, true);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="zs-vehicle-metabox">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="zs_vehicle_plate"><?php esc_html_e('Plate', 'zero-sense'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="zs_vehicle_plate"
                               name="zs_vehicle_plate"
                               value="<?php echo esc_attr($plate); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Optional', 'zero-sense'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="zs_vehicle_notes"><?php esc_html_e('Notes', 'zero-sense'); ?></label>
                    </th>
                    <td>
                        <textarea id="zs_vehicle_notes"
                                  name="zs_vehicle_notes"
                                  rows="3"
                                  class="large-text"
                                  placeholder="<?php esc_attr_e('Optional notes about this vehicle', 'zero-sense'); ?>"><?php echo esc_textarea($notes); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function addUsageColumn(array $columns): array
    {
        $year = (int) current_time('Y');
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ($key === 'title') {
                $result['usage_count'] = sprintf(__('Uses %d', 'zero-sense'), $year);
            }
        }
        return $result;
    }

    public function renderUsageColumn(string $column, int $postId): void
    {
        if ($column !== 'usage_count') {
            return;
        }
        $counts = self::getUsageCounts();
        echo (int) ($counts[$postId] ?? 0);
    }

    private static function getUsageCounts(): array
    {
        if (self::$usageLoaded) {
            return self::$usageCounts;
        }
        self::$usageLoaded = true;

        $year = (int) current_time('Y');
        $orders = wc_get_orders([
            'limit'      => -1,
            'return'     => 'objects',
            'meta_query' => [[
                'key'     => 'zs_event_date',
                'value'   => [$year . '-01-01', $year . '-12-31'],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]],
        ]);

        foreach ($orders as $order) {
            $vehicles = $order->get_meta('zs_event_vehicles', true);
            if (!is_array($vehicles)) {
                continue;
            }
            foreach ($vehicles as $vid) {
                $vid = (int) $vid;
                self::$usageCounts[$vid] = (self::$usageCounts[$vid] ?? 0) + 1;
            }
        }

        return self::$usageCounts;
    }

    public function saveVehicleMetabox(int $postId, WP_Post $post): void
    {
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce((string) $_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        $plate = isset($_POST['zs_vehicle_plate']) ? sanitize_text_field((string) $_POST['zs_vehicle_plate']) : '';
        $notes = isset($_POST['zs_vehicle_notes']) ? sanitize_textarea_field((string) $_POST['zs_vehicle_notes']) : '';

        if ($plate !== '') {
            update_post_meta($postId, self::META_PLATE, $plate);
        } else {
            delete_post_meta($postId, self::META_PLATE);
        }

        if ($notes !== '') {
            update_post_meta($postId, self::META_NOTES, $notes);
        } else {
            delete_post_meta($postId, self::META_NOTES);
        }
    }
}
