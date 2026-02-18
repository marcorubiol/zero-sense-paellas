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
    /** @deprecated Use _shipping_address_1 (native WooCommerce shipping field) */
    public const ADDRESS = 'zs_event_address';
    /** @deprecated Use _shipping_city (native WooCommerce shipping field) */
    public const CITY = 'zs_event_city';
    /** @deprecated Use _shipping_location_link (custom shipping field) */
    public const LOCATION_LINK = 'zs_event_location_link';
    
    // Event Timing
    public const EVENT_DATE = 'zs_event_date';
    public const TEAM_ARRIVAL_TIME = 'zs_event_team_arrival_time';
    public const SERVING_TIME = 'zs_event_serving_time';
    public const STARTERS_SERVICE_TIME = 'zs_event_starters_service_time';
    public const START_TIME = 'zs_event_start_time';
    public const OPEN_BAR_START = 'zs_event_open_bar_start';
    public const OPEN_BAR_END = 'zs_event_open_bar_end';
    public const COCKTAIL_START = 'zs_event_cocktail_start';
    public const COCKTAIL_END = 'zs_event_cocktail_end';
    
    // Event Details
    public const EVENT_TYPE = 'zs_event_type';
    public const HOW_FOUND_US = 'zs_event_how_found_us';
    public const INTOLERANCES = 'zs_event_intolerances';
    
    // Email Content
    public const BUDGET_EMAIL_CONTENT = 'zs_budget_email_content';
    public const FINAL_DETAILS_EMAIL_CONTENT = 'zs_final_details_email_content';
    
    // Staff Assignment
    public const EVENT_STAFF = 'zs_event_staff';
    
    // Vehicle Assignment
    public const EVENT_VEHICLES = 'zs_event_vehicles';
    
    // Customer Preferences
    public const MARKETING_CONSENT = 'zs_marketing_consent';
    
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
            'team_arrival_time' => self::TEAM_ARRIVAL_TIME,
            'serving_time' => self::SERVING_TIME,
            'starters_service_time' => self::STARTERS_SERVICE_TIME,
            'start_time' => self::START_TIME,
            'open_bar_start' => self::OPEN_BAR_START,
            'open_bar_end' => self::OPEN_BAR_END,
            'cocktail_start' => self::COCKTAIL_START,
            'cocktail_end' => self::COCKTAIL_END,
            'event_type' => self::EVENT_TYPE,
            'how_found_us' => self::HOW_FOUND_US,
            'intolerances' => self::INTOLERANCES,
            'event_staff'    => self::EVENT_STAFF,
            'event_vehicles' => self::EVENT_VEHICLES,
        ];
    }
    
    /**
     * Get field labels for display
     * 
     * NOTE: Use sentence case for all labels (only first word capitalized)
     * Example: "Event date" not "Event Date"
     */
    public static function getLabels(): array
    {
        return [
            self::TOTAL_GUESTS => __('Total guests', 'zero-sense'),
            self::ADULTS => __('Adults', 'zero-sense'),
            self::CHILDREN_5_TO_8 => __('Children 5-8 years (40%)', 'zero-sense'),
            self::CHILDREN_0_TO_4 => __('Children 0-4 years (free)', 'zero-sense'),
            self::SERVICE_LOCATION => __('Service location', 'zero-sense'),
            self::ADDRESS => __('Event address', 'zero-sense'),
            self::CITY => __('City', 'zero-sense'),
            self::LOCATION_LINK => __('Location link', 'zero-sense'),
            self::EVENT_DATE => __('Event date', 'zero-sense'),
            self::TEAM_ARRIVAL_TIME => __('Team arrival time', 'zero-sense'),
            self::SERVING_TIME => __('Paellas service time', 'zero-sense'),
            self::STARTERS_SERVICE_TIME => __('Starters service time', 'zero-sense'),
            self::START_TIME => __('Event start time', 'zero-sense'),
            self::OPEN_BAR_START => __('Open bar start', 'zero-sense'),
            self::OPEN_BAR_END => __('Open bar end', 'zero-sense'),
            self::COCKTAIL_START => __('Cocktail start', 'zero-sense'),
            self::COCKTAIL_END => __('Cocktail end', 'zero-sense'),
            self::EVENT_TYPE => __('Event type', 'zero-sense'),
            self::HOW_FOUND_US => __('How found us', 'zero-sense'),
            self::INTOLERANCES => __('Allergies / intolerances', 'zero-sense'),
            self::EVENT_STAFF    => __('Event staff', 'zero-sense'),
            self::EVENT_VEHICLES => __('Event vehicles', 'zero-sense'),
        ];
    }
}
