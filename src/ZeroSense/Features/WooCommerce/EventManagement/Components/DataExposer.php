<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

/**
 * Exposes event data to Flowmattic and Bricks
 */
class DataExposer
{
    public function register(): void
    {
        // Expose to Flowmattic dynamic tags
        add_filter('flowmattic_dynamic_tags', [$this, 'registerFlowmatticTags']);
        
        // Expose to Bricks dynamic data
        add_filter('bricks/dynamic_tags_list', [$this, 'registerBricksTags']);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'renderBricksTag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'renderBricksContent'], 10, 3);
    }

    /**
     * Register tags for Flowmattic
     */
    public function registerFlowmatticTags(array $tags): array
    {
        $tags['event_data'] = [
            'label' => __('Event Data', 'zero-sense'),
            'tags' => [
                'total_guests' => __('Total Guests', 'zero-sense'),
                'adults' => __('Adults', 'zero-sense'),
                'children_5_to_8' => __('Children 5-8 years', 'zero-sense'),
                'children_0_to_4' => __('Children 0-4 years', 'zero-sense'),
                'service_location' => __('Service Location', 'zero-sense'),
                'service_location_label' => __('Service Location (Label)', 'zero-sense'),
                'address' => __('Event Address', 'zero-sense'),
                'city' => __('City', 'zero-sense'),
                'location_link' => __('Location Link', 'zero-sense'),
                'event_date' => __('Event Date', 'zero-sense'),
                'event_date_formatted' => __('Event Date (Formatted)', 'zero-sense'),
                'serving_time' => __('Serving Time', 'zero-sense'),
                'start_time' => __('Event Start Time', 'zero-sense'),
                'event_type' => __('Event Type', 'zero-sense'),
                'event_type_label' => __('Event Type (Label)', 'zero-sense'),
                'how_found_us' => __('How Found Us', 'zero-sense'),
                'how_found_us_label' => __('How Found Us (Label)', 'zero-sense'),
            ],
        ];
        
        return $tags;
    }

