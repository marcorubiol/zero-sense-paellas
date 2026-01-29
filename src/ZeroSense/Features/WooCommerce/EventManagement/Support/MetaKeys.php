<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Support;

class MetaKeys
{
    // Guest Information
    public const TOTAL_GUESTS = 'zs_event_total_guests';
    public const ADULTS = 'zs_event_adults';
    public const CHILDREN_5_TO_8 = 'zs_event_children_5_to_8';
    public const CHILDREN_0_TO_4 = 'zs_event_children_0_to_4';
    
    // Location Information
    public const SERVICE_LOCATION = 'zs_event_service_location';
    public const ADDRESS = 'zs_event_address';
    public const CITY = 'zs_event_city';
    public const LOCATION_LINK = 'zs_event_location_link';
    
    // Event Timing
    public const EVENT_DATE = 'zs_event_date';
    public const SERVING_TIME = 'zs_event_serving_time';
    public const START_TIME = 'zs_event_start_time';
    
    // Event Details
    public const EVENT_TYPE = 'zs_event_type';
    public const HOW_FOUND_US = 'zs_event_how_found_us';
    
    /**
     * Get all meta keys as array
     */
    public static function getAllKeys(): array
    {
        return [
            'total_guests' => self::TOTAL_GUESTS,
            'adults' => self::ADULTS,
            'children_5_to_8' => self::CHILDREN_5_TO_8,
            'children_0_to_4' => self::CHILDREN_0_TO_4,
            'service_location' => self::SERVICE_LOCATION,
            'address' => self::ADDRESS,
            'city' => self::CITY,
            'location_link' => self::LOCATION_LINK,
            'event_date' => self::EVENT_DATE,
            'serving_time' => self::SERVING_TIME,
            'start_time' => self::START_TIME,
            'event_type' => self::EVENT_TYPE,
            'how_found_us' => self::HOW_FOUND_US,
        ];
    }
    
    /**
     * Get field labels for display
     */
    public static function getLabels(): array
    {
        return [
            self::TOTAL_GUESTS => __('Total Guests', 'zero-sense'),
            self::ADULTS => __('Adults', 'zero-sense'),
            self::CHILDREN_5_TO_8 => __('Children 5-8 years (40%)', 'zero-sense'),
            self::CHILDREN_0_TO_4 => __('Children 0-4 years (FREE)', 'zero-sense'),
            self::SERVICE_LOCATION => __('Service Location', 'zero-sense'),
            self::ADDRESS => __('Event Address', 'zero-sense'),
            self::CITY => __('City', 'zero-sense'),
            self::LOCATION_LINK => __('Location Link', 'zero-sense'),
            self::EVENT_DATE => __('Event Date', 'zero-sense'),
            self::SERVING_TIME => __('Serving Time', 'zero-sense'),
            self::START_TIME => __('Event Start Time', 'zero-sense'),
            self::EVENT_TYPE => __('Event Type', 'zero-sense'),
            self::HOW_FOUND_US => __('How Found Us', 'zero-sense'),
        ];
    }
}
