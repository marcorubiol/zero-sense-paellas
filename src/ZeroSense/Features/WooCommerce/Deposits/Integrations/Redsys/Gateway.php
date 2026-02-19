<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

use WC_Order;
use WC_Payment_Gateway;
use ZeroSense\Features\WooCommerce\Gateways\RedsysApi;
use ZeroSense\Features\WooCommerce\Gateways\RedsysHelpers;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class Gateway extends WC_Payment_Gateway
{
    public const GATEWAY_ID = 'redsys_deposits';

    private ?RedsysApi $api = null;

    public function __construct()
    {
        $this->id = $this->getGatewayId();
        $this->method_title = $this->getMethodTitle();
        $this->method_description = $this->getMethodDescription();
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title', $this->getDefaultTitle());
        $this->description = $this->get_option('description', $this->getDefaultDescription());

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->getCallbackEndpoint(), [$this, 'handleCallback']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'renderReceipt']);

        $this->api = new RedsysApi();
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'zero-sense'),
                'type' => 'checkbox',
                'label' => __('Enable Redsys Deposits Gateway', 'zero-sense'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'zero-sense'),
                'type' => 'text',
                'description' => __('Controls the title shown during checkout.', 'zero-sense'),
                'default' => $this->getDefaultTitle(),
            ],
            'description' => [
                'title' => __('Description', 'zero-sense'),
                'type' => 'textarea',
                'description' => __('Payment method description shown during checkout.', 'zero-sense'),
                'default' => $this->getDefaultDescription(),
            ],
            'merchant_code' => [
                'title' => __('Merchant Code (FUC)', 'zero-sense'),
                'type' => 'text',
                'default' => '',
            ],
            'secret_key' => [
                'title' => __('Secret Key (SHA-256)', 'zero-sense'),
                'type' => 'password',
                'default' => '',
            ],
            'terminal' => [
                'title' => __('Terminal', 'zero-sense'),
                'type' => 'text',
                'default' => '1',
            ],
            'test_mode' => [
                'title' => __('Test Mode', 'zero-sense'),
                'type' => 'checkbox',
                'label' => __('Enable Redsys sandbox environment', 'zero-sense'),
                'default' => 'no',
            ],
        ];
    }

    public function is_available(): bool
    {
        $isOrderPay = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))
            || isset($_GET['pay_for_order']);

        if (!parent::is_available() && !$isOrderPay) {
            return false;
        }

        foreach (['merchant_code', 'secret_key', 'terminal'] as $option) {
            if (empty($this->get_option($option))) {
                return false;
            }
        }

        return true;
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
    }

    public function process_payment($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Payment error: invalid order.', 'zero-sense'), 'error');
            return ['result' => 'failure'];
        }

        // Debug context
        if (function_exists('wc_get_logger')) {
            try {
                // intentionally no info logs in production
            } catch (\Throwable $e) { /* ignore */ }
        }

        $params = $this->build_redsys_parameters($order);
        if (!$params) {
            wc_add_notice(__('Payment error: unable to prepare Redsys parameters.', 'zero-sense'), 'error');
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('RedsysDeposits: build_redsys_parameters returned null', ['source' => 'zero-sense-redsys', 'order_id' => $orderId]);
            }
            return ['result' => 'failure'];
        }

        // Store parameters for receipt page form post
        $order->update_meta_data('_zs_redsys_params', $params);
        // Store payment intent (v3 key)
        if (!empty($params['payment_intent'])) {
            $order->update_meta_data(\ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys::PAYMENT_FLOW, (string) $params['payment_intent']);
        }
        $order->save();

        // On order-pay, Woo does not render the receipt template automatically. Output the form now and exit.
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $this->renderImmediateFormAndExit($params);
        }

        // Checkout-created orders: redirect to receipt page (which renders the form)
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    protected function build_redsys_parameters(WC_Order $order): ?array
    {
        if (!$this->api instanceof RedsysApi) {
            return null;
        }

        // Resolve deposit/full/remaining amounts (MVP, minimal but robust)
        $depositInfo   = $this->resolveDepositInfo($order);
        $isDepositPaid = (bool) ($depositInfo['is_deposit_paid'] ?? false);
        $chosen        = (string) ($depositInfo['chosen_option'] ?? '');
        $depositAmount = (float) ($depositInfo['deposit_amount'] ?? 0.0);
        $remainingMeta = (float) ($depositInfo['remaining_amount'] ?? 0.0);
        $fullAmount    = (float) ($depositInfo['order_total'] ?? $order->get_total());

        $amountToCharge = 0.0;
        $paymentIntent = 'full';

        // no info logs for decision inputs in production

        if ($isDepositPaid) {
            // Remaining payment on order-pay
            if ($remainingMeta > 0.0) {
                $amountToCharge = $remainingMeta;
                $paymentIntent = 'remaining';
            } else {
                // Compute remaining deterministically if deposit meta exists
                if ($depositAmount > 0.0) {
                    $computedRemaining = max(0.0, $fullAmount - $depositAmount);
                    if ($computedRemaining > 0.0) {
                        $amountToCharge = $computedRemaining;
                        $paymentIntent = 'remaining';
                    } else {
                        if (function_exists('wc_add_notice')) {
                            wc_add_notice(__('Error: remaining amount is zero for this order.', 'zero-sense'), 'error');
                        }
                        if (function_exists('wc_get_logger')) {
                            wc_get_logger()->error('RedsysDeposits: computed remaining <= 0 (order_total - deposit_amount)', ['source' => 'zero-sense-redsys', 'order_id' => $order->get_id()]);
                        }
                        return null;
                    }
                } else {
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(__('Error: remaining amount is missing for this order. Please contact support.', 'zero-sense'), 'error');
                    }
                    if (function_exists('wc_get_logger')) {
                        wc_get_logger()->error('RedsysDeposits: missing remaining_amount and deposit_amount meta for deposit-paid order', ['source' => 'zero-sense-redsys', 'order_id' => $order->get_id()]);
                    }
                    return null;
                }
            }
        } else {
            // Initial payment: respect explicit full choice; otherwise require deposit meta to charge a deposit
            if ($chosen === 'full') {
                $amountToCharge = $fullAmount;
                $paymentIntent = 'full_initial';
            } elseif ($depositAmount > 0.0) {
                $amountToCharge = $depositAmount;
                $paymentIntent = 'deposit';
            } else {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Error: deposit amount is not available for this order.', 'zero-sense'), 'error');
                }
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error('RedsysDeposits: missing deposit_amount meta on initial payment', ['source' => 'zero-sense-redsys', 'order_id' => $order->get_id()]);
                }
                return null;
            }
        }

        // Log intent before building parameters
        try {
            Logs::add($order, 'gateway', [
                'event' => 'intent_prepared',
                'intent' => $paymentIntent,
                'amount' => wc_format_decimal($amountToCharge),
                'order_total' => wc_format_decimal($fullAmount),
            ]);
        } catch (\Throwable $e) { /* no-op */ }

        // Redsys requires amount in cents as string without decimal point
        $merchantOrder = $this->generateOrderId($order->get_id());
        $this->api->setParameter('DS_MERCHANT_AMOUNT', $this->formatAmount($amountToCharge));
        $this->api->setParameter('DS_MERCHANT_ORDER', $merchantOrder);
        $this->api->setParameter('DS_MERCHANT_CURRENCY', $this->getCurrencyNumericCode(get_woocommerce_currency()));
        $this->api->setParameter('DS_MERCHANT_TRANSACTIONTYPE', Config::TRANSACTION_TYPE_AUTHORIZATION);
        $this->api->setParameter('DS_MERCHANT_TERMINAL', $this->get_option('terminal'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTCODE', $this->get_option('merchant_code'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTURL', \ZeroSense\Features\WooCommerce\Gateways\RedsysHelpers::getMerchantUrl(self::GATEWAY_ID));
        $this->api->setParameter('DS_MERCHANT_URLOK', \ZeroSense\Features\WooCommerce\Gateways\RedsysHelpers::getOkUrl($order));
        $this->api->setParameter('DS_MERCHANT_URLKO', \ZeroSense\Features\WooCommerce\Gateways\RedsysHelpers::getKoUrl());
        $this->api->setParameter('DS_MERCHANT_PAYMETHODS', $this->getPayMethods());
        // Localize Redsys form
        $this->api->setParameter('DS_MERCHANT_CONSUMERLANGUAGE', $this->getConsumerLanguageForOrder($order));

        if ($this->shouldUseEmv3ds()) {
            $emv3dsParameters = Config::getEmv3dsParameters();
            if ($emv3dsParameters) {
                $this->api->setParameter('DS_MERCHANT_EMV3DS', json_encode($emv3dsParameters));
            }
        }

        foreach ($this->getAdditionalParameters($order) as $key => $value) {
            $this->api->setParameter($key, $value);
        }

        $this->api->setParameter('DS_MERCHANT_MERCHANTNAME', get_bloginfo('name'));

        $secretKey = $this->get_option('secret_key');
        if (!$secretKey) {
            return null;
        }

        $merchantParameters = $this->api->createMerchantParameters();
        $signature          = $this->api->createMerchantSignature($secretKey);

        if (!$merchantParameters || !$signature) {
            return null;
        }

        $ret = [
            'redirect_url' => Config::getEndpoint($this->get_option('test_mode') === 'yes'),
            'signature' => $signature,
            'merchant_parameters' => $merchantParameters,
            // Pass payment intent back so caller can store it
            'payment_intent' => $paymentIntent,
        ];

        // no info logs for prepared params in production

        return $ret;
    }

    private function formatAmount(float $amount): string
    {
        // Delegate to shared helper
        return RedsysHelpers::formatAmount($amount);
    }

    private function generateOrderId(int $orderId): string
    {
        // Delegate to shared helper
        return RedsysHelpers::generateOrderId($orderId);
    }

    /**
     * Resolve deposit-related information for the order.
     * MVP: gather from request, session/meta, and safe fallbacks.
     */
    private function resolveDepositInfo(WC_Order $order): array
    {
        $orderId = (int) $order->get_id();

        // Chosen option from request (if any)
        $chosen = null;
        // Prefer new v3 field names
        if (isset($_POST['zs_deposits_payment_choice_submit'])) {
            $chosen = sanitize_text_field((string) $_POST['zs_deposits_payment_choice_submit']);
        } elseif (isset($_POST['zs_deposits_payment_choice'])) {
            $chosen = sanitize_text_field((string) $_POST['zs_deposits_payment_choice']);
        }

        // Pre-calc
        $orderTotal = (float) $order->get_total();
        $isDepositPaid = $order->has_status('deposit-paid');

        // v3 meta via MetaKeys (with legacy mapping inside MetaKeys if needed)
        $depositAmount = (float) (MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $remainingAmount = (float) (MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT, true) ?: 0);

        // No fallbacks: do not compute amounts from settings here

        return [
            'chosen_option' => $chosen,
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $remainingAmount,
            'order_total' => $orderTotal,
            'is_deposit_paid' => $isDepositPaid,
        ];
    }

    public function handleCallback(): void
    {
        // Redsys sends POST with Ds_MerchantParameters (base64 JSON), Ds_Signature, Ds_SignatureVersion
        try {
            $logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
            $post = $_POST ?? [];
            $sigVersion = isset($post['Ds_SignatureVersion']) ? sanitize_text_field((string) $post['Ds_SignatureVersion']) : '';
            $mpB64      = isset($post['Ds_MerchantParameters']) ? (string) $post['Ds_MerchantParameters'] : '';
            $sig        = isset($post['Ds_Signature']) ? (string) $post['Ds_Signature'] : '';

            if (!$mpB64 || !$sig) {
                if ($logger) { $logger->error('Redsys callback: missing parameters', ['source' => 'zero-sense-redsys']); }
                status_header(400); exit;
            }

            $callbackApi = new RedsysApi();
            $decodedJson = $callbackApi->decodeMerchantParameters($mpB64);

            $params = []; // decoded associative array
            if ($decodedJson) {
                $arr = json_decode($decodedJson, true);
                if (is_array($arr)) { $params = $arr; }
            }

            // Extract fields
            $dsOrder    = isset($params['Ds_Order']) ? (string) $params['Ds_Order'] : '';
            $dsResponse = isset($params['Ds_Response']) ? (int) $params['Ds_Response'] : 999;

            // Verify signature
            $signatureOk = false;
            try {
                $calc        = $callbackApi->createMerchantSignatureNotif((string) $this->get_option('secret_key'), $mpB64);
                $signatureOk = hash_equals((string) $sig, (string) $calc);
            } catch (\Throwable $e) {
                $signatureOk = false;
            }

            // Map Ds_Order back to Woo order ID (we generate like 12345Txxxx)
            $orderId = 0;
            if ($dsOrder && preg_match('/^(\d+)/', $dsOrder, $m)) { $orderId = (int) $m[1]; }
            $order = $orderId ? wc_get_order($orderId) : null;
            if (!$order instanceof \WC_Order) {
                if ($logger) { $logger->error('Redsys callback: order not found for Ds_Order ' . $dsOrder, ['source' => 'zero-sense-redsys']); }
                status_header(200); exit; // acknowledge
            }

            // Log raw callback
            try {
                Logs::add($order, 'gateway', [
                    'event' => 'callback_received',
                    'signature_ok' => $signatureOk ? 'yes' : 'no',
                    'response' => $dsResponse,
                    'ds_order' => $dsOrder,
                ]);
            } catch (\Throwable $e) { /* no-op */ }
            // suppress info-level callback log

            if (!$signatureOk) {
                // Do not alter order, just note failure
                $order->add_order_note(__('Redsys callback: signature verification failed. No status change.', 'zero-sense'));
                $order->save();
                status_header(200); exit;
            }

            $isSuccess = ($dsResponse >= 0 && $dsResponse <= 99);
            if ($isSuccess) {
                // Idempotency: if already in a paid status, just acknowledge
                if ($order->has_status(['deposit-paid', 'fully-paid'])) {
                    try { Logs::add($order, 'gateway', ['event' => 'callback_already_processed', 'status' => $order->get_status()]); } catch (\Throwable $e) {}
                    status_header(200); exit;
                }

                // Decide target status and update metas — mirrors ReturnHandler::handleSuccess()
                $intent = (string) $order->get_meta(MetaKeys::PAYMENT_FLOW, true);
                $amountRaw = isset($params['Ds_Amount']) ? ((float) $params['Ds_Amount'] / 100) : 0.0;
                $orderTotal = (float) $order->get_total();
                $depositAmountMeta = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);

                if ($intent === 'deposit') {
                    $target = 'deposit-paid';
                    MetaKeys::update($order, MetaKeys::IS_DEPOSIT_PAID, 'yes');
                    MetaKeys::update($order, MetaKeys::DEPOSIT_PAYMENT_DATE, current_time('mysql'));
                    MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'deposit');
                    MetaKeys::delete($order, MetaKeys::IS_BALANCE_PAID);
                    MetaKeys::delete($order, MetaKeys::BALANCE_PAYMENT_DATE);
                    $remaining = max(0.0, $orderTotal - $depositAmountMeta);
                    MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remaining);
                    MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $remaining);
                } else {
                    $target = 'fully-paid';
                    MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, $intent ?: 'full');
                    MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, 0);
                    MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, max(0.0, $orderTotal - $depositAmountMeta));
                }

                $order->add_order_note(sprintf(__('Redsys payment success via callback (%s). Response: %d', 'zero-sense'), $intent ?: 'n/a', $dsResponse));
                $previousStatus = $order->get_status();
                $order->update_status($target);
                $order->save();
                $order->save_meta_data();

                // Ensure FlowMattic triggers even if status was already set (e.g., by ReturnHandler)
                if ($previousStatus === $target) {
                    do_action('woocommerce_order_status_changed', $order->get_id(), $previousStatus, $target, $order);
                }

                try { Logs::add($order, 'gateway', ['event' => 'payment_success', 'intent' => $intent, 'target_status' => $target, 'amount' => $amountRaw]); } catch (\Throwable $e) {}
            } else {
                // Failed: leave status as-is, add note only
                $order->add_order_note(sprintf(__('Redsys payment failed via callback. Response: %d. Order left unchanged.', 'zero-sense'), $dsResponse));
                $order->save();
                try { Logs::add($order, 'gateway', ['event' => 'payment_failed', 'response' => $dsResponse]); } catch (\Throwable $e) {}
                if ($logger) { $logger->warning('Redsys failure; no status change', ['source' => 'zero-sense-redsys-deposits', 'order_id' => $orderId, 'resp' => $dsResponse]); }
            }

            status_header(200); exit;
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) { wc_get_logger()->error('Redsys callback exception: ' . $e->getMessage(), ['source' => 'zero-sense-redsys']); }
            status_header(500); exit;
        }
    }

    protected function getCallbackEndpoint(): string
    {
        return 'wc_' . $this->getGatewayId();
    }

    protected function getGatewayId(): string
    {
        return self::GATEWAY_ID;
    }

    protected function getMethodTitle(): string
    {
        return __('Redsys Deposits', 'zero-sense');
    }

    protected function getMethodDescription(): string
    {
        return __('Accept deposits and full payments through Redsys.', 'zero-sense');
    }

    protected function getDefaultTitle(): string
    {
        return __('Redsys Deposits', 'zero-sense');
    }

    protected function getDefaultDescription(): string
    {
        return __('Pay with Redsys. Supports deposit or full payment.', 'zero-sense');
    }

    protected function getPayMethods(): string
    {
        return Config::PAYMENT_METHOD_ALL;
    }

    protected function shouldUseEmv3ds(): bool
    {
        return true;
    }

    protected function getAdditionalParameters(WC_Order $order): array
    {
        return [];
    }

    private function getConsumerLanguageForOrder(WC_Order $order): string
    {
        $lang = $order->get_meta('wpml_language', true);
        if (!$lang && function_exists('apply_filters')) {
            $lang = apply_filters('wpml_current_language', null);
        }
        $lang = $lang ?: substr((string) get_locale(), 0, 2);
        // Map language code to Redsys consumer language
        return Config::getConsumerLanguage($lang);
    }

    protected function getCurrencyNumericCode(string $currency): string
    {
        // Backwards-compat shim: delegate to shared helper
        return RedsysHelpers::getCurrencyNumericCode($currency);
    }

    public function renderReceipt($orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) { return; }
        $params = $order->get_meta('_zs_redsys_params');
        if (!is_array($params) || empty($params['redirect_url']) || empty($params['signature']) || empty($params['merchant_parameters'])) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('RedsysDeposits: missing params on receipt', ['source' => 'zero-sense-redsys', 'order_id' => $orderId]);
            }
            echo '<p>' . esc_html__('Unable to initiate payment. Please contact support.', 'zero-sense') . '</p>';
            return;
        }
        $this->outputFormHtml($params);
    }

    /**
     * Output Redsys form immediately and terminate response (order-pay flow).
     */
    private function renderImmediateFormAndExit(array $params): void
    {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__('Redirecting...', 'zero-sense') . '</title></head><body>';
        $this->outputFormHtml($params);
        echo '</body></html>';
        exit;
    }

    /**
     * Shared form HTML output.
     */
    private function outputFormHtml(array $params): void
    {
        $action = esc_url($params['redirect_url']);
        $sig    = esc_attr($params['signature']);
        $mp     = esc_attr($params['merchant_parameters']);
        $sv     = esc_attr(Config::SIGNATURE_VERSION);

        echo '<form id="zs-redsys-form" action="' . $action . '" method="post">'
            . '<input type="hidden" name="Ds_SignatureVersion" value="' . $sv . '" />'
            . '<input type="hidden" name="Ds_MerchantParameters" value="' . $mp . '" />'
            . '<input type="hidden" name="Ds_Signature" value="' . $sig . '" />'
            . '</form>';
        echo '<p>' . esc_html__('Redirecting to Redsys...', 'zero-sense') . '</p>';
        echo '<script>document.getElementById("zs-redsys-form").submit();</script>';
    }
}
