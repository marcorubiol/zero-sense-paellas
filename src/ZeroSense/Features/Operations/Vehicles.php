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

    private static int $usageYear = 0;
    private static array $monthlyUsage = [];

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
        add_action('restrict_manage_posts', [$this, 'renderFilters']);
        add_filter('disable_months_dropdown', [$this, 'disableMonthsDropdown'], 10, 2);
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
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ($key === 'title') {
                $result['usage'] = __('Usage', 'zero-sense');
            }
        }
        return $result;
    }

    public function renderUsageColumn(string $column, int $postId): void
    {
        if ($column !== 'usage') {
            return;
        }

        $year = self::getSelectedYear();
        $selectedMonth = self::getSelectedMonth();
        $usage = self::getMonthlyUsage($year);
        $vehicleData = $usage[$postId] ?? [];
        $monthsFull = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $monthsShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $total = 0;

        echo '<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:2px;font-size:11px;line-height:1;max-width:210px;">';
        for ($m = 1; $m <= 12; $m++) {
            $count = (int) ($vehicleData[$m] ?? 0);
            $total += $count;
            $isSelected = ($selectedMonth === $m);
            if ($isSelected) {
                $bg = $count > 0 ? '#0073aa' : '#666';
                $color = '#fff';
                $labelColor = 'rgba(255,255,255,.7)';
            } else {
                $bg = $count > 0 ? '#e7f3ff' : '#f0f0f0';
                $color = $count > 0 ? '#0073aa' : '#999';
                $labelColor = '#888';
            }
            printf(
                '<div style="text-align:center;padding:3px 2px;background:%s;border-radius:2px;">'
                . '<div style="color:%s;font-size:8px;">%s</div>'
                . '<div style="font-weight:600;color:%s;">%d</div>'
                . '</div>',
                esc_attr($bg),
                esc_attr($labelColor),
                esc_html($monthsShort[$m - 1]),
                esc_attr($color),
                $count
            );
        }
        echo '</div>';

        if ($total > 0) {
            printf(
                '<div style="font-size:11px;color:#0073aa;font-weight:600;margin-top:4px;">Total: %d</div>',
                $total
            );
        }
    }

    public function disableMonthsDropdown(bool $disabled, string $postType): bool
    {
        if ($postType === self::CPT) {
            return true;
        }
        return $disabled;
    }

    public function renderFilters(string $postType): void
    {
        if ($postType !== self::CPT) {
            return;
        }

        $current = (int) current_time('Y');
        $selectedYear = self::getSelectedYear();
        $selectedMonth = self::getSelectedMonth();
        $monthsFull = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        echo '<select name="zs_usage_year">';
        for ($y = $current - 2; $y <= $current; $y++) {
            printf(
                '<option value="%d"%s>%d</option>',
                $y,
                selected($y, $selectedYear, false),
                $y
            );
        }
        echo '</select>';

        echo '<select name="zs_usage_month">';
        printf('<option value="0"%s>%s</option>', selected(0, $selectedMonth, false), esc_html__('All months', 'zero-sense'));
        for ($m = 1; $m <= 12; $m++) {
            printf(
                '<option value="%d"%s>%s</option>',
                $m,
                selected($m, $selectedMonth, false),
                esc_html($monthsFull[$m - 1])
            );
        }
        echo '</select>';
    }

    private static function getSelectedYear(): int
    {
        $current = (int) current_time('Y');
        $year = isset($_GET['zs_usage_year']) ? (int) $_GET['zs_usage_year'] : $current;
        return max($current - 2, min($current, $year));
    }

    private static function getSelectedMonth(): int
    {
        $month = isset($_GET['zs_usage_month']) ? (int) $_GET['zs_usage_month'] : 0;
        return ($month >= 0 && $month <= 12) ? $month : 0;
    }

    private static function getMonthlyUsage(int $year): array
    {
        if (self::$usageYear === $year && !empty(self::$monthlyUsage)) {
            return self::$monthlyUsage;
        }

        self::$usageYear = $year;
        self::$monthlyUsage = [];

        $statuses = array_diff(array_keys(wc_get_order_statuses()), ['wc-cancelled']);
        $orders = wc_get_orders([
            'limit'      => -1,
            'return'     => 'objects',
            'status'     => array_values($statuses),
            'meta_query' => [[
                'key'     => 'zs_event_date',
                'value'   => [$year . '-01-01', $year . '-12-31'],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]],
        ]);

        foreach ($orders as $order) {
            $eventDate = $order->get_meta('zs_event_date', true);
            if (!$eventDate) {
                continue;
            }
            $month = (int) substr($eventDate, 5, 2);
            if ($month < 1 || $month > 12) {
                continue;
            }
            $vehicles = $order->get_meta('zs_event_vehicles', true);
            if (!is_array($vehicles)) {
                continue;
            }
            foreach ($vehicles as $vid) {
                $vid = (int) $vid;
                self::$monthlyUsage[$vid][$month] = (self::$monthlyUsage[$vid][$month] ?? 0) + 1;
            }
        }

        return self::$monthlyUsage;
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
