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
            'home' => __('At home', 'zero-sense'),
            'venue' => __('At venue', 'zero-sense'),
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
            'corporate_meal' => __('Corporate meal', 'zero-sense'),
            'wedding_eve' => __('Wedding eve', 'zero-sense'),
            'wedding_day_after' => __('Wedding day after', 'zero-sense'),
            'birthday' => __('Birthday', 'zero-sense'),
            'friends_family_gathering' => __('Friends and/or family gathering', 'zero-sense'),
            'inauguration' => __('Inauguration', 'zero-sense'),
            'sports_event' => __('Sports event', 'zero-sense'),
            'social_event' => __('Social event', 'zero-sense'),
            'farewell_ceremony' => __('Farewell ceremony', 'zero-sense'),
            'workshop_teambuilding' => __('Workshop / teambuilding', 'zero-sense'),
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
            'previous_customer' => __('Previous customer', 'zero-sense'),
            'friend_recommendation' => __('Friend recommendation', 'zero-sense'),
            'our_hosts' => __('Our hosts', 'zero-sense'),
            'catering_guide' => __('Catering guide', 'zero-sense'),
            'catering_click' => __('Catering click', 'zero-sense'),
            'bodas_net' => __('Bodas.net', 'zero-sense'),
            'other' => __('Other', 'zero-sense'),
        ]);
    }
}