    /**
     * Register tags for Bricks
     */
    public function registerBricksTags(array $tags): array
    {
        $tags[] = [
            'name' => '{event_total_guests}',
            'label' => __('Event: Total Guests', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_adults}',
            'label' => __('Event: Adults', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_children_5_to_8}',
            'label' => __('Event: Children 5-8 years', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_children_0_to_4}',
            'label' => __('Event: Children 0-4 years', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_service_location}',
            'label' => __('Event: Service Location', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_address}',
            'label' => __('Event: Address', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_city}',
            'label' => __('Event: City', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_location_link}',
            'label' => __('Event: Location Link', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_date}',
            'label' => __('Event: Date', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_serving_time}',
            'label' => __('Event: Serving Time', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_start_time}',
            'label' => __('Event: Start Time', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_type}',
            'label' => __('Event: Type', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        $tags[] = [
            'name' => '{event_how_found_us}',
            'label' => __('Event: How Found Us', 'zero-sense'),
            'group' => __('Event Data', 'zero-sense'),
        ];
        
        return $tags;
    }

    /**
     * Render Bricks tag
     */
    public function renderBricksTag($tag, $post, $context = 'text')
    {
        // Get order from post or context
        $order = null;
        
        if ($post instanceof \WP_Post && $post->post_type === 'shop_order') {
            $order = wc_get_order($post->ID);
        } elseif (is_numeric($post)) {
            $order = wc_get_order($post);
        } elseif (isset($context['order_id'])) {
            $order = wc_get_order($context['order_id']);
        }
        
        if (!$order instanceof WC_Order) {
            return $tag;
        }
        
        return $this->getEventDataValue($tag, $order);
    }

    /**
     * Render Bricks content
     */
    public function renderBricksContent($content, $post, $context = 'text')
    {
        // Check if content contains event tags
        if (strpos($content, '{event_') === false) {
            return $content;
        }
        
        // Get order
        $order = null;
        
        if ($post instanceof \WP_Post && $post->post_type === 'shop_order') {
            $order = wc_get_order($post->ID);
        } elseif (is_numeric($post)) {
            $order = wc_get_order($post);
        } elseif (isset($context['order_id'])) {
            $order = wc_get_order($context['order_id']);
        }
        
        if (!$order instanceof WC_Order) {
            return $content;
        }
        
        // Replace all event tags
        $tags = [
            '{event_total_guests}',
            '{event_adults}',
            '{event_children_5_to_8}',
            '{event_children_0_to_4}',
            '{event_service_location}',
            '{event_address}',
            '{event_city}',
            '{event_location_link}',
            '{event_date}',
            '{event_serving_time}',
            '{event_start_time}',
            '{event_type}',
            '{event_how_found_us}',
        ];
        
        foreach ($tags as $tag) {
            if (strpos($content, $tag) !== false) {
                $value = $this->getEventDataValue($tag, $order);
                $content = str_replace($tag, $value, $content);
            }
        }
        
        return $content;
    }

    /**
     * Get event data value by tag
     */
    private function getEventDataValue(string $tag, WC_Order $order): string
    {
        $tag = str_replace(['{', '}'], '', $tag);
        
        switch ($tag) {
            case 'event_total_guests':
                return (string) $order->get_meta(MetaKeys::TOTAL_GUESTS);
                
            case 'event_adults':
                return (string) $order->get_meta(MetaKeys::ADULTS);
                
            case 'event_children_5_to_8':
                return (string) $order->get_meta(MetaKeys::CHILDREN_5_TO_8);
                
            case 'event_children_0_to_4':
                return (string) $order->get_meta(MetaKeys::CHILDREN_0_TO_4);
                
            case 'event_service_location':
            case 'service_location':
            case 'service_location_label':
                return $this->getServiceAreaNameForOrder($order);
                
            case 'event_address':
                return (string) $order->get_meta(MetaKeys::ADDRESS);
                
            case 'event_city':
                return (string) $order->get_meta(MetaKeys::CITY);
                
            case 'event_location_link':
                return (string) $order->get_meta(MetaKeys::LOCATION_LINK);
                
            case 'event_date':
                $date = $order->get_meta(MetaKeys::EVENT_DATE);
                if ($date) {
                    if (is_numeric($date) && (int) $date == $date) {
                        return date_i18n(get_option('date_format'), (int) $date);
                    }
                    return date_i18n(get_option('date_format'), strtotime((string) $date));
                }
                return '';
                
            case 'event_serving_time':
                return (string) $order->get_meta(MetaKeys::SERVING_TIME);
                
            case 'event_start_time':
                return (string) $order->get_meta(MetaKeys::START_TIME);
                
            case 'event_type':
                $value = $order->get_meta(MetaKeys::EVENT_TYPE);
                $options = FieldOptions::getEventTypeOptions();
                return $options[$value] ?? $value;
                
            case 'event_how_found_us':
                $value = $order->get_meta(MetaKeys::HOW_FOUND_US);
                $options = FieldOptions::getHowFoundUsOptions();
                return $options[$value] ?? $value;
                
            default:
                return '';
        }
    }

    private function getServiceAreaNameForOrder(WC_Order $order): string
    {
        $canonicalId = $order->get_meta(MetaKeys::SERVICE_LOCATION);
        $canonicalId = is_numeric($canonicalId) ? (int) $canonicalId : 0;
        if ($canonicalId <= 0) {
            return '';
        }

        $termId = $canonicalId;
        if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
            $lang = apply_filters('wpml_current_language', null);
            if (!is_string($lang) || $lang === '') {
                $lang = $order->get_meta('wpml_language', true);
            }
            if (is_string($lang) && $lang !== '') {
                $translatedId = apply_filters('wpml_object_id', $canonicalId, 'service-area', true, $lang);
                if ($translatedId) {
                    $termId = (int) $translatedId;
                }
            }
        }

        $term = get_term($termId, 'service-area');
        if ($term instanceof \WP_Term) {
            return $term->name;
        }

        return '';
    }

    /**
     * Get all event data for an order (useful for Flowmattic)
     */
    public static function getOrderEventData(WC_Order $order): array
    {
        $serviceLocation = $order->get_meta(MetaKeys::SERVICE_LOCATION);
        $eventType = $order->get_meta(MetaKeys::EVENT_TYPE);
        $howFoundUs = $order->get_meta(MetaKeys::HOW_FOUND_US);
        $eventDate = $order->get_meta(MetaKeys::EVENT_DATE);
        
        $eventTypeOptions = FieldOptions::getEventTypeOptions();
        $howFoundUsOptions = FieldOptions::getHowFoundUsOptions();
        
        $canonicalId = is_numeric($serviceLocation) ? (int) $serviceLocation : 0;
        $termId = $canonicalId;
        if ($canonicalId > 0 && defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
            $lang = $order->get_meta('wpml_language', true);
            if (is_string($lang) && $lang !== '') {
                $translatedId = apply_filters('wpml_object_id', $canonicalId, 'service-area', true, $lang);
                if ($translatedId) {
                    $termId = (int) $translatedId;
                }
            }
        }
        $term = $termId > 0 ? get_term($termId, 'service-area') : null;
        $serviceLocationLabel = $term instanceof \WP_Term ? $term->name : '';
        
        return [
            'total_guests' => $order->get_meta(MetaKeys::TOTAL_GUESTS),
            'adults' => $order->get_meta(MetaKeys::ADULTS),
            'children_5_to_8' => $order->get_meta(MetaKeys::CHILDREN_5_TO_8),
            'children_0_to_4' => $order->get_meta(MetaKeys::CHILDREN_0_TO_4),
            'service_location' => $serviceLocation,
            'service_location_label' => $serviceLocationLabel,
            'address' => $order->get_meta(MetaKeys::ADDRESS),
            'city' => $order->get_meta(MetaKeys::CITY),
            'location_link' => $order->get_meta(MetaKeys::LOCATION_LINK),
            'event_date' => $eventDate,
            'event_date_formatted' => $eventDate
                ? (is_numeric($eventDate) && (int) $eventDate == $eventDate
                    ? date_i18n(get_option('date_format'), (int) $eventDate)
                    : date_i18n(get_option('date_format'), strtotime((string) $eventDate)))
                : '',
            'serving_time' => $order->get_meta(MetaKeys::SERVING_TIME),
            'start_time' => $order->get_meta(MetaKeys::START_TIME),
            'event_type' => $eventType,
            'event_type_label' => $eventTypeOptions[$eventType] ?? '',
            'how_found_us' => $howFoundUs,
            'how_found_us_label' => $howFoundUsOptions[$howFoundUs] ?? '',
        ];
    }
}
