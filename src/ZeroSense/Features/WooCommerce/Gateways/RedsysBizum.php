<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

use WC_Order;
use WC_Payment_Gateway;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\Config;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Gateways\RedsysApi;

class RedsysBizum extends WC_Payment_Gateway
{
    public const GATEWAY_ID = 'redsys_bizum';

    private ?RedsysApi $api = null;

    public function __construct()
    {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __('Redsys (Bizum)', 'zero-sense');
        $this->method_description = __('Pay with Bizum via Redsys.', 'zero-sense');
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled');
        $this->title       = $this->get_option('title', __('Bizum (Redsys)', 'zero-sense'));
        $this->description = $this->get_option('description', __('Quick payment with Bizum via Redsys.', 'zero-sense'));

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
                'label' => __('Enable Redsys (Bizum)', 'zero-sense'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'zero-sense'),
                'type' => 'text',
                'default' => __('Bizum (Redsys)', 'zero-sense'),
            ],
            'description' => [
                'title' => __('Description', 'zero-sense'),
                'type' => 'textarea',
                'default' => __('Quick payment with Bizum via Redsys.', 'zero-sense'),
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

        foreach (['merchant_code', 'secret_key', 'terminal'] as $opt) {
            if (empty($this->get_option($opt))) { return false; }
        }

        return true;
    }

    public function process_payment($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Payment error: invalid order.', 'zero-sense'), 'error');
            return ['result' => 'failure'];
        }

        // No info logs in production

