<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Support;

class FieldOptions
{
    /**
     * Get service location options
     */
    public static function getServiceLocationOptions(): array
    {
        return apply_filters('zs_event_service_location_options', [
            'home' => __('At Home', 'zero-sense'),
            'venue' => __('At Venue', 'zero-sense'),
            'outdoor' => __('Outdoor', 'zero-sense'),
            'other' => __('Other', 'zero-sense'),
        ]);
    }
    
    /**
     * Get event type options
     */
    public static function getEventTypeOptions(): array
    {
        return apply_filters('zs_event_type_options', [
            'birthday' => __('Birthday', 'zero-sense'),
            'wedding' => __('Wedding', 'zero-sense'),
            'corporate' => __('Corporate Event', 'zero-sense'),
            'family' => __('Family Gathering', 'zero-sense'),
            'anniversary' => __('Anniversary', 'zero-sense'),
            'other' => __('Other', 'zero-sense'),
        ]);
    }
    
    /**
     * Get how found us options
     */
    public static function getHowFoundUsOptions(): array
    {
        return apply_filters('zs_event_how_found_us_options', [
            'google' => __('Google', 'zero-sense'),
            'social_media' => __('Social Media', 'zero-sense'),
            'friend' => __('Friend/Family Recommendation', 'zero-sense'),
            'previous_customer' => __('Previous Customer', 'zero-sense'),
            'advertisement' => __('Advertisement', 'zero-sense'),
            'other' => __('Other', 'zero-sense'),
        ]);
    }
}
