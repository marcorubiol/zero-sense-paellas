<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

/**
 * Exposes event data to Flowmattic
 * Note: Bricks integration is handled by BricksDynamicTags class via MetaFieldRegistry
 */
class DataExposer
{
    public function register(): void
    {
        // Expose to Flowmattic dynamic tags
        add_filter('flowmattic_dynamic_tags', [$this, 'registerFlowmatticTags']);
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
                'zs_service_location' => __('ZS Service Location', 'zero-sense'),
                'zs_service_location_label' => __('ZS Service Location (Label)', 'zero-sense'),
                'address' => __('Event Address', 'zero-sense'),
                'city' => __('City', 'zero-sense'),
                'location_link' => __('Location Link', 'zero-sense'),
                'event_date' => __('Event Date', 'zero-sense'),
                'event_date_formatted' => __('Event Date (Formatted)', 'zero-sense'),
                'serving_time' => __('Serving Time', 'zero-sense'),
                'start_time' => __('Event Start Time', 'zero-sense'),
                'event_type' => __('Event Type', 'zero-sense'),
                'event_type_label' => __('Event Type (Label)', 'zero-sense'),
                'how_found_us' => __('How did you find us?', 'zero-sense'),
                'how_found_us_label' => __('How did you find us? (Label)', 'zero-sense'),
                'budget_email_content' => __('Budget Email Content', 'zero-sense'),
                'final_details_email_content' => __('Final Details Email Content', 'zero-sense'),
                'marketing_consent' => __('Marketing Consent', 'zero-sense'),
                'staff_cap_de_bolo' => __('Staff: Cap de Bolo', 'zero-sense'),
                'staff_cuiner_a' => __('Staff: Cuiner/a', 'zero-sense'),
                'staff_ajudant_a_de_cuina' => __('Staff: Ajudant/a de cuina', 'zero-sense'),
                'staff_cambrer_a_barra' => __('Staff: Cambrer/a - Barra', 'zero-sense'),
                'staff_cockteler_a' => __('Staff: Cockteler/a', 'zero-sense'),
                'staff_tallador_a_de_pernil' => __('Staff: Tallador/a de pernil', 'zero-sense'),
                'staff_all_formatted' => __('Staff: All (Formatted)', 'zero-sense'),
                'staff_kitchen_names' => __('Staff: Kitchen Team Names (Cap de Bolo, Cuiner/a, Ajudant/a de cuina)', 'zero-sense'),
                'google_calendar_event_id' => __('Google Calendar Event ID', 'zero-sense'),
                'event_reserved' => __('Event Reserved (yes/no)', 'zero-sense'),
                'google_calendar_event_url' => __('Google Calendar Event URL', 'zero-sense'),
                'zs_calendar_notes' => __('Calendar Notes', 'zero-sense'),
            ],
        ];
        
        return $tags;
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
            'zs_service_location' => $serviceLocation,
            'zs_service_location_label' => $serviceLocationLabel,
            'address' => $order->get_shipping_address_1() !== '' ? $order->get_shipping_address_1() : $order->get_meta(MetaKeys::ADDRESS),
            'city' => $order->get_shipping_city() !== '' ? $order->get_shipping_city() : $order->get_meta(MetaKeys::CITY),
            'location_link' => (($ll = $order->get_meta('_shipping_location_link', true)) && is_string($ll) && $ll !== '') ? $ll : $order->get_meta(MetaKeys::LOCATION_LINK),
            'event_date' => $eventDate,
            'event_date_formatted' => (is_string($eventDate) && $eventDate !== '')
                ? date_i18n(get_option('date_format'), strtotime($eventDate))
                : '',
            'serving_time' => $order->get_meta(MetaKeys::SERVING_TIME),
            'start_time' => $order->get_meta(MetaKeys::START_TIME),
            'event_type' => $eventType,
            'event_type_label' => $eventTypeOptions[$eventType] ?? '',
            'how_found_us' => $howFoundUs,
            'how_found_us_label' => $howFoundUsOptions[$howFoundUs] ?? '',
            'budget_email_content' => $order->get_meta(MetaKeys::BUDGET_EMAIL_CONTENT),
            'final_details_email_content' => $order->get_meta(MetaKeys::FINAL_DETAILS_EMAIL_CONTENT),
            'marketing_consent' => $order->get_meta(MetaKeys::MARKETING_CONSENT) === '1' ? 'yes' : 'no',
            'staff_cap_de_bolo' => self::getStaffByRole($order, 'cap-de-bolo'),
            'staff_cuiner_a' => self::getStaffByRole($order, 'cuiner-a'),
            'staff_ajudant_a_de_cuina' => self::getStaffByRole($order, 'ajudant-a-de-cuina'),
            'staff_cambrer_a_barra' => self::getStaffByRole($order, 'cambrer-a-barra'),
            'staff_cockteler_a' => self::getStaffByRole($order, 'cockteler-a'),
            'staff_tallador_a_de_pernil' => self::getStaffByRole($order, 'tallador-a-de-pernil'),
            'staff_all_formatted' => self::getAllStaffFormatted($order),
            'staff_all_names' => self::getAllStaffNames($order),
            'staff_kitchen_names' => self::getKitchenStaffNames($order),
            'vehicles'            => self::getVehiclesFormatted($order),
            'event_reserved' => $order->get_meta(MetaKeys::EVENT_RESERVED, true),
            'google_calendar_event_id' => $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true),
            'calendar_notes' => $order->get_meta(MetaKeys::CALENDAR_NOTES, true),
        ];
    }

    /**
     * Get staff members by role for an order
     */
    private static function getStaffByRole(WC_Order $order, string $role): string
    {
        $staffAssignments = $order->get_meta(MetaKeys::EVENT_STAFF, true);
        if (!is_array($staffAssignments)) {
            return '';
        }

        $staffNames = [];
        foreach ($staffAssignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['role'], $assignment['staff_id'])) {
                continue;
            }
            
            if ($assignment['role'] === $role) {
                $staffId = (int) $assignment['staff_id'];
                $staffPost = get_post($staffId);
                if ($staffPost) {
                    $name = $staffPost->post_title;
                    $email = get_post_meta($staffId, 'zs_staff_email', true);
                    $phone = get_post_meta($staffId, 'zs_staff_phone', true);
                    
                    $details = $name;
                    if ($email || $phone) {
                        $details .= ' (';
                        if ($email) {
                            $details .= $email;
                        }
                        if ($email && $phone) {
                            $details .= ', ';
                        }
                        if ($phone) {
                            $details .= $phone;
                        }
                        $details .= ')';
                    }
                    
                    $staffNames[] = $details;
                }
            }
        }

        return implode(', ', $staffNames);
    }

    /**
     * Get vehicles assigned to an order
     */
    private static function getVehiclesFormatted(WC_Order $order): string
    {
        $vehicleIds = $order->get_meta(MetaKeys::EVENT_VEHICLES, true);
        if (!is_array($vehicleIds) || empty($vehicleIds)) {
            return '';
        }

        $parts = [];
        foreach ($vehicleIds as $vehicleId) {
            $post = get_post((int) $vehicleId);
            if (!$post) {
                continue;
            }
            $plate = get_post_meta($post->ID, 'zs_vehicle_plate', true);
            $parts[] = $post->post_title . ($plate ? ' (' . $plate . ')' : '');
        }

        return implode(', ', $parts);
    }

    /**
     * Get kitchen staff names only (Jefe de voluntarios, Cocineros, Ayudantes)
     * Returns only the names without email or phone
     */
    private static function getKitchenStaffNames(WC_Order $order): string
    {
        $staffAssignments = $order->get_meta(MetaKeys::EVENT_STAFF, true);
        if (!is_array($staffAssignments)) {
            return '';
        }

        $kitchenRoles = ['cap-de-bolo', 'cuiner-a', 'ajudant-a-de-cuina'];
        $staffNames = [];

        foreach ($staffAssignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['role'], $assignment['staff_id'])) {
                continue;
            }
            
            if (in_array($assignment['role'], $kitchenRoles, true)) {
                $staffId = (int) $assignment['staff_id'];
                $staffPost = get_post($staffId);
                if ($staffPost) {
                    $staffNames[] = $staffPost->post_title;
                }
            }
        }

        return implode(', ', $staffNames);
    }

    /**
     * Get all staff as "Role: Name" lines (no contact info) for calendar titles
     */
    private static function getAllStaffNames(WC_Order $order): string
    {
        $staffAssignments = $order->get_meta(MetaKeys::EVENT_STAFF, true);
        if (!is_array($staffAssignments)) {
            return '';
        }

        $roles = [
            'cap-de-bolo' => __('Cap de Bolo', 'zero-sense'),
            'cuiner-a' => __('Cuiner/a', 'zero-sense'),
            'ajudant-a-de-cuina' => __('Ajudant/a de cuina', 'zero-sense'),
            'cambrer-a-barra' => __('Cambrer/a - Barra', 'zero-sense'),
            'cockteler-a' => __('Cockteler/a', 'zero-sense'),
            'tallador-a-de-pernil' => __('Tallador/a de pernil', 'zero-sense'),
        ];

        $grouped = [];
        foreach ($staffAssignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['role'], $assignment['staff_id'])) {
                continue;
            }
            $roleSlug = $assignment['role'];
            $staffPost = get_post((int) $assignment['staff_id']);
            if ($staffPost && isset($roles[$roleSlug])) {
                $grouped[$roleSlug][] = $staffPost->post_title;
            }
        }

        $output = [];
        foreach ($roles as $slug => $label) {
            if (!empty($grouped[$slug])) {
                $output[] = $label . ': ' . implode(', ', $grouped[$slug]);
            }
        }

        return implode(' | ', $output);
    }

    /**
     * Get all staff formatted for display (with contact info)
     */
    private static function getAllStaffFormatted(WC_Order $order): string
    {
        $roles = [
            'cap-de-bolo' => __('Cap de Bolo', 'zero-sense'),
            'cuiner-a' => __('Cuiner/a', 'zero-sense'),
            'ajudant-a-de-cuina' => __('Ajudant/a de cuina', 'zero-sense'),
            'cambrer-a-barra' => __('Cambrer/a - Barra', 'zero-sense'),
            'cockteler-a' => __('Cockteler/a', 'zero-sense'),
            'tallador-a-de-pernil' => __('Tallador/a de pernil', 'zero-sense'),
        ];

        $output = [];
        foreach ($roles as $roleSlug => $roleName) {
            $staff = self::getStaffByRole($order, $roleSlug);
            if ($staff !== '') {
                $output[] = $roleName . ': ' . $staff;
            }
        }

        return implode("\n", $output);
    }

}
