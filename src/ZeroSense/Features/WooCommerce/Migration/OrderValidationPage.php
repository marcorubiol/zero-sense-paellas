<?php
namespace ZeroSense\Features\WooCommerce\Migration;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class OrderValidationPage implements FeatureInterface
{
    private static bool $hooksRegistered = false;

    public function getName(): string
    {
        return __('Order Validation', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Check orders for missing required fields before migration.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_order_validation_enabled';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        add_action('admin_menu', [$this, 'addAdminMenu']);
    }

    public function addAdminMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Order Validation', 'zero-sense'),
            __('Order Validation', 'zero-sense'),
            'manage_options',
            'zs_order_validation',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'zero-sense'));
        }

        $invalidOrders = $this->getInvalidOrders();
        $totalOrders = $this->getTotalOrders();
        $validOrders = $totalOrders - count($invalidOrders);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Order Validation', 'zero-sense'); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Summary:', 'zero-sense'); ?></strong><br>
                    <?php echo sprintf(__('Total orders: %d', 'zero-sense'), $totalOrders); ?><br>
                    <?php echo sprintf(__('Valid orders: %d', 'zero-sense'), $validOrders); ?><br>
                    <?php echo sprintf(__('Orders with missing required fields: %d', 'zero-sense'), count($invalidOrders)); ?>
                </p>
            </div>

            <?php if (empty($invalidOrders)): ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e('All orders have the required fields filled!', 'zero-sense'); ?></p>
                </div>
            <?php else: ?>
                <h2><?php esc_html_e('Orders with Missing Required Fields', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('The following orders are missing required fields and may fail migration or updates:', 'zero-sense'); ?></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order ID', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Customer', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Date', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Status', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Missing Fields', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Action', 'zero-sense'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invalidOrders as $orderData): ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo esc_html($orderData['id']); ?></strong>
                                </td>
                                <td><?php echo esc_html($orderData['customer']); ?></td>
                                <td><?php echo esc_html($orderData['date']); ?></td>
                                <td>
                                    <span class="order-status status-<?php echo esc_attr($orderData['status']); ?>">
                                        <?php echo esc_html(wc_get_order_status_name($orderData['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <ul style="margin: 0; padding-left: 20px;">
                                        <?php foreach ($orderData['missing_fields'] as $field): ?>
                                            <li style="color: #dc3232;"><?php echo esc_html($field); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $orderData['id'] . '&action=edit')); ?>" 
                                       class="button button-small">
                                        <?php esc_html_e('Edit Order', 'zero-sense'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="notice notice-warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php esc_html_e('Important:', 'zero-sense'); ?></strong><br>
                        <?php esc_html_e('Please fill in the missing required fields before performing any migration or bulk updates on these orders.', 'zero-sense'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function getInvalidOrders(): array
    {
        $args = [
            'limit' => -1,
            'return' => 'ids',
        ];

        $orderIds = wc_get_orders($args);
        $invalidOrders = [];

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }

            $missingFields = $this->getMissingRequiredFields($order);
            
            if (!empty($missingFields)) {
                $invalidOrders[] = [
                    'id' => $orderId,
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'date' => $order->get_date_created()->date('Y-m-d H:i'),
                    'status' => $order->get_status(),
                    'missing_fields' => $missingFields,
                ];
            }
        }

        return $invalidOrders;
    }

    private function getTotalOrders(): int
    {
        $args = [
            'limit' => -1,
            'return' => 'ids',
        ];

        return count(wc_get_orders($args));
    }

    private function getMissingRequiredFields(\WC_Order $order): array
    {
        $missing = [];

        $totalGuests = $order->get_meta(MetaKeys::TOTAL_GUESTS, true);
        if (empty($totalGuests) || $totalGuests === '0') {
            $missing[] = __('Total guests', 'zero-sense');
        }

        $adults = $order->get_meta(MetaKeys::ADULTS, true);
        if (empty($adults) || $adults === '0') {
            $missing[] = __('Adults', 'zero-sense');
        }

        $eventDate = $order->get_meta(MetaKeys::EVENT_DATE, true);
        if (empty($eventDate)) {
            $missing[] = __('Event date', 'zero-sense');
        }

        $serviceLocation = $order->get_meta(MetaKeys::SERVICE_LOCATION, true);
        if (empty($serviceLocation) || (int)$serviceLocation <= 0) {
            $missing[] = __('Service location', 'zero-sense');
        }

        $billingFirstName = $order->get_billing_first_name();
        if (empty($billingFirstName)) {
            $missing[] = __('Billing first name', 'zero-sense');
        }

        $billingEmail = $order->get_billing_email();
        if (empty($billingEmail)) {
            $missing[] = __('Billing email', 'zero-sense');
        }

        return $missing;
    }
}
