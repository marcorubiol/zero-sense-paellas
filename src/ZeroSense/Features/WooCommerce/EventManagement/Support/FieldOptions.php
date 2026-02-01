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
            'wedding' => __('Wedding', 'zero-sense'),
            'corporate_meal' => __('Corporate Meal', 'zero-sense'),
            'wedding_eve' => __('Wedding Eve', 'zero-sense'),
            'wedding_day_after' => __('Wedding Day After', 'zero-sense'),
            'birthday' => __('Birthday', 'zero-sense'),
            'friends_family_gathering' => __('Friends and/or Family Gathering', 'zero-sense'),
            'inauguration' => __('Inauguration', 'zero-sense'),
            'sports_event' => __('Sports Event', 'zero-sense'),
            'social_event' => __('Social Event', 'zero-sense'),
            'farewell_ceremony' => __('Farewell Ceremony', 'zero-sense'),
            'workshop_teambuilding' => __('Workshop / Teambuilding', 'zero-sense'),
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
            'instagram' => __('Instagram', 'zero-sense'),
            'facebook' => __('Facebook', 'zero-sense'),
            'previous_customer' => __('Previous Customer', 'zero-sense'),
            'friend_recommendation' => __('Friend Recommendation', 'zero-sense'),
            'our_hosts' => __('Our Hosts', 'zero-sense'),
            'catering_guide' => __('Catering Guide', 'zero-sense'),
            'catering_click' => __('Catering Click', 'zero-sense'),
            'bodas_net' => __('Bodas.net', 'zero-sense'),
            'other' => __('Other', 'zero-sense'),
        ]);
    }
}
