<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

class EventDetailsMetabox
{
    private const LEGACY_META_KEYS = [
        MetaKeys::TOTAL_GUESTS => 'total_guests',
        MetaKeys::ADULTS => 'adults',
        MetaKeys::CHILDREN_5_TO_8 => 'children_5_to_8',
        MetaKeys::CHILDREN_0_TO_4 => 'children_0_to_4',
        MetaKeys::SERVICE_LOCATION => 'event_service_location',
        MetaKeys::ADDRESS => 'event_address',
        MetaKeys::CITY => 'event_city',
        MetaKeys::LOCATION_LINK => 'location_link',
        MetaKeys::EVENT_DATE => 'event_date',
        MetaKeys::SERVING_TIME => 'serving_time',
        MetaKeys::START_TIME => 'event_start_time',
        MetaKeys::EVENT_TYPE => 'event_type',
        MetaKeys::HOW_FOUND_US => 'how_found_us',
    ];

    private const LEGACY_EVENT_KEYS = [
        MetaKeys::TOTAL_GUESTS => '_event_total_guests',
        MetaKeys::ADULTS => '_event_adults',
        MetaKeys::CHILDREN_5_TO_8 => '_event_children_5_to_8',
        MetaKeys::CHILDREN_0_TO_4 => '_event_children_0_to_4',
        MetaKeys::SERVICE_LOCATION => '_event_service_location',
        MetaKeys::ADDRESS => '_event_address',
        MetaKeys::CITY => '_event_city',
        MetaKeys::LOCATION_LINK => '_event_location_link',
        MetaKeys::EVENT_DATE => '_event_date',
        MetaKeys::SERVING_TIME => '_event_serving_time',
        MetaKeys::START_TIME => '_event_start_time',
        MetaKeys::EVENT_TYPE => '_event_type',
        MetaKeys::HOW_FOUND_US => '_event_how_found_us',
    ];

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }
        
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20);
    }

    public function addMetabox(): void
    {
        $screen = wc_get_page_screen_id('shop-order');
        
        add_meta_box(
            'zs_event_details',
            __('Event Details', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'normal',
            'high'
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

        // Get values
        $totalGuests = $this->getOrderMetaWithFallback($order, MetaKeys::TOTAL_GUESTS);
        $adults = $this->getOrderMetaWithFallback($order, MetaKeys::ADULTS);
        $children5to8 = $this->getOrderMetaWithFallback($order, MetaKeys::CHILDREN_5_TO_8);
        $children0to4 = $this->getOrderMetaWithFallback($order, MetaKeys::CHILDREN_0_TO_4);
        $serviceLocation = $this->getOrderMetaWithFallback($order, MetaKeys::SERVICE_LOCATION);
        $serviceLocationCanonicalId = is_numeric($serviceLocation) ? absint($serviceLocation) : 0;
        $serviceLocationSelectedId = $serviceLocationCanonicalId;
        if ($serviceLocationCanonicalId > 0 && defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
            $currentLang = apply_filters('wpml_current_language', null);
            if (is_string($currentLang) && $currentLang !== '') {
                $translatedId = apply_filters('wpml_object_id', $serviceLocationCanonicalId, 'service-area', true, $currentLang);
                if ($translatedId) {
                    $serviceLocationSelectedId = (int) $translatedId;
                }
            }
        }

        $serviceAreaTerms = get_terms([
            'taxonomy' => 'service-area',
            'hide_empty' => false,
        ]);
        if (is_wp_error($serviceAreaTerms) || !is_array($serviceAreaTerms)) {
            $serviceAreaTerms = [];
        }
        $address = $this->getOrderMetaWithFallback($order, MetaKeys::ADDRESS);
        $city = $this->getOrderMetaWithFallback($order, MetaKeys::CITY);
        $locationLink = $this->getOrderMetaWithFallback($order, MetaKeys::LOCATION_LINK);
        $eventDateRaw = $this->getOrderMetaWithFallback($order, MetaKeys::EVENT_DATE);
        $teamArrivalTime = $this->getOrderMetaWithFallback($order, MetaKeys::TEAM_ARRIVAL_TIME);
        $eventDateForInput = is_numeric($eventDateRaw)
            ? (function_exists('wp_date') ? wp_date('Y-m-d', (int) $eventDateRaw) : date('Y-m-d', (int) $eventDateRaw))
            : $eventDateRaw;
        $servingTime = $this->getOrderMetaWithFallback($order, MetaKeys::SERVING_TIME);
        $startTime = $this->getOrderMetaWithFallback($order, MetaKeys::START_TIME);
        $eventType = $this->getOrderMetaWithFallback($order, MetaKeys::EVENT_TYPE);
        $howFoundUs = $this->getOrderMetaWithFallback($order, MetaKeys::HOW_FOUND_US);
        $intolerances = $this->getOrderMetaWithFallback($order, MetaKeys::INTOLERANCES);
        wp_nonce_field('zs_event_details_save', 'zs_event_details_nonce');
        ?>
        
        <div class="zs-event-details-wrapper">
            <!-- Guest Information -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Guest Information', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_total_guests">
                            <?php esc_html_e('Total Guests', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_total_guests" 
                               name="event_total_guests" 
                               value="<?php echo esc_attr($totalGuests); ?>" 
                               min="0"
                               class="short">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_adults">
                            <?php esc_html_e('Adults', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_adults" 
                               name="event_adults" 
                               value="<?php echo esc_attr($adults); ?>" 
                               min="0"
                               class="short">
                    </div>
                </div>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_children_5_to_8">
                            <?php esc_html_e('Children 5-8 years (40%)', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_children_5_to_8" 
                               name="event_children_5_to_8" 
                               value="<?php echo esc_attr($children5to8); ?>" 
                               min="0"
                               class="short">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_children_0_to_4">
                            <?php esc_html_e('Children 0-4 years (FREE)', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_children_0_to_4" 
                               name="event_children_0_to_4" 
                               value="<?php echo esc_attr($children0to4); ?>" 
                               min="0"
                               class="short">
                    </div>
                </div>
            </div>

            <!-- Location Information -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Location Information', 'zero-sense'); ?></h3>
                
                <div class="zs-field">
                    <label for="event_service_location">
                        <?php esc_html_e('Service Location', 'zero-sense'); ?>
                    </label>
                    <select id="event_service_location" name="event_service_location" class="widefat">
                        <option value=""><?php esc_html_e('Select...', 'zero-sense'); ?></option>
                        <?php foreach ($serviceAreaTerms as $term) : ?>
                            <?php if ($term instanceof \WP_Term) : ?>
                                <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($serviceLocationSelectedId, (int) $term->term_id); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="zs-field">
                    <label for="event_address">
                        <?php esc_html_e('Event Address', 'zero-sense'); ?>
                    </label>
                    <input type="text" 
                           id="event_address" 
                           name="event_address" 
                           value="<?php echo esc_attr($address); ?>" 
                           class="widefat">
                </div>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_city">
                            <?php esc_html_e('City', 'zero-sense'); ?>
                        </label>
                        <input type="text" 
                               id="event_city" 
                               name="event_city" 
                               value="<?php echo esc_attr($city); ?>" 
                               class="widefat">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_location_link">
                            <?php esc_html_e('Location Link', 'zero-sense'); ?>
                        </label>
                        <input type="url" 
                               id="event_location_link" 
                               name="event_location_link" 
                               value="<?php echo esc_attr($locationLink); ?>" 
                               class="widefat"
                               placeholder="https://maps.google.com/...">
                        <?php if ($locationLink) : ?>
                            <a href="<?php echo esc_url($locationLink); ?>" target="_blank" class="button button-small" style="margin-top:5px;">
                                <?php esc_html_e('Open Map', 'zero-sense'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Event Timing -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Event Timing', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_date">
                            <?php esc_html_e('Event Date', 'zero-sense'); ?>
                        </label>
                        <input type="date" 
                               id="event_date" 
                               name="event_date" 
                               value="<?php echo esc_attr($eventDateForInput); ?>" 
                               class="widefat">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_team_arrival_time">
                            <?php esc_html_e('Team arrival time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_team_arrival_time" 
                               name="event_team_arrival_time" 
                               value="<?php echo esc_attr($teamArrivalTime); ?>" 
                               class="widefat">
                    </div>
                </div>

                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_serving_time">
                            <?php esc_html_e('Service time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_serving_time" 
                               name="event_serving_time" 
                               value="<?php echo esc_attr($servingTime); ?>" 
                               class="widefat">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_start_time">
                            <?php esc_html_e('Event start time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_start_time" 
                               name="event_start_time" 
                               value="<?php echo esc_attr($startTime); ?>" 
                               class="widefat">
                    </div>
                </div>
            </div>

            <!-- Event Details -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Additional Details', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_type">
                            <?php esc_html_e('Event Type', 'zero-sense'); ?>
                        </label>
                        <select id="event_type" name="event_type" class="widefat">
                            <option value=""><?php esc_html_e('Select...', 'zero-sense'); ?></option>
                            <?php foreach (FieldOptions::getEventTypeOptions() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($eventType, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_how_found_us">
                            <?php esc_html_e('How Found Us', 'zero-sense'); ?>
                        </label>
                        <select id="event_how_found_us" name="event_how_found_us" class="widefat">
                            <option value=""><?php esc_html_e('Select...', 'zero-sense'); ?></option>
                            <?php foreach (FieldOptions::getHowFoundUsOptions() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($howFoundUs, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="zs-field">
                    <label for="event_intolerances">
                        <?php esc_html_e('Allergies / intolerances', 'zero-sense'); ?>
                    </label>
                    <textarea id="event_intolerances" name="event_intolerances" rows="4" class="widefat"><?php echo esc_textarea(is_string($intolerances) ? $intolerances : ''); ?></textarea>
                </div>
            </div>

            <!-- Event Media -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Event Media', 'zero-sense'); ?></h3>
                
                <div class="zs-field">
                    <label><?php esc_html_e('Uploaded Media', 'zero-sense'); ?></label>
                    <?php
                    $media_ids = $order->get_meta('_zs_event_media', true);
                    if ($media_ids) {
                        $ids = explode(',', $media_ids);
                        echo '<div class="zs-media-preview-admin">';
                        foreach ($ids as $id) {
                            $id = trim($id);
                            if (empty($id)) continue;
                            
                            $url = wp_get_attachment_url($id);
                            $type = get_post_mime_type($id);
                            $title = get_the_title($id);
                            
                            if ($url) {
                                echo '<div class="zs-media-item-admin" title="' . esc_attr($title) . '">';
                                if (strpos($type, 'image') !== false) {
                                    echo '<img src="' . esc_url($url) . '" alt="' . esc_attr($title) . '">';
                                } elseif (strpos($type, 'video') !== false) {
                                    echo '<video src="' . esc_url($url) . '" controls></video>';
                                } else {
                                    echo '<div class="media-placeholder">File: ' . esc_html($title) . '</div>';
                                }
                                echo '<a href="' . esc_url($url) . '" target="_blank" class="media-link" title="View full size">🔗</a>';
                                echo '</div>';
                            }
                        }
                        echo '</div>';
                    } else {
                        echo '<p>' . esc_html__('No media uploaded', 'zero-sense') . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <style>
            .zs-event-details-wrapper {
                padding: 12px;
            }
            .zs-field-group {
                margin-bottom: 24px;
                padding-bottom: 24px;
                border-bottom: 1px solid #ddd;
            }
            .zs-field-group:last-child {
                border-bottom: none;
            }
            .zs-field-group h3 {
                margin: 0 0 16px 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .zs-field-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin-bottom: 16px;
            }
            .zs-field-row:last-child {
                margin-bottom: 0;
            }
            .zs-field {
                margin-bottom: 16px;
            }
            .zs-field:last-child {
                margin-bottom: 0;
            }
            .zs-field label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                font-size: 13px;
                color: #1d2327;
            }
            .zs-field input[type="text"],
            .zs-field input[type="number"],
            .zs-field input[type="date"],
            .zs-field input[type="time"],
            .zs-field input[type="url"],
            .zs-field select {
                width: 100%;
            }
            .zs-field input.short {
                width: 100px;
            }
            .zs-media-preview-admin {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-top: 10px;
            }
            .zs-media-item-admin {
                position: relative;
                width: 120px;
                height: 120px;
                border: 1px solid #ddd;
                border-radius: 3px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .zs-media-item-admin img,
            .zs-media-item-admin video {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .zs-media-item-admin .media-link {
                position: absolute;
                top: 5px;
                right: 5px;
                background: rgba(0,0,0,0.7);
                color: white;
                text-decoration: none;
                padding: 3px 6px;
                border-radius: 3px;
                font-size: 12px;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .zs-media-item-admin:hover .media-link {
                opacity: 1;
            }
            .media-placeholder {
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f5f5f5;
                font-size: 11px;
                text-align: center;
                padding: 5px;
                box-sizing: border-box;
            }
        </style>
        <?php
    }

    public function save($orderId): void
    {
        // Verify nonce
        if (!isset($_POST['zs_event_details_nonce']) || 
            !wp_verify_nonce($_POST['zs_event_details_nonce'], 'zs_event_details_save')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Save guest information
        if (isset($_POST['event_total_guests'])) {
            $order->update_meta_data(MetaKeys::TOTAL_GUESTS, absint($_POST['event_total_guests']));
        }
        if (isset($_POST['event_adults'])) {
            $order->update_meta_data(MetaKeys::ADULTS, absint($_POST['event_adults']));
        }
        if (isset($_POST['event_children_5_to_8'])) {
            $order->update_meta_data(MetaKeys::CHILDREN_5_TO_8, absint($_POST['event_children_5_to_8']));
        }
        if (isset($_POST['event_children_0_to_4'])) {
            $order->update_meta_data(MetaKeys::CHILDREN_0_TO_4, absint($_POST['event_children_0_to_4']));
        }

        // Save location information
        if (isset($_POST['event_service_location'])) {
            $raw = absint($_POST['event_service_location']);
            if ($raw <= 0) {
                $order->update_meta_data(MetaKeys::SERVICE_LOCATION, '');
            } else {
                $canonicalId = $raw;
                if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
                    $defaultLang = apply_filters('wpml_default_language', null);
                    if (is_string($defaultLang) && $defaultLang !== '') {
                        $translated = apply_filters('wpml_object_id', $raw, 'service-area', true, $defaultLang);
                        if ($translated) {
                            $canonicalId = (int) $translated;
                        }
                    }
                }
                $order->update_meta_data(MetaKeys::SERVICE_LOCATION, (int) $canonicalId);
            }
        }
        if (isset($_POST['event_address'])) {
            $order->update_meta_data(MetaKeys::ADDRESS, sanitize_text_field($_POST['event_address']));
        }
        if (isset($_POST['event_city'])) {
            $order->update_meta_data(MetaKeys::CITY, sanitize_text_field($_POST['event_city']));
        }
        if (isset($_POST['event_location_link'])) {
            $order->update_meta_data(MetaKeys::LOCATION_LINK, esc_url_raw($_POST['event_location_link']));
        }

        // Save event timing
        if (isset($_POST['event_date'])) {
            $rawDate = sanitize_text_field((string) $_POST['event_date']);
            if ($rawDate === '') {
                $order->update_meta_data(MetaKeys::EVENT_DATE, '');
            } else {
                $timestamp = $this->normalizeEventDateToTimestamp($rawDate);
                $order->update_meta_data(MetaKeys::EVENT_DATE, $timestamp > 0 ? $timestamp : $rawDate);
            }
        }
        if (isset($_POST['event_team_arrival_time'])) {
            $order->update_meta_data(MetaKeys::TEAM_ARRIVAL_TIME, sanitize_text_field((string) $_POST['event_team_arrival_time']));
        }
        if (isset($_POST['event_serving_time'])) {
            $order->update_meta_data(MetaKeys::SERVING_TIME, sanitize_text_field($_POST['event_serving_time']));
        }
        if (isset($_POST['event_start_time'])) {
            $order->update_meta_data(MetaKeys::START_TIME, sanitize_text_field($_POST['event_start_time']));
        }

        // Save event details
        if (isset($_POST['event_type'])) {
            $order->update_meta_data(MetaKeys::EVENT_TYPE, sanitize_text_field($_POST['event_type']));
        }
        if (isset($_POST['event_how_found_us'])) {
            $order->update_meta_data(MetaKeys::HOW_FOUND_US, sanitize_text_field($_POST['event_how_found_us']));
        }
        if (isset($_POST['event_intolerances'])) {
            $order->update_meta_data(MetaKeys::INTOLERANCES, sanitize_textarea_field((string) $_POST['event_intolerances']));
        }

        $order->save();
    }

    private function normalizeEventDateToTimestamp($value): int
    {
        if (is_numeric($value) && (int) $value == $value) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return 0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value, $tz);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->setTime(0, 0, 0)->getTimestamp();
            }
        }

        $ts = strtotime($value);
        return $ts ? (int) $ts : 0;
    }

    private function getOrderMetaWithFallback(WC_Order $order, string $key)
    {
        $value = $order->get_meta($key, true);
        if ($value !== '' && $value !== null) {
            return $value;
        }

        $legacyMetaBoxKey = self::LEGACY_META_KEYS[$key] ?? null;
        if (is_string($legacyMetaBoxKey) && $legacyMetaBoxKey !== '') {
            $legacyValue = $order->get_meta($legacyMetaBoxKey, true);
            if ($legacyValue !== '' && $legacyValue !== null) {
                return $legacyValue;
            }
        }

        $legacyEventKey = self::LEGACY_EVENT_KEYS[$key] ?? null;
        if (is_string($legacyEventKey) && $legacyEventKey !== '') {
            return $order->get_meta($legacyEventKey, true);
        }

        return '';
    }
}
