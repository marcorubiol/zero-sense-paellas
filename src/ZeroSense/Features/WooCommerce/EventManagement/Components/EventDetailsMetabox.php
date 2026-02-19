<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;

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
        MetaKeys::OPEN_BAR_START => 'open_bar_start',
        MetaKeys::OPEN_BAR_END => 'open_bar_end',
        MetaKeys::COCKTAIL_START => 'cocktail_start',
        MetaKeys::COCKTAIL_END => 'cocktail_end',
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
        MetaKeys::OPEN_BAR_START => '_event_open_bar_start',
        MetaKeys::OPEN_BAR_END => '_event_open_bar_end',
        MetaKeys::COCKTAIL_START => '_event_cocktail_start',
        MetaKeys::COCKTAIL_END => '_event_cocktail_end',
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
        $openBarStart = $this->getOrderMetaWithFallback($order, MetaKeys::OPEN_BAR_START);
        $openBarEnd = $this->getOrderMetaWithFallback($order, MetaKeys::OPEN_BAR_END);
        $cocktailStart = $this->getOrderMetaWithFallback($order, MetaKeys::COCKTAIL_START);
        $cocktailEnd = $this->getOrderMetaWithFallback($order, MetaKeys::COCKTAIL_END);
        $eventType = $this->getOrderMetaWithFallback($order, MetaKeys::EVENT_TYPE);
        $howFoundUs = $this->getOrderMetaWithFallback($order, MetaKeys::HOW_FOUND_US);
        $intolerances = $this->getOrderMetaWithFallback($order, MetaKeys::INTOLERANCES);
        wp_nonce_field('zs_event_details_save', 'zs_event_details_nonce');
        ?>
        
        <div class="zs-mb-wrapper">
            <!-- Guest Information -->
            <div class="zs-mb-field-group">
                <h3><?php esc_html_e('Guest information', 'zero-sense'); ?></h3>
                
                <div class="zs-mb-field-row">
                    <div class="zs-mb-field">
                        <label for="event_total_guests">
                            <?php esc_html_e('Total guests', 'zero-sense'); ?>
                        </label>
                        <input type="number" 
                               id="event_total_guests" 
                               name="event_total_guests" 
                               value="<?php echo esc_attr($totalGuests); ?>" 
                               min="0"
                               class="short">
                        <div id="zs-guests-validation" class="zs-guest-validation">
                            <span class="zs-guest-validation-message"></span>
                        </div>
                    </div>
                </div>
                
                <div class="zs-mb-field-row">
                    <div class="zs-mb-field">
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
                    
                    <div class="zs-mb-field">
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
                    
                    <div class="zs-mb-field">
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

                <div class="zs-mb-field">
                    <label for="event_intolerances">
                        <?php esc_html_e('Allergies / Intolerances', 'zero-sense'); ?>
                    </label>
                    <textarea id="event_intolerances" name="event_intolerances" rows="4" class="widefat"><?php echo esc_textarea(is_string($intolerances) ? $intolerances : ''); ?></textarea>
                </div>
            </div>

            <!-- Service Location -->
            <div class="zs-mb-field-group">
                <h3><?php esc_html_e('Service location', 'zero-sense'); ?></h3>
                
                <div class="zs-mb-field">
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
            <div class="zs-mb-field-group">
                <h3><?php esc_html_e('Event timing', 'zero-sense'); ?></h3>
                
                <div class="zs-mb-field-row">
                    <div class="zs-mb-field">
                        <label for="event_date">
                            <?php esc_html_e('Event date', 'zero-sense'); ?>
                        </label>
                        <input type="date" 
                               id="event_date" 
                               name="event_date" 
                               value="<?php echo esc_attr($eventDateForInput); ?>" 
                               class="short">
                    </div>
                    
                    <div class="zs-mb-field">
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
                
                <!-- Team Setup -->
                <div class="zs-mb-divider">
                    <h4 class="zs-mb-subheader"><?php esc_html_e('Team setup', 'zero-sense'); ?></h4>
                    
                    <div class="zs-mb-field-row">
                        <div class="zs-mb-field">
                            <label for="event_team_arrival_time">
                                <?php esc_html_e('Team arrival time', 'zero-sense'); ?>
                            </label>
                            <div class="zs-mb-field-inline">
                                <input type="time" 
                                       id="event_team_arrival_time" 
                                       name="event_team_arrival_time" 
                                       value="<?php echo esc_attr($teamArrivalTime); ?>" 
                                       class="short">
                                <button type="button" 
                                        id="zs-recalculate-team-arrival-time" 
                                        class="zs-btn is-primary"
                                        title="<?php esc_attr_e('Set 3 hours before Paellas service time', 'zero-sense'); ?>">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Auto', 'zero-sense'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="zs-mb-field"></div>
                    </div>
                </div>
                
                <!-- Service Times -->
                <div class="zs-mb-divider">
                    <h4 class="zs-mb-subheader"><?php esc_html_e('Service times', 'zero-sense'); ?></h4>
                    
                    <div class="zs-mb-field-row">
                        <div class="zs-mb-field">
                            <label for="event_starters_service_time">
                                <?php esc_html_e('Starters service time', 'zero-sense'); ?>
                            </label>
                            <div class="zs-mb-field-inline">
                                <input type="time" 
                                       id="event_starters_service_time" 
                                       name="event_starters_service_time" 
                                       value="<?php echo esc_attr($startersServiceTime); ?>" 
                                       class="short">
                                <button type="button" 
                                        id="zs-recalculate-starters-time" 
                                        class="zs-btn is-primary"
                                        title="<?php esc_attr_e('Set 30 minutes before Paellas service time', 'zero-sense'); ?>">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Auto', 'zero-sense'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="zs-mb-field">
                            <label for="event_serving_time">
                                <?php esc_html_e('Paellas service time', 'zero-sense'); ?>
                            </label>
                            <input type="time" 
                                   id="event_serving_time" 
                                   name="event_serving_time" 
                                   value="<?php echo esc_attr($servingTime); ?>" 
                                   class="short">
                        </div>
                    </div>
                </div>
                
                <!-- Open Bar -->
                <div class="zs-mb-divider">
                    <h4 class="zs-mb-subheader"><?php esc_html_e('Open bar', 'zero-sense'); ?></h4>
                    
                    <div class="zs-mb-field-row">
                        <div class="zs-mb-field">
                            <label for="event_open_bar_start">
                                <?php esc_html_e('Open bar start', 'zero-sense'); ?>
                            </label>
                            <input type="time" 
                                   id="event_open_bar_start" 
                                   name="event_open_bar_start" 
                                   value="<?php echo esc_attr($openBarStart); ?>" 
                                   class="short">
                        </div>
                        
                        <div class="zs-mb-field">
                            <label for="event_open_bar_end">
                                <?php esc_html_e('Open bar end', 'zero-sense'); ?>
                            </label>
                            <input type="time" 
                                   id="event_open_bar_end" 
                                   name="event_open_bar_end" 
                                   value="<?php echo esc_attr($openBarEnd); ?>" 
                                   class="short">
                        </div>
                    </div>
                </div>
                
                <!-- Cocktail -->
                <div class="zs-mb-divider">
                    <h4 class="zs-mb-subheader"><?php esc_html_e('Cocktail', 'zero-sense'); ?></h4>
                    
                    <div class="zs-mb-field-row">
                        <div class="zs-mb-field">
                            <label for="event_cocktail_start">
                                <?php esc_html_e('Cocktail start', 'zero-sense'); ?>
                            </label>
                            <input type="time" 
                                   id="event_cocktail_start" 
                                   name="event_cocktail_start" 
                                   value="<?php echo esc_attr($cocktailStart); ?>" 
                                   class="short">
                        </div>
                        
                        <div class="zs-mb-field">
                            <label for="event_cocktail_end">
                                <?php esc_html_e('Cocktail end', 'zero-sense'); ?>
                            </label>
                            <input type="time" 
                                   id="event_cocktail_end" 
                                   name="event_cocktail_end" 
                                   value="<?php echo esc_attr($cocktailEnd); ?>" 
                                   class="short">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Event Details -->
            <div class="zs-mb-field-group">
                <h3><?php esc_html_e('Additional details', 'zero-sense'); ?></h3>
                
                <div class="zs-mb-field-row">
                    <div class="zs-mb-field">
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
                    
                    <div class="zs-mb-field">
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

        <script>
        (function() {
            'use strict';
            
            document.addEventListener('DOMContentLoaded', function() {
                const paellasTimeInput = document.getElementById('event_serving_time');

                function subtractMinutes(timeStr, totalMinutes) {
                    const parts = timeStr.split(':');
                    if (parts.length !== 2) return null;
                    let hours = parseInt(parts[0], 10);
                    let minutes = parseInt(parts[1], 10);
                    minutes -= totalMinutes % 60;
                    hours -= Math.floor(totalMinutes / 60);
                    if (minutes < 0) { minutes += 60; hours -= 1; }
                    if (hours < 0) { hours += 24; }
                    return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
                }

                function setupRecalcBtn(btnId, targetInputId, offsetMinutes) {
                    const btn = document.getElementById(btnId);
                    const targetInput = document.getElementById(targetInputId);
                    if (!btn || !paellasTimeInput || !targetInput) return;
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const paellasTime = paellasTimeInput.value;
                        if (!paellasTime) {
                            alert('<?php echo esc_js(__('Please set Paellas Service Time first', 'zero-sense')); ?>');
                            paellasTimeInput.focus();
                            return;
                        }
                        const result = subtractMinutes(paellasTime, offsetMinutes);
                        if (!result) {
                            alert('<?php echo esc_js(__('Invalid time format', 'zero-sense')); ?>');
                            return;
                        }
                        targetInput.value = result;
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<span class="dashicons dashicons-yes" style="font-size: 16px; width: 16px; height: 16px;"></span> <?php echo esc_js(__('Done', 'zero-sense')); ?>';
                        btn.disabled = true;
                        setTimeout(function() { btn.innerHTML = originalText; btn.disabled = false; }, 1500);
                    });
                }

                setupRecalcBtn('zs-recalculate-starters-time', 'event_starters_service_time', 30);
                setupRecalcBtn('zs-recalculate-team-arrival-time', 'event_team_arrival_time', 180);

                // Guest count validation
                function updateGuestsValidation() {
                    console.log('updateGuestsValidation called'); // Debug
                    
                    const totalGuestsInput = document.getElementById('event_total_guests');
                    const adultsInput = document.getElementById('event_adults');
                    const children5to8Input = document.getElementById('event_children_5_to_8');
                    const children0to4Input = document.getElementById('event_children_0_to_4');
                    const validationContainer = document.getElementById('zs-guests-validation');
                    const validationMessage = validationContainer.querySelector('.zs-guest-validation-message');
                    
                    console.log('Elements found:', {
                        totalGuestsInput: !!totalGuestsInput,
                        adultsInput: !!adultsInput,
                        children5to8Input: !!children5to8Input,
                        children0to4Input: !!children0to4Input,
                        validationContainer: !!validationContainer
                    }); // Debug
                    
                    if (!totalGuestsInput || !adultsInput || !children5to8Input || !children0to4Input || !validationContainer) {
                        console.log('Missing elements, returning'); // Debug
                        return;
                    }
                    
                    const totalGuests = parseInt(totalGuestsInput.value) || 0;
                    const adults = parseInt(adultsInput.value) || 0;
                    const children5to8 = parseInt(children5to8Input.value) || 0;
                    const children0to4 = parseInt(children0to4Input.value) || 0;
                    const sumOfPeople = adults + children5to8 + children0to4;
                    
                    console.log('Values:', { totalGuests, adults, children5to8, children0to4, sumOfPeople }); // Debug
                    
                    // Remove all validation classes from input
                    totalGuestsInput.classList.remove('match', 'lower', 'higher');
                    validationContainer.classList.remove('match', 'lower', 'higher');
                    
                    if (totalGuests === sumOfPeople && sumOfPeople > 0) {
                        totalGuestsInput.classList.add('match');
                        validationContainer.classList.add('match');
                        validationMessage.textContent = '';
                        console.log('Added match class'); // Debug
                    } else if (totalGuests < sumOfPeople && sumOfPeople > 0) {
                        totalGuestsInput.classList.add('lower');
                        validationContainer.classList.add('lower');
                        validationMessage.textContent = 'El número de personas totales es más bajo que la suma de personas';
                        console.log('Added lower class'); // Debug
                    } else if (totalGuests > sumOfPeople && totalGuests > 0) {
                        totalGuestsInput.classList.add('higher');
                        validationContainer.classList.add('higher');
                        validationMessage.textContent = 'El número de personas totales es más alto que la suma de personas';
                        console.log('Added higher class'); // Debug
                    } else {
                        validationMessage.textContent = '';
                        console.log('No validation applied'); // Debug
                    }
                }
                
                // Add event listeners to all guest count fields
                const guestFields = ['event_total_guests', 'event_adults', 'event_children_5_to_8', 'event_children_0_to_4'];
                guestFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.addEventListener('input', updateGuestsValidation);
                        field.addEventListener('change', updateGuestsValidation);
                    }
                });
                
                // Initial validation on page load
                updateGuestsValidation();

            });
        })();
        </script>
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
            $newValue = absint($_POST['event_total_guests']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::TOTAL_GUESTS, $order->get_meta(MetaKeys::TOTAL_GUESTS, true), $newValue);
            $order->update_meta_data(MetaKeys::TOTAL_GUESTS, $newValue);
        }
        if (isset($_POST['event_adults'])) {
            $newValue = absint($_POST['event_adults']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::ADULTS, $order->get_meta(MetaKeys::ADULTS, true), $newValue);
            $order->update_meta_data(MetaKeys::ADULTS, $newValue);
        }
        if (isset($_POST['event_children_5_to_8'])) {
            $newValue = absint($_POST['event_children_5_to_8']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::CHILDREN_5_TO_8, $order->get_meta(MetaKeys::CHILDREN_5_TO_8, true), $newValue);
            $order->update_meta_data(MetaKeys::CHILDREN_5_TO_8, $newValue);
        }
        if (isset($_POST['event_children_0_to_4'])) {
            $newValue = absint($_POST['event_children_0_to_4']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::CHILDREN_0_TO_4, $order->get_meta(MetaKeys::CHILDREN_0_TO_4, true), $newValue);
            $order->update_meta_data(MetaKeys::CHILDREN_0_TO_4, $newValue);
        }

        // Save location information
        if (isset($_POST['event_service_location'])) {
            $raw = absint($_POST['event_service_location']);
            $newValue = '';
            if ($raw <= 0) {
                $newValue = '';
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
                $newValue = (int) $canonicalId;
            }
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::SERVICE_LOCATION, $order->get_meta(MetaKeys::SERVICE_LOCATION, true), $newValue);
            $order->update_meta_data(MetaKeys::SERVICE_LOCATION, $newValue);
        }
        // Save event timing (YYYY-MM-DD format, ISO 8601)
        if (isset($_POST['event_date'])) {
            $newValue = sanitize_text_field((string) $_POST['event_date']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::EVENT_DATE, $order->get_meta(MetaKeys::EVENT_DATE, true), $newValue);
            $order->update_meta_data(MetaKeys::EVENT_DATE, $newValue);
        }
        if (isset($_POST['event_team_arrival_time'])) {
            $newValue = sanitize_text_field((string) $_POST['event_team_arrival_time']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::TEAM_ARRIVAL_TIME, $order->get_meta(MetaKeys::TEAM_ARRIVAL_TIME, true), $newValue);
            $order->update_meta_data(MetaKeys::TEAM_ARRIVAL_TIME, $newValue);
        }
        if (isset($_POST['event_serving_time'])) {
            $servingTime = sanitize_text_field($_POST['event_serving_time']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::SERVING_TIME, $order->get_meta(MetaKeys::SERVING_TIME, true), $servingTime);
            $order->update_meta_data(MetaKeys::SERVING_TIME, $servingTime);
            
            // Auto-calculate starters service time if not set and serving time is provided
            $existingStartersTime = $order->get_meta(MetaKeys::STARTERS_SERVICE_TIME, true);
            if (($existingStartersTime === '' || $existingStartersTime === null) && $servingTime !== '') {
                $startersTime = $this->calculateStartersTime($servingTime);
                if ($startersTime !== '') {
                    $order->update_meta_data(MetaKeys::STARTERS_SERVICE_TIME, $startersTime);
                }
            }
            
            // Auto-calculate team arrival time if not set and serving time is provided
            $existingTeamArrivalTime = $order->get_meta(MetaKeys::TEAM_ARRIVAL_TIME, true);
            if (($existingTeamArrivalTime === '' || $existingTeamArrivalTime === null) && $servingTime !== '') {
                $teamArrivalTime = $this->calculateTeamArrivalTime($servingTime);
                if ($teamArrivalTime !== '') {
                    $order->update_meta_data(MetaKeys::TEAM_ARRIVAL_TIME, $teamArrivalTime);
                }
            }
        }
        if (isset($_POST['event_starters_service_time'])) {
            $newValue = sanitize_text_field($_POST['event_starters_service_time']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::STARTERS_SERVICE_TIME, $order->get_meta(MetaKeys::STARTERS_SERVICE_TIME, true), $newValue);
            $order->update_meta_data(MetaKeys::STARTERS_SERVICE_TIME, $newValue);
        }
        if (isset($_POST['event_start_time'])) {
            $newValue = sanitize_text_field($_POST['event_start_time']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::START_TIME, $order->get_meta(MetaKeys::START_TIME, true), $newValue);
            $order->update_meta_data(MetaKeys::START_TIME, $newValue);
        }
        if (isset($_POST['event_open_bar_start'])) {
            $newValue = sanitize_text_field($_POST['event_open_bar_start']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::OPEN_BAR_START, $order->get_meta(MetaKeys::OPEN_BAR_START, true), $newValue);
            $order->update_meta_data(MetaKeys::OPEN_BAR_START, $newValue);
        }
        if (isset($_POST['event_open_bar_end'])) {
            $newValue = sanitize_text_field($_POST['event_open_bar_end']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::OPEN_BAR_END, $order->get_meta(MetaKeys::OPEN_BAR_END, true), $newValue);
            $order->update_meta_data(MetaKeys::OPEN_BAR_END, $newValue);
        }
        if (isset($_POST['event_cocktail_start'])) {
            $newValue = sanitize_text_field($_POST['event_cocktail_start']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::COCKTAIL_START, $order->get_meta(MetaKeys::COCKTAIL_START, true), $newValue);
            $order->update_meta_data(MetaKeys::COCKTAIL_START, $newValue);
        }
        if (isset($_POST['event_cocktail_end'])) {
            $newValue = sanitize_text_field($_POST['event_cocktail_end']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::COCKTAIL_END, $order->get_meta(MetaKeys::COCKTAIL_END, true), $newValue);
            $order->update_meta_data(MetaKeys::COCKTAIL_END, $newValue);
        }

        // Save event details
        if (isset($_POST['event_type'])) {
            $newValue = sanitize_text_field($_POST['event_type']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::EVENT_TYPE, $order->get_meta(MetaKeys::EVENT_TYPE, true), $newValue);
            $order->update_meta_data(MetaKeys::EVENT_TYPE, $newValue);
        }
        if (isset($_POST['event_how_found_us'])) {
            $newValue = sanitize_text_field($_POST['event_how_found_us']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::HOW_FOUND_US, $order->get_meta(MetaKeys::HOW_FOUND_US, true), $newValue);
            $order->update_meta_data(MetaKeys::HOW_FOUND_US, $newValue);
        }
        if (isset($_POST['event_intolerances'])) {
            $newValue = sanitize_textarea_field((string) $_POST['event_intolerances']);
            FieldChangeTracker::compareAndTrack($orderId, MetaKeys::INTOLERANCES, $order->get_meta(MetaKeys::INTOLERANCES, true), $newValue);
            $order->update_meta_data(MetaKeys::INTOLERANCES, $newValue);
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

    private function calculateTeamArrivalTime(string $paellasTime): string
    {
        if ($paellasTime === '') {
            return '';
        }

        try {
            $time = \DateTime::createFromFormat('H:i', $paellasTime);
            if ($time === false) {
                return '';
            }
            
            $time->modify('-3 hours');
            return $time->format('H:i');
        } catch (\Exception $e) {
            return '';
        }
    }
}
