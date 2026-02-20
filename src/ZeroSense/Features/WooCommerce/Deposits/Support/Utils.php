<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Support;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;

/**
 * Utility helpers for WooCommerce Deposits.
 *
 * Ported from legacy `zs-wd-utils.php` while adopting PSR-4 naming and
 * namespacing conventions. The static API mirrors the previous global
 * functions so existing logic can be migrated gradually.
 */
class Utils
{
    /**
     * Request-level cache for deposit info calculations keyed by order ID.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $depositInfoCache = [];

    /**
     * Save deposit information to order meta.
     *
     * @param int|WC_Order $orderOrId
     */
    public static function saveDepositInfo($orderOrId, array $data): bool
    {
        if (empty($orderOrId) || !is_array($data)) {
            self::logWarning('Deposits Meta Save: Invalid order_id or data provided.');
            return false;
        }

        $order = $orderOrId instanceof WC_Order ? $orderOrId : wc_get_order($orderOrId);
        if (!$order) {
            self::logError('Deposits Meta Save: Could not retrieve order object.', [
                'order_id' => is_scalar($orderOrId) ? $orderOrId : 'N/A',
            ]);
            return false;
        }

        $orderId = $order->get_id();

        if (isset($data['deposit_amount']) && is_numeric($data['deposit_amount'])) {
            $orderTotal = (float) $order->get_total();
            $depositAmount = min((float) $data['deposit_amount'], $orderTotal);
            $data['deposit_amount'] = $depositAmount;
            $data['remaining_amount'] = $orderTotal - $depositAmount;
            $data['balance_amount'] = $data['remaining_amount'];
        }

        $metaKeys = [
            'deposit_amount' => MetaKeys::DEPOSIT_AMOUNT,
            'remaining_amount' => MetaKeys::REMAINING_AMOUNT,
            'balance_amount' => MetaKeys::BALANCE_AMOUNT,
        ];

        MetaKeys::update($order, MetaKeys::HAS_DEPOSIT, 'yes');

        $updated = false;

        foreach ($metaKeys as $dataKey => $metaKey) {
            if (!array_key_exists($dataKey, $data)) {
                continue;
            }

            $newValue = $data[$dataKey];
            $oldValue = MetaKeys::get($order, $metaKey);
            $processed = '';

            if ($newValue !== '' && $newValue !== null && is_numeric($newValue)) {
                $processed = (string) floatval($newValue);
            }

            if ((string) $processed === (string) $oldValue) {
                continue;
            }

            if ($processed === '') {
                if ($order->meta_exists($metaKey)) {
                    $order->delete_meta_data($metaKey);
                    self::logDebug('Woo Deposits Meta Save: Deleting meta.', [
                        'order_id' => $orderId,
                        'meta_key' => $metaKey,
                    ]);
                    $updated = true;
                }
                continue;
            }

            MetaKeys::update($order, $metaKey, $processed);
            self::logDebug('Woo Deposits Meta Save: Updating meta.', [
                'order_id' => $orderId,
                'meta_key' => $metaKey,
                'old_value' => $oldValue,
                'new_value' => $processed,
            ]);
            $updated = true;
        }

        if ($updated) {
            $order->save_meta_data();
            self::logInfo('Woo Deposits Meta Save: Meta data persisted.', [
                'order_id' => $orderId,
                'data' => $data,
            ]);

            self::invalidateDepositInfoCache($orderId);
        }

        return $updated;
    }

