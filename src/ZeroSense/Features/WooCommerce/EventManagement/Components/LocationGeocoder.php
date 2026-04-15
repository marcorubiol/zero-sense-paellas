<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;

/**
 * Auto-geocodes order shipping addresses and resolves coordinates.
 *
 * Priority chain on woocommerce_process_shop_order_meta:
 *   1  → EventDetailsMetabox::validateRequiredFields
 *   20 → EventDetailsMetabox::save  (saves service_location, event_date, etc.)
 *   20 → OrderOps::save             (saves _shipping_location_link, venue, email)
 *   30 → LocationGeocoder::onOrderSave  ← this class
 *
 * Flow:
 *   1. If _shipping_location_link exists → extract lat/lng from URL
 *   2. If no link → geocode _shipping_address_1 + _shipping_city via Nominatim
 *      → generate Google Maps link → save to _shipping_location_link
 *   3. Store _shipping_latitude / _shipping_longitude as hidden meta
 */
class LocationGeocoder
{
    private const META_LATITUDE  = '_shipping_latitude';
    private const META_LONGITUDE = '_shipping_longitude';
    private const NOMINATIM_URL  = 'https://nominatim.openstreetmap.org/search';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('woocommerce_process_shop_order_meta', [$this, 'onOrderSave'], 30);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'renderCoordinateIndicator']);
    }

    /**
     * Main hook: resolve coordinates after address/link are saved.
     */
    public function onOrderSave(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $locationLink = $order->get_meta('_shipping_location_link', true);
        $locationLink = is_string($locationLink) ? trim($locationLink) : '';

        $coords = null;

        if ($locationLink !== '') {
            // Path 1: extract coords from existing Google Maps link
            $coords = self::extractCoordsFromUrl($locationLink);
        }

        if ($coords === null) {
            // Path 2: geocode from shipping address
            $address = $order->get_shipping_address_1();
            $city    = $order->get_shipping_city();
            $country = $order->get_shipping_country();

            $coords = self::geocodeAddress($address, $city, $country);

            if ($coords !== null) {
                // Generate and save Google Maps link
                $mapsLink = esc_url_raw(sprintf(
                    'https://www.google.com/maps/search/?api=1&query=%s,%s',
                    $coords['lat'],
                    $coords['lng']
                ));
                $order->update_meta_data('_shipping_location_link', $mapsLink);

                // Also set in $_POST so WooCommerce core doesn't overwrite with empty
                $_POST['_shipping_location_link'] = $mapsLink;
            }
        }

        if ($coords !== null) {
            $order->update_meta_data(self::META_LATITUDE, (string) $coords['lat']);
            $order->update_meta_data(self::META_LONGITUDE, (string) $coords['lng']);
        }

        $order->save();
    }

    /**
     * Extract latitude and longitude from a Google Maps URL.
     *
     * Supported formats:
     *   - https://www.google.com/maps/place/.../@39.5696,2.6502,17z/...
     *   - https://www.google.com/maps/search/?api=1&query=39.5696,2.6502
     *   - https://maps.google.com/?q=39.5696,2.6502
     *   - https://www.google.com/maps?q=39.5696,2.6502
     *
     * @param  string $url  The Google Maps URL
     * @return array{lat: float, lng: float}|null  Coordinates or null if not parseable
     */
    public static function extractCoordsFromUrl(string $url): ?array
    {
        // Pattern 1: /@lat,lng in path (most common)
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        // Pattern 2: ?q= or &query= with coordinates
        if (preg_match('/[?&](?:q|query)=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }

        // Pattern 3: !3d (lat) and !4d (lng) in encoded URLs
        if (preg_match('/!3d(-?\d+\.\d+)/', $url, $latMatch) &&
            preg_match('/!4d(-?\d+\.\d+)/', $url, $lngMatch)) {
            return ['lat' => (float) $latMatch[1], 'lng' => (float) $lngMatch[1]];
        }

        return null;
    }

    /**
     * Geocode a shipping address using Nominatim (OpenStreetMap).
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function geocodeAddress(string $address, string $city, string $country): ?array
    {
        $query = trim("$address, $city");
        if ($query === '' || $query === ',') {
            return null;
        }

        $params = [
            'q'      => $query,
            'format' => 'json',
            'limit'  => 1,
        ];

        if ($country !== '') {
            $params['countrycodes'] = strtolower($country);
        }

        $response = wp_remote_get(
            self::NOMINATIM_URL . '?' . http_build_query($params),
            [
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'ZeroSense-WordPress-Plugin/1.0',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body)) {
            return null;
        }

        $lat = isset($body[0]['lat']) ? (float) $body[0]['lat'] : null;
        $lng = isset($body[0]['lon']) ? (float) $body[0]['lon'] : null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Render coordinate indicator below shipping address in admin.
     */
    public function renderCoordinateIndicator(WC_Order $order): void
    {
        $lat = $order->get_meta(self::META_LATITUDE, true);
        $lng = $order->get_meta(self::META_LONGITUDE, true);

        echo '<p class="zs-coords-indicator" style="color: #888; font-size: 12px; margin: 4px 0 0;">';
        if ($lat !== '' && $lng !== '') {
            echo '📍 ' . esc_html($lat) . ', ' . esc_html($lng);
        } else {
            echo esc_html__('No location found', 'zero-sense');
        }
        echo '</p>';
    }
}
