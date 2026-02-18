<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;

class EventPublicAccess implements FeatureInterface
{
    private const META_PUBLIC_TOKEN = 'zs_event_public_token';
    private const QUERY_VAR_TOKEN = 'zs_event_token';

    public function getName(): string
    {
        return __('Event public access', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Provides token-based public access to event sheet pages (no login) and prevents indexing.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'maybeResolveToken'], 1);

        add_action('woocommerce_checkout_create_order', [$this, 'ensureTokenOnCheckout'], 5, 2);
        add_action('woocommerce_new_order', [$this, 'ensureTokenOnNewOrder'], 5, 1);

        add_shortcode('zs_event_public_token', [$this, 'shortcodeToken']);
        add_shortcode('zs_event_public_link', [$this, 'shortcodeLink']);
        add_shortcode('zs_event_require_token', [$this, 'shortcodeRequireToken']);
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function registerQueryVars(array $vars): array
    {
        $vars[] = self::QUERY_VAR_TOKEN;
        return $vars;
    }

    public function ensureTokenOnCheckout(WC_Order $order, $data): void
    {
        $this->ensureOrderHasToken($order);
    }

    public function ensureTokenOnNewOrder($orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof WC_Order) {
            $this->ensureOrderHasToken($order);
        }
    }

    private function ensureOrderHasToken(WC_Order $order): void
    {
        $existing = $order->get_meta(self::META_PUBLIC_TOKEN, true);
        if (is_string($existing) && $existing !== '') {
            return;
        }

        $token = $this->generateToken();
        $order->update_meta_data(self::META_PUBLIC_TOKEN, $token);
        $order->save();
    }

    private function generateToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return wp_generate_password(32, false, false);
        }
    }

    public function maybeResolveToken(): void
    {
        $token = get_query_var(self::QUERY_VAR_TOKEN);
        if (!is_string($token) || $token === '') {
            return;
        }

        $token = sanitize_text_field($token);
        if (!$this->isValidToken($token)) {
            $this->deny();
        }

        $orderId = $this->findOrderIdByToken($token);
        if (!$orderId) {
            $this->deny();
        }

        // Make other components (BricksDynamicTags/EventShortcodes) resolve the order.
        $_GET['order'] = (string) $orderId;
        $GLOBALS['zs_event_order_id'] = (int) $orderId;

        // Prevent indexing
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        add_filter('wp_robots', function(array $robots): array {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
            return $robots;
        });

        nocache_headers();
    }

    private function isValidToken(string $token): bool
    {
        if (strlen($token) < 20 || strlen($token) > 80) {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9]+$/', $token);
    }

    private function findOrderIdByToken(string $token): ?int
    {
        $ids = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => self::META_PUBLIC_TOKEN,
            'meta_value' => $token,
        ]);

        if (is_array($ids) && isset($ids[0])) {
            $id = (int) $ids[0];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function deny(): void
    {
        status_header(404);
        nocache_headers();
        wp_die(__('Invalid event link.', 'zero-sense'), '', ['response' => 404]);
    }

    /**
     * [zs_event_public_token order="123"]
     */
    public function shortcodeToken($atts): string
    {
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $this->ensureOrderHasToken($order);
        $token = $order->get_meta(self::META_PUBLIC_TOKEN, true);
        return is_string($token) ? esc_html($token) : '';
    }

    /**
     * [zs_event_public_link order="123" base_url="https://example.com/event-sheet/"]
     */
    public function shortcodeLink($atts): string
    {
        $atts = is_array($atts) ? $atts : [];
        $orderId = $this->resolveOrderId($atts);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $this->ensureOrderHasToken($order);
        $token = $order->get_meta(self::META_PUBLIC_TOKEN, true);
        if (!is_string($token) || $token === '') {
            return '';
        }

        $base = isset($atts['base_url']) ? esc_url_raw((string) $atts['base_url']) : '';
        if ($base === '') {
            $base = home_url('/');
        }

        $url = add_query_arg([self::QUERY_VAR_TOKEN => $token], $base);
        return esc_url($url);
    }

    /**
     * [zs_event_require_token]
     */
    public function shortcodeRequireToken(): string
    {
        $token = get_query_var(self::QUERY_VAR_TOKEN);
        if (!is_string($token) || $token === '') {
            $this->deny();
        }
        return '';
    }

    private function resolveOrderId($atts): ?int
    {
        $atts = is_array($atts) ? $atts : [];
        $orderId = isset($atts['order']) ? absint($atts['order']) : 0;

        if ($orderId <= 0 && isset($GLOBALS['zs_event_order_id'])) {
            $orderId = (int) $GLOBALS['zs_event_order_id'];
        }

        if ($orderId <= 0 && isset($_GET['order'])) {
            $orderId = absint(wp_unslash((string) $_GET['order']));
        }

        return $orderId > 0 ? $orderId : null;
    }
}
