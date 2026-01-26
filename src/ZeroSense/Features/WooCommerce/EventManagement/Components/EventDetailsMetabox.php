<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

class EventDetailsMetabox
{
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
        $totalGuests = $order->get_meta(MetaKeys::TOTAL_GUESTS);
        $adults = $order->get_meta(MetaKeys::ADULTS);
        $children5to8 = $order->get_meta(MetaKeys::CHILDREN_5_TO_8);
        $children0to4 = $order->get_meta(MetaKeys::CHILDREN_0_TO_4);
        $serviceLocation = $order->get_meta(MetaKeys::SERVICE_LOCATION);
        $address = $order->get_meta(MetaKeys::ADDRESS);
        $city = $order->get_meta(MetaKeys::CITY);
        $locationLink = $order->get_meta(MetaKeys::LOCATION_LINK);
        $eventDate = $order->get_meta(MetaKeys::EVENT_DATE);
        $servingTime = $order->get_meta(MetaKeys::SERVING_TIME);
        $startTime = $order->get_meta(MetaKeys::START_TIME);
        $eventType = $order->get_meta(MetaKeys::EVENT_TYPE);
        $howFoundUs = $order->get_meta(MetaKeys::HOW_FOUND_US);
        
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
                        <?php foreach (FieldOptions::getServiceLocationOptions() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($serviceLocation, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
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
                               value="<?php echo esc_attr($eventDate); ?>" 
                               class="widefat">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_serving_time">
                            <?php esc_html_e('Serving Time', 'zero-sense'); ?>
                        </label>
                        <input type="time" 
                               id="event_serving_time" 
                               name="event_serving_time" 
                               value="<?php echo esc_attr($servingTime); ?>" 
                               class="widefat">
                    </div>
                    
                    <div class="zs-field">
                        <label for="event_start_time">
                            <?php esc_html_e('Event Start Time', 'zero-sense'); ?>
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
            $order->update_meta_data(MetaKeys::SERVICE_LOCATION, sanitize_text_field($_POST['event_service_location']));
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
            $order->update_meta_data(MetaKeys::EVENT_DATE, sanitize_text_field($_POST['event_date']));
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

        $order->save();
    }
}
