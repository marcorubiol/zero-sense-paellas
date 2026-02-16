<?php
namespace ZeroSense\Features\WooCommerce\EventManagement;

use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\EventDetailsMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Components\EmailContentMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Components\CustomerPreferencesMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Components\DataExposer;
use ZeroSense\Features\WooCommerce\EventManagement\Components\ServiceAreaAdminColumns;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

/**
 * Bootstrap for Event Management module
 */
class Bootstrap
{
    public function boot(): void
    {
        $this->registerMetaFields();
        
        (new EventDetailsMetabox())->register();
        (new EmailContentMetabox())->register();
        (new CustomerPreferencesMetabox())->register();
        (new DataExposer())->register();
        (new ServiceAreaAdminColumns())->register();
    }

    private function registerMetaFields(): void
    {
        $registry = MetaFieldRegistry::getInstance();
        $labels = MetaKeys::getLabels();

        $registry->register(MetaKeys::TOTAL_GUESTS, [
            'label' => $labels[MetaKeys::TOTAL_GUESTS] ?? 'Total guests',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => ['total_guests', '_event_total_guests'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::ADULTS, [
            'label' => $labels[MetaKeys::ADULTS] ?? 'Adults',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => ['adults', '_event_adults'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::CHILDREN_5_TO_8, [
            'label' => $labels[MetaKeys::CHILDREN_5_TO_8] ?? 'Children 5-8 years (40%)',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => ['children_5_to_8', '_event_children_5_to_8'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::CHILDREN_0_TO_4, [
            'label' => $labels[MetaKeys::CHILDREN_0_TO_4] ?? 'Children 0-4 years (free)',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => ['children_0_to_4', '_event_children_0_to_4'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::SERVICE_LOCATION, [
            'label' => $labels[MetaKeys::SERVICE_LOCATION] ?? 'Service location',
            'type' => 'select',
            'translatable' => true,
            'legacy_keys' => ['event_service_location', '_event_service_location', 'location'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::ADDRESS, [
            'label' => $labels[MetaKeys::ADDRESS] ?? 'Event address',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['event_address', '_event_address'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::CITY, [
            'label' => $labels[MetaKeys::CITY] ?? 'City',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['event_city', '_event_city'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::LOCATION_LINK, [
            'label' => $labels[MetaKeys::LOCATION_LINK] ?? 'Location link',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['location_link', '_event_location_link'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::EVENT_DATE, [
            'label' => $labels[MetaKeys::EVENT_DATE] ?? 'Event date',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => ['event_date', '_event_date'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::TEAM_ARRIVAL_TIME, [
            'label' => $labels[MetaKeys::TEAM_ARRIVAL_TIME] ?? 'Team arrival time',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::SERVING_TIME, [
            'label' => $labels[MetaKeys::SERVING_TIME] ?? 'Service time',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['serving_time', '_event_serving_time'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::START_TIME, [
            'label' => $labels[MetaKeys::START_TIME] ?? 'Event start time',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['event_start_time', '_event_start_time'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::EVENT_TYPE, [
            'label' => $labels[MetaKeys::EVENT_TYPE] ?? 'Event type',
            'type' => 'select',
            'translatable' => true,
            'legacy_keys' => ['event_type', '_event_type'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::HOW_FOUND_US, [
            'label' => $labels[MetaKeys::HOW_FOUND_US] ?? 'How found us',
            'type' => 'select',
            'translatable' => true,
            'legacy_keys' => ['how_found_us', '_event_how_found_us'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::INTOLERANCES, [
            'label' => $labels[MetaKeys::INTOLERANCES] ?? 'Allergies / intolerances',
            'type' => 'textarea',
            'translatable' => false,
            'legacy_keys' => ['intolerances'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::BUDGET_EMAIL_CONTENT, [
            'label' => 'Budget email content',
            'type' => 'textarea',
            'translatable' => false,
            'legacy_keys' => ['budget_email_content'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::FINAL_DETAILS_EMAIL_CONTENT, [
            'label' => 'Final details email content',
            'type' => 'textarea',
            'translatable' => false,
            'legacy_keys' => ['final_details_email_content'],
            'feature' => 'EventManagement',
        ]);

        $registry->register(MetaKeys::MARKETING_CONSENT, [
            'label' => 'Marketing consent',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => ['marketing_consent_checkbox'],
            'feature' => 'EventManagement',
        ]);

        $registry->register('_shipping_email', [
            'label' => 'Shipping email',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'EventManagement',
        ]);

        $registry->register('_shipping_location_link', [
            'label' => 'Shipping location link',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'EventManagement',
        ]);
    }
}
