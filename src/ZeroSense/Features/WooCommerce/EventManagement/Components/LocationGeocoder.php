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
 *      → auto-fill empty shipping fields (postcode, state) from geocode response
 *   3. Store _shipping_latitude / _shipping_longitude as hidden meta
 */
class LocationGeocoder
{
    private const META_LATITUDE  = '_shipping_latitude';
    private const META_LONGITUDE = '_shipping_longitude';
    private const NOMINATIM_URL  = 'https://nominatim.openstreetmap.org/search';

    /**
     * Map ISO 3166-2 subdivision codes to WooCommerce ES state codes.
     * Nominatim returns ISO3166-2-lvl4 (e.g. "ES-IB"), WooCommerce uses province codes (e.g. "PM").
     */
    private const ISO_TO_WC_STATE = [
        'ES-C'  => 'C',  'ES-VI' => 'VI', 'ES-AB' => 'AB', 'ES-A'  => 'A',
        'ES-AL' => 'AL', 'ES-O'  => 'O',  'ES-AV' => 'AV', 'ES-BA' => 'BA',
        'ES-PM' => 'PM', 'ES-IB' => 'PM', 'ES-B'  => 'B',  'ES-BU' => 'BU',
        'ES-CC' => 'CC', 'ES-CA' => 'CA', 'ES-S'  => 'S',  'ES-CS' => 'CS',
        'ES-CE' => 'CE', 'ES-CR' => 'CR', 'ES-CO' => 'CO', 'ES-CU' => 'CU',
        'ES-GI' => 'GI', 'ES-GR' => 'GR', 'ES-GU' => 'GU', 'ES-SS' => 'SS',
        'ES-H'  => 'H',  'ES-HU' => 'HU', 'ES-J'  => 'J',  'ES-LO' => 'LO',
        'ES-GC' => 'GC', 'ES-LE' => 'LE', 'ES-L'  => 'L',  'ES-LU' => 'LU',
        'ES-M'  => 'M',  'ES-MA' => 'MA', 'ES-ML' => 'ML', 'ES-MU' => 'MU',
        'ES-NA' => 'NA', 'ES-OR' => 'OR', 'ES-P'  => 'P',  'ES-PO' => 'PO',
        'ES-SA' => 'SA', 'ES-TF' => 'TF', 'ES-SG' => 'SG', 'ES-SE' => 'SE',
        'ES-SO' => 'SO', 'ES-T'  => 'T',  'ES-TE' => 'TE', 'ES-TO' => 'TO',
        'ES-V'  => 'V',  'ES-VA' => 'VA', 'ES-BI' => 'BI', 'ES-ZA' => 'ZA',
        'ES-Z'  => 'Z',
    ];

    /**
     * Fallback: map Nominatim province/state names to WooCommerce codes.
     */
    private const NAME_TO_WC_STATE = [
        'a coruña'       => 'C',   'araba/álava'    => 'VI', 'álava'          => 'VI',
        'albacete'       => 'AB',  'alicante'       => 'A',  'almería'        => 'AL',
        'asturias'       => 'O',   'ávila'          => 'AV', 'badajoz'        => 'BA',
        'baleares'       => 'PM',  'illes balears'  => 'PM', 'islas baleares' => 'PM',
        'mallorca'       => 'PM',  'barcelona'      => 'B',  'burgos'         => 'BU',
        'cáceres'        => 'CC',  'cádiz'          => 'CA', 'cantabria'      => 'S',
        'castellón'      => 'CS',  'ceuta'          => 'CE', 'ciudad real'    => 'CR',
        'córdoba'        => 'CO',  'cuenca'         => 'CU', 'girona'         => 'GI',
        'granada'        => 'GR',  'guadalajara'    => 'GU', 'gipuzkoa'       => 'SS',
        'guipúzcoa'      => 'SS',  'huelva'         => 'H',  'huesca'         => 'HU',
        'jaén'           => 'J',   'la rioja'       => 'LO', 'las palmas'     => 'GC',
        'león'           => 'LE',  'lleida'         => 'L',  'lugo'           => 'LU',
        'madrid'         => 'M',   'málaga'         => 'MA', 'melilla'        => 'ML',
        'murcia'         => 'MU',  'navarra'        => 'NA', 'ourense'        => 'OR',
        'palencia'       => 'P',   'pontevedra'     => 'PO', 'salamanca'      => 'SA',
        'santa cruz de tenerife' => 'TF', 'segovia' => 'SG', 'sevilla'        => 'SE',
        'soria'          => 'SO',  'tarragona'      => 'T',  'teruel'         => 'TE',
        'toledo'         => 'TO',  'valencia'       => 'V',  'valladolid'     => 'VA',
        'vizcaya'        => 'BI',  'bizkaia'        => 'BI', 'zamora'         => 'ZA',
        'zaragoza'       => 'Z',   'eivissa'        => 'PM', 'ibiza'          => 'PM',
        'menorca'        => 'PM',
    ];

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

