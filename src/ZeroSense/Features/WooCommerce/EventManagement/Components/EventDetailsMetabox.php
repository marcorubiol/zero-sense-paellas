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
        MetaKeys::STARTERS_SERVICE_TIME => 'starters_service_time',
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
        MetaKeys::STARTERS_SERVICE_TIME => '_event_starters_service_time',
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
        $eventDateForInput = $this->getOrderMetaWithFallback($order, MetaKeys::EVENT_DATE);
        $teamArrivalTime = $this->getOrderMetaWithFallback($order, MetaKeys::TEAM_ARRIVAL_TIME);
        $servingTime = $this->getOrderMetaWithFallback($order, MetaKeys::SERVING_TIME);
        $startersServiceTime = $this->getOrderMetaWithFallback($order, MetaKeys::STARTERS_SERVICE_TIME);
        $startTime = $this->getOrderMetaWithFallback($order, MetaKeys::START_TIME);
        $eventType = $this->getOrderMetaWithFallback($order, MetaKeys::EVENT_TYPE);
        $howFoundUs = $this->getOrderMetaWithFallback($order, MetaKeys::HOW_FOUND_US);
        $intolerances = $this->getOrderMetaWithFallback($order, MetaKeys::INTOLERANCES);
        wp_nonce_field('zs_event_details_save', 'zs_event_details_nonce');
        ?>
        
        <div class="zs-event-details-wrapper">
            <!-- Guest Information -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Guest information', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_total_guests">
                            <?php esc_html_e('Total guests', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_total_guests" 
                               name="event_total_guests" 
                               value="<?php echo esc_attr($totalGuests); ?>" 
                               min="0"
                               class="short">
                    </div>
                </div>
                
                <div class="zs-field-row">
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
                            <?php esc_html_e('Children 0-4 years (free)', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_children_0_to_4" 
                               name="event_children_0_to_4" 
                               value="<?php echo esc_attr($children0to4); ?>" 
                               min="0"
                               class="short">
                    </div>
                </div>

                <div class="zs-field">
                    <label for="event_intolerances">
                        <?php esc_html_e('Allergies / Intolerances', 'zero-sense'); ?>
                    </label>
                    <textarea id="event_intolerances" name="event_intolerances" rows="4" class="widefat"><?php echo esc_textarea(is_string($intolerances) ? $intolerances : ''); ?></textarea>
                </div>
            </div>

            <!-- Service Location -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Service location', 'zero-sense'); ?></h3>
                
                <div class="zs-field">
                    <label for="event_service_location">
                        <?php esc_html_e('Service location', 'zero-sense'); ?>
                    </label>
                    <select id="event_service_location" name="event_service_location">
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
            </div>

            <!-- Event Timing -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Event timing', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_date">
                            <?php esc_html_e('Event date', 'zero-sense'); ?>
                        </label>
                        <input type="date" 
                               id="event_date" 
                               name="event_date" 
                               value="<?php echo esc_attr($eventDateForInput); ?>" 
                               class="short">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_start_time">
                            <?php esc_html_e('Event start time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_start_time" 
                               name="event_start_time" 
                               value="<?php echo esc_attr($startTime); ?>" 
                               class="short">
                    </div>
                </div>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_team_arrival_time">
                            <?php esc_html_e('Team arrival time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_team_arrival_time" 
                               name="event_team_arrival_time" 
                               value="<?php echo esc_attr($teamArrivalTime); ?>" 
                               class="short">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_serving_time">
                            <?php esc_html_e('Paellas Service Time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_serving_time" 
                               name="event_serving_time" 
                               value="<?php echo esc_attr($servingTime); ?>" 
                               class="short">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_starters_service_time">
                            <?php esc_html_e('Starters Service Time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_starters_service_time" 
                               name="event_starters_service_time" 
                               value="<?php echo esc_attr($startersServiceTime); ?>" 
                               class="short">
                    </div>
                </div>
            </div>

            <!-- Event Details -->
            <div class="zs-field-group">
                <h3><?php esc_html_e('Additional details', 'zero-sense'); ?></h3>
                
                <div class="zs-field-row">
                    <div class="zs-field">
                        <label for="event_type">
                            <?php esc_html_e('Event type', 'zero-sense'); ?>
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
                            <?php esc_html_e('How found us', 'zero-sense'); ?>
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
            </div>
        </div>

        <style>
            .zs-event-details-wrapper {
                padding: 12px;
            }
            .zs-field-group {
                display: grid;
                gap: 16px;
                margin-bottom: 24px;
                padding-bottom: 24px;
                border-bottom: 1px solid #ddd;
            }
            .zs-field-group:last-child {
                border-bottom: none;
            }
            .zs-field-group h3 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .zs-field-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 16px;
            }

            .zs-field-row .zs-field {
                display: flex;
                flex-direction: column;
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
                width: 150px;
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
        // Save event timing (YYYY-MM-DD format, ISO 8601)
        if (isset($_POST['event_date'])) {
            $order->update_meta_data(MetaKeys::EVENT_DATE, sanitize_text_field((string) $_POST['event_date']));
        }
        if (isset($_POST['event_team_arrival_time'])) {
            $order->update_meta_data(MetaKeys::TEAM_ARRIVAL_TIME, sanitize_text_field((string) $_POST['event_team_arrival_time']));
        }
        if (isset($_POST['event_serving_time'])) {
            $servingTime = sanitize_text_field($_POST['event_serving_time']);
            $order->update_meta_data(MetaKeys::SERVING_TIME, $servingTime);
            
            // Auto-calculate starters service time if not set and serving time is provided
            $existingStartersTime = $order->get_meta(MetaKeys::STARTERS_SERVICE_TIME, true);
            if (($existingStartersTime === '' || $existingStartersTime === null) && $servingTime !== '') {
                $startersTime = $this->calculateStartersTime($servingTime);
                if ($startersTime !== '') {
                    $order->update_meta_data(MetaKeys::STARTERS_SERVICE_TIME, $startersTime);
                }
            }
        }
        if (isset($_POST['event_starters_service_time'])) {
            $order->update_meta_data(MetaKeys::STARTERS_SERVICE_TIME, sanitize_text_field($_POST['event_starters_service_time']));
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

    private function calculateStartersTime(string $paellasTime): string
    {
        if ($paellasTime === '') {
            return '';
        }

        try {
            $time = \DateTime::createFromFormat('H:i', $paellasTime);
            if ($time === false) {
                return '';
            }
            
            $time->modify('-30 minutes');
            return $time->format('H:i');
        } catch (\Exception $e) {
            return '';
        }
    }
}