    /**
     * Force recalculation of deposit info from percentage (ignores existing deposit).
     *
     * @param int|WC_Order $orderOrId
     * @return array Deposit info with recalculated amounts
     */
    public static function recalculateDepositInfo($orderOrId): array
    {
        if (!$orderOrId) {
            return [];
        }

        $order = $orderOrId instanceof WC_Order ? $orderOrId : wc_get_order($orderOrId);
        if (!$order) {
            return [];
        }

        $orderId = $order->get_id();
        $orderTotal = (float) $order->get_total();
        $orderCurrency = $order->get_currency();

        // Get percentage from order meta or settings
        $orderPercentage = MetaKeys::get($order, MetaKeys::DEPOSIT_PERCENTAGE);
        $settingPercentage = (float) get_option(Settings::OPTION_DEPOSIT_PERCENTAGE, 30);
        $depositPercentage = is_numeric($orderPercentage) ? (float) $orderPercentage : $settingPercentage;
        $depositPercentage = min(max($depositPercentage, 0), 100);

        // Calculate from percentage
        $depositAmount = round(($orderTotal * $depositPercentage) / 100, wc_get_price_decimals());
        $remainingAmount = $orderTotal - $depositAmount;
        $balanceAmount = max(0, $remainingAmount);

        $depositAmount = max(0, $depositAmount);
        $remainingAmount = max(0, $remainingAmount);

        $priceArgs = ['currency' => $orderCurrency];

        $currencySettings = [
            'symbol' => get_woocommerce_currency_symbol($orderCurrency),
            'position' => get_option('woocommerce_currency_pos'),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimals' => wc_get_price_decimals(),
        ];

        return [
            'order_id' => $orderId,
            'order_total' => $orderTotal,
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $remainingAmount,
            'balance_amount' => $balanceAmount,
            'deposit_display' => wc_price($depositAmount, $priceArgs),
            'remaining_display' => wc_price($remainingAmount, $priceArgs),
            'full_display' => wc_price($orderTotal, $priceArgs),
            'deposit_percentage' => $depositPercentage,
            'deposit_percentage_display' => rtrim(rtrim(number_format($depositPercentage, 2, '.', ''), '0'), '.'),
            'has_deposit' => true,
            'order_currency' => $orderCurrency,
            'currency_settings' => $currencySettings,
        ];
    }

    /**
     * Retrieve and calculate deposit information for an order.
     *
     * @param int|WC_Order $orderOrId
     */
    public static function getDepositInfo($orderOrId, ?string $paymentChoice = null): array
    {
        if (!$orderOrId) {
            return [];
        }

        $order = $orderOrId instanceof WC_Order ? $orderOrId : wc_get_order($orderOrId);
        if (!$order) {
            return [];
        }

        $orderId = $order->get_id();
        $baseInfo = self::$depositInfoCache[$orderId] ?? null;

        if ($baseInfo === null) {
            $orderTotal = (float) $order->get_total();
            $orderCurrency = $order->get_currency();

            $depositAmount = 0.0;
            $remainingAmount = $orderTotal;
            $depositPercentage = 0.0;
            $hasDeposit = false;

            $settingPercentage = (float) get_option(Settings::OPTION_DEPOSIT_PERCENTAGE, 30);
            $orderPercentage = MetaKeys::get($order, MetaKeys::DEPOSIT_PERCENTAGE);
            $depositPercentage = is_numeric($orderPercentage) ? (float) $orderPercentage : $settingPercentage;

            $existingDeposit = (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
            $existingRemaining = (float) MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT);
            $isManualOverride = MetaKeys::isEnabled($order, MetaKeys::IS_MANUAL_OVERRIDE);
            $isDepositStatus = $order->has_status('deposit-paid');

            if ($existingDeposit > 0 || $isDepositStatus) {
                $hasDeposit = true;
                $depositAmount = $existingDeposit;
                
                // If manual override is active, deposit is fixed but remaining always reflects current total
                if ($isManualOverride) {
                    $remainingAmount = $orderTotal - $depositAmount;
                } else {
                    $remainingAmount = ($existingRemaining > 0 && !$isDepositStatus)
                        ? $existingRemaining
                        : ($orderTotal - $depositAmount);
                }
            } else {
                $depositAmount = round(($orderTotal * $depositPercentage) / 100, wc_get_price_decimals());
                $remainingAmount = $orderTotal - $depositAmount;
                $hasDeposit = true;
            }

            $depositAmount = max(0, $depositAmount);
            $remainingAmount = max(0, $remainingAmount);

            $priceArgs = ['currency' => $orderCurrency];

            $currencySettings = [
                'symbol' => get_woocommerce_currency_symbol($orderCurrency),
                'position' => get_option('woocommerce_currency_pos'),
                'decimal_separator' => wc_get_price_decimal_separator(),
                'thousand_separator' => wc_get_price_thousand_separator(),
                'decimals' => wc_get_price_decimals(),
            ];

            $actualPercentage = ($orderTotal > 0)
                ? round(($depositAmount / $orderTotal) * 100, 2)
                : $depositPercentage;

            $baseInfo = [
                'order_id' => $orderId,
                'order_total' => $orderTotal,
                'deposit_amount' => $depositAmount,
                'remaining_amount' => $remainingAmount,
                'deposit_display' => wc_price($depositAmount, $priceArgs),
                'remaining_display' => wc_price($remainingAmount, $priceArgs),
                'full_display' => wc_price($orderTotal, $priceArgs),
                'deposit_percentage' => $actualPercentage,
                'deposit_percentage_display' => rtrim(rtrim(number_format($actualPercentage, 2, '.', ''), '0'), '.'),
                'has_deposit' => $hasDeposit,
                'order_currency' => $orderCurrency,
                'currency_settings' => $currencySettings,
            ];

            self::$depositInfoCache[$orderId] = $baseInfo;
        }

        $result = $baseInfo;
        $result['chosen_option'] = self::resolvePaymentChoice($orderId, $paymentChoice);

        return $result;
    }