            $geocodeResult = self::geocodeAddress($address, $city, $country);

            if ($geocodeResult !== null) {
                $coords = ['lat' => $geocodeResult['lat'], 'lng' => $geocodeResult['lng']];

                // Generate and save Google Maps link
                $mapsLink = esc_url_raw(sprintf(
                    'https://www.google.com/maps/search/?api=1&query=%s,%s',
                    $coords['lat'],
                    $coords['lng']
                ));
                $order->update_meta_data('_shipping_location_link', $mapsLink);
                $_POST['_shipping_location_link'] = $mapsLink;

                // Auto-fill empty shipping fields from geocode data
                self::autoFillShippingFields($order, $geocodeResult);
            }
        }

        if ($coords !== null) {
            $order->update_meta_data(self::META_LATITUDE, (string) $coords['lat']);
            $order->update_meta_data(self::META_LONGITUDE, (string) $coords['lng']);
        }

        $order->save();
    }

    /**
     * Fill empty shipping fields from Nominatim address data.
     * Never overwrites existing values.
     */
    private static function autoFillShippingFields(WC_Order $order, array $geo): void
    {
        $addressData = $geo['address'] ?? [];
        if (empty($addressData)) {
            return;
        }

        // Postcode
        if ($order->get_shipping_postcode() === '' && !empty($addressData['postcode'])) {
            $postcode = sanitize_text_field($addressData['postcode']);
            $order->set_shipping_postcode($postcode);
            $_POST['_shipping_postcode'] = $postcode;
        }

        // Country
        if ($order->get_shipping_country() === '' && !empty($addressData['country_code'])) {
            $country = strtoupper(sanitize_text_field($addressData['country_code']));
            $order->set_shipping_country($country);
            $_POST['_shipping_country'] = $country;
        }

        // State — requires mapping to WooCommerce codes
        if ($order->get_shipping_state() === '') {
            $stateCode = self::resolveWcStateCode($addressData);
            if ($stateCode !== null) {
                $order->set_shipping_state($stateCode);
                $_POST['_shipping_state'] = $stateCode;
            }
        }
    }

    /**
     * Resolve a WooCommerce state code from Nominatim address data.
     */
    private static function resolveWcStateCode(array $addressData): ?string
    {
        // Try ISO 3166-2 code first (most reliable)
        $isoCode = $addressData['ISO3166-2-lvl4'] ?? ($addressData['ISO3166-2-lvl6'] ?? '');
        if ($isoCode !== '' && isset(self::ISO_TO_WC_STATE[$isoCode])) {
            return self::ISO_TO_WC_STATE[$isoCode];
        }

        // Fallback: match by province/state/county name
        $candidates = array_filter([
            $addressData['province'] ?? '',
            $addressData['state'] ?? '',
            $addressData['county'] ?? '',
            $addressData['island'] ?? '',
        ]);

        foreach ($candidates as $name) {
            $normalized = mb_strtolower(trim($name));
            if (isset(self::NAME_TO_WC_STATE[$normalized])) {
                return self::NAME_TO_WC_STATE[$normalized];
            }
        }

        return null;
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
     * Returns coords + full address data for auto-filling fields.
     *
     * @return array{lat: float, lng: float, address: array}|null
     */
    private static function geocodeAddress(string $address, string $city, string $country): ?array
    {
        $query = trim("$address, $city");
        if ($query === '' || $query === ',') {
            return null;
        }

        $params = [
            'q'              => $query,
            'format'         => 'json',
            'limit'          => 1,
            'addressdetails' => 1,
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

        $result = $body[0];
        $lat = isset($result['lat']) ? (float) $result['lat'] : null;
        $lng = isset($result['lon']) ? (float) $result['lon'] : null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat'     => $lat,
            'lng'     => $lng,
            'address' => $result['address'] ?? [],
        ];
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