        $params = $this->buildParameters($order);
        if (!$params) {
            wc_add_notice(__('Payment error: unable to prepare Redsys parameters.', 'zero-sense'), 'error');
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('RedsysBizum: buildParameters returned null', ['source' => 'zero-sense-redsys']);
            }
            return ['result' => 'failure'];
        }

        // Store parameters for receipt page form post
        $order->update_meta_data('_zs_redsys_params', $params);
        // Persist intent under v3 key if present (both flows)
        if (!empty($params['payment_intent'])) {
            $order->update_meta_data(\ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys::PAYMENT_FLOW, (string) $params['payment_intent']);
        }
        $order->save();

        // On order-pay, immediately output form and exit
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            // Persist intent under v3 key if present
            if (!empty($params['payment_intent'])) {
                $order->update_meta_data(\ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys::PAYMENT_FLOW, (string) $params['payment_intent']);
                $order->save();
            }
            $this->renderImmediateFormAndExit($params);
        }

        // Receipt path for normal checkout
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    protected function buildParameters(WC_Order $order): ?array
    {
        if (!$this->api instanceof RedsysApi) { return null; }

        // Determine amount similar to deposits: remaining if deposit-paid; else deposit if present unless explicitly full
        $info          = $this->resolveDepositInfo($order);
        $isDepositPaid = (bool) ($info['is_deposit_paid'] ?? false);
        $chosen        = (string) ($info['chosen_option'] ?? '');
        $depositAmount = (float) ($info['deposit_amount'] ?? 0.0);
        $remainingMeta = (float) ($info['remaining_amount'] ?? 0.0);
        $fullAmount    = (float) ($info['order_total'] ?? $order->get_total());

        if ($isDepositPaid) {
            if ($remainingMeta > 0.0) {
                $amount = $remainingMeta;
                $intent = 'remaining';
            } else {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Error: remaining amount is missing for this order. Please contact support.', 'zero-sense'), 'error');
                }
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error('RedsysBizum: missing remaining_amount meta for deposit-paid order', ['source' => 'zero-sense-redsys', 'order_id' => $order->get_id()]);
                }
                return null;
            }
        } else {
            if ($chosen === 'full') {
                $amount = $fullAmount;
                $intent = 'full_initial';
            } elseif ($depositAmount > 0.0) {
                $amount = $depositAmount;
                $intent = 'deposit';
            } else {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Error: deposit amount is not available for this order.', 'zero-sense'), 'error');
                }
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error('RedsysBizum: missing deposit_amount meta on initial payment', ['source' => 'zero-sense-redsys', 'order_id' => $order->get_id()]);
                }
                return null;
            }
        }

        $merchantOrder = RedsysHelpers::generateOrderId((int) $order->get_id());

        $this->api->setParameter('DS_MERCHANT_AMOUNT', RedsysHelpers::formatAmount((float) $amount));
        $this->api->setParameter('DS_MERCHANT_ORDER', $merchantOrder);
        $this->api->setParameter('DS_MERCHANT_CURRENCY', RedsysHelpers::getCurrencyNumericCode((string) get_woocommerce_currency()));
        $this->api->setParameter('DS_MERCHANT_TRANSACTIONTYPE', Config::TRANSACTION_TYPE_AUTHORIZATION);
        $this->api->setParameter('DS_MERCHANT_TERMINAL', $this->get_option('terminal'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTCODE', $this->get_option('merchant_code'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTURL', RedsysHelpers::getMerchantUrl(self::GATEWAY_ID));
        $this->api->setParameter('DS_MERCHANT_URLOK', RedsysHelpers::getOkUrl($order));
        $this->api->setParameter('DS_MERCHANT_URLKO', RedsysHelpers::getKoUrl());
        $this->api->setParameter('DS_MERCHANT_PAYMETHODS', Config::PAYMENT_METHOD_BIZUM);

        $emv3ds = Config::getEmv3dsParameters();
        if ($emv3ds) { $this->api->setParameter('DS_MERCHANT_EMV3DS', json_encode($emv3ds)); }

        $secretKey = (string) $this->get_option('secret_key');
        if (!$secretKey) { return null; }

        $merchantParameters = $this->api->createMerchantParameters();
        $signature          = $this->api->createMerchantSignature($secretKey);
        if (!$merchantParameters || !$signature) { return null; }

        // No info logs for prepared params in production

        return [
            'redirect_url' => Config::getEndpoint($this->get_option('test_mode') === 'yes'),
            'signature' => $signature,
            'merchant_parameters' => $merchantParameters,
            'payment_intent' => $intent,
        ];
    }

    public function handleCallback(): void
    {
        try {
            $logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
            $post = $_POST ?? [];
            $mpB64 = isset($post['Ds_MerchantParameters']) ? (string) $post['Ds_MerchantParameters'] : '';
            $sig   = isset($post['Ds_Signature']) ? (string) $post['Ds_Signature'] : '';
            if (!$mpB64 || !$sig) { status_header(400); exit; }

            $callbackApi = new RedsysApi();
            $decodedJson = $callbackApi->decodeMerchantParameters($mpB64);

            $params = [];
            if ($decodedJson) {
                $arr = json_decode($decodedJson, true);
                if (is_array($arr)) { $params = $arr; }
            }

            $dsOrder    = isset($params['Ds_Order']) ? (string) $params['Ds_Order'] : '';
            $dsResponse = isset($params['Ds_Response']) ? (int) $params['Ds_Response'] : 999;

            $signatureOk = false;
            try {
                $calc        = $callbackApi->createMerchantSignatureNotif((string) $this->get_option('secret_key'), $mpB64);
                $signatureOk = hash_equals((string) $sig, (string) $calc);
            } catch (\Throwable $e) { $signatureOk = false; }

            $orderId = 0; if ($dsOrder && preg_match('/^(\d+)/', $dsOrder, $m)) { $orderId = (int) $m[1]; }
            $order = $orderId ? wc_get_order($orderId) : null;
            if (!$order instanceof \WC_Order) { status_header(200); exit; }

            // suppress info-level callback log

            if (!$signatureOk) {
                $order->add_order_note(__('Redsys Bizum callback: signature verification failed. No status change.', 'zero-sense'));
                $order->save(); status_header(200); exit;
            }

            $isSuccess = ($dsResponse >= 0 && $dsResponse <= 99);
            if ($isSuccess) {
                $order->add_order_note(sprintf(__('Redsys Bizum payment success. Response: %d', 'zero-sense'), $dsResponse));
                $order->update_status('fully-paid');
                $order->save();
                // suppress info-level success log
            } else {
                $order->add_order_note(sprintf(__('Redsys Bizum payment failed. Response: %d. Order left unchanged.', 'zero-sense'), $dsResponse));
                $order->save();
                if ($logger) { $logger->warning('Redsys Bizum failure; no status change', ['source'=>'zero-sense-redsys-bizum','order_id'=>$orderId,'resp'=>$dsResponse]); }
            }

            status_header(200); exit;
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) { wc_get_logger()->error('Redsys Bizum callback exception: '.$e->getMessage(), ['source'=>'zero-sense-redsys-bizum']); }
            status_header(500); exit;
        }
    }

    protected function getCallbackEndpoint(): string
    {
        return 'wc_' . self::GATEWAY_ID;
    }

    public function renderReceipt($orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) { return; }
        $params = $order->get_meta('_zs_redsys_params');
        if (!is_array($params) || empty($params['redirect_url']) || empty($params['signature']) || empty($params['merchant_parameters'])) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('RedsysBizum: missing params on receipt', ['source' => 'zero-sense-redsys', 'order_id' => $orderId]);
            }
            echo '<p>' . esc_html__('Unable to initiate payment. Please contact support.', 'zero-sense') . '</p>';
            return;
        }
        $this->outputFormHtml($params);
    }

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

    private function outputFormHtml(array $params): void
    {
        $action = esc_url($params['redirect_url']);
        $sig    = esc_attr($params['signature']);
        $mp     = esc_attr($params['merchant_parameters']);
        $sv     = esc_attr(\ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\Config::SIGNATURE_VERSION);

        echo '<form id="zs-redsys-form" action="' . $action . '" method="post">'
            . '<input type="hidden" name="Ds_SignatureVersion" value="' . $sv . '" />'
            . '<input type="hidden" name="Ds_MerchantParameters" value="' . $mp . '" />'
            . '<input type="hidden" name="Ds_Signature" value="' . $sig . '" />'
            . '</form>';
        echo '<p>' . esc_html__('Redirecting to Redsys...', 'zero-sense') . '</p>';
        echo '<script>document.getElementById("zs-redsys-form").submit();</script>';
    }

    private function resolveDepositInfo(WC_Order $order): array
    {
        // Chosen option from request
        $chosen = null;
        if (isset($_POST['zs_deposits_payment_choice_submit'])) {
            $chosen = sanitize_text_field((string) $_POST['zs_deposits_payment_choice_submit']);
        } elseif (isset($_POST['zs_deposits_payment_choice'])) {
            $chosen = sanitize_text_field((string) $_POST['zs_deposits_payment_choice']);
        }

        // v3 MetaKeys (with internal legacy mapping if present)
        $depositAmount   = (float) (MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $remainingAmount = (float) (MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT, true) ?: 0);

        return [
            'chosen_option' => $chosen,
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $remainingAmount,
            'order_total' => (float) $order->get_total(),
            'is_deposit_paid' => $order->has_status('deposit-paid'),
        ];
    }
}