    /**
     * Format a price without spaces between symbol and amount.
     */
    public static function formatPriceNoSpace($amount, array $args = []): string
    {
        if (!is_numeric($amount)) {
            return '';
        }

        if ($amount == 0 && isset($args['free_text'])) {
            return $args['free_text'];
        }

        $currency = $args['currency'] ?? get_woocommerce_currency();
        $decimalSep = wc_get_price_decimal_separator();
        $thousandSep = wc_get_price_thousand_separator();
        $decimals = $args['decimals'] ?? wc_get_price_decimals();
        $currencyPosition = get_option('woocommerce_currency_pos');
        $symbol = get_woocommerce_currency_symbol($currency);

        $formattedNumber = number_format((float) $amount, $decimals, $decimalSep, $thousandSep);

        switch ($currencyPosition) {
            case 'left':
            case 'left_space':
                $formattedPrice = $symbol . $formattedNumber;
                break;
            case 'right':
            case 'right_space':
                $formattedPrice = $formattedNumber . $symbol;
                break;
            default:
                return wc_price($amount, $args);
        }

        return apply_filters('zs_deposits_formatted_price_no_space', html_entity_decode($formattedPrice), $amount, $args);
    }

    private static function logInfo(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    private static function logWarning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    private static function logError(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    private static function logDebug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        // Silence noisy levels in production
        if (in_array($level, ['info', 'debug'], true)) {
            return;
        }

        $logger = wc_get_logger();
        $logger->log($level, $message, array_merge(['source' => 'zs-deposits-utils'], $context));
    }

    public static function invalidateDepositInfoCache($orderOrId = null): void
    {
        if ($orderOrId === null) {
            self::$depositInfoCache = [];
            return;
        }

        $orderId = $orderOrId instanceof WC_Order ? $orderOrId->get_id() : (int) $orderOrId;
        if ($orderId > 0) {
            unset(self::$depositInfoCache[$orderId]);
        }
    }

    private static function resolvePaymentChoice(int $orderId, ?string $paymentChoice): string
    {
        if ($paymentChoice && in_array($paymentChoice, ['deposit', 'full'], true)) {
            return $paymentChoice;
        }

        if (WC()->session) {
            $stored = WC()->session->get('zs_deposits_payment_choice_order_' . $orderId);

            if ($stored && in_array($stored, ['deposit', 'full'], true)) {
                return $stored;
            }
        }

        return 'deposit';
    }
}
