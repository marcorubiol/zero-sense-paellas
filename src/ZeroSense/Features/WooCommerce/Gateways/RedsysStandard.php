<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

use WC_Order;
use WC_Payment_Gateway;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\Config;
use ZeroSense\Features\WooCommerce\Gateways\RedsysApi;

class RedsysStandard extends WC_Payment_Gateway
{
    public const GATEWAY_ID = 'redsys_standard';

    private ?RedsysApi $api = null;

    public function __construct()
    {
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __('Redsys (Card)', 'zero-sense');
        $this->method_description = __('Pay by card via Redsys.', 'zero-sense');
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled');
        $this->title       = $this->get_option('title', __('Card (Redsys)', 'zero-sense'));
        $this->description = $this->get_option('description', __('Secure payment by credit/debit card via Redsys.', 'zero-sense'));

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
                'label' => __('Enable Redsys (Card)', 'zero-sense'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'zero-sense'),
                'type' => 'text',
                'default' => __('Card (Redsys)', 'zero-sense'),
            ],
            'description' => [
                'title' => __('Description', 'zero-sense'),
                'type' => 'textarea',
                'default' => __('Secure payment by credit/debit card via Redsys.', 'zero-sense'),
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
        if (!parent::is_available()) {
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

        $params = $this->buildParameters($order);
        if (!$params) {
            wc_add_notice(__('Payment error: unable to prepare Redsys parameters.', 'zero-sense'), 'error');
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('RedsysStandard: buildParameters returned null', ['source' => 'zero-sense-redsys']);
            }
            return ['result' => 'failure'];
        }

        // Store parameters for receipt page form post
        $order->update_meta_data('_zs_redsys_params', $params);
        $order->save();

        // Redirect to receipt page where we'll auto-submit POST to Redsys
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        ];
    }

    protected function buildParameters(WC_Order $order): ?array
    {
        if (!$this->api instanceof RedsysApi) { return null; }

        $this->api->setParameter('DS_MERCHANT_AMOUNT', RedsysHelpers::formatAmount((float) $order->get_total()));
        $this->api->setParameter('DS_MERCHANT_ORDER', RedsysHelpers::generateOrderId((int) $order->get_id()));
        $this->api->setParameter('DS_MERCHANT_CURRENCY', RedsysHelpers::getCurrencyNumericCode((string) get_woocommerce_currency()));
        $this->api->setParameter('DS_MERCHANT_TRANSACTIONTYPE', Config::TRANSACTION_TYPE_AUTHORIZATION);
        $this->api->setParameter('DS_MERCHANT_TERMINAL', $this->get_option('terminal'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTCODE', $this->get_option('merchant_code'));
        $this->api->setParameter('DS_MERCHANT_MERCHANTURL', RedsysHelpers::getMerchantUrl(self::GATEWAY_ID));
        $this->api->setParameter('DS_MERCHANT_URLOK', RedsysHelpers::getOkUrl($order));
        $this->api->setParameter('DS_MERCHANT_URLKO', RedsysHelpers::getKoUrl());
        $this->api->setParameter('DS_MERCHANT_PAYMETHODS', Config::PAYMENT_METHOD_ALL);

        $emv3ds = Config::getEmv3dsParameters();
        if ($emv3ds) { $this->api->setParameter('DS_MERCHANT_EMV3DS', json_encode($emv3ds)); }

        $secretKey = (string) $this->get_option('secret_key');
        if (!$secretKey) { return null; }

        $merchantParameters = $this->api->createMerchantParameters();
        $signature          = $this->api->createMerchantSignature($secretKey);
        if (!$merchantParameters || !$signature) { return null; }

        return [
            'redirect_url' => Config::getEndpoint($this->get_option('test_mode') === 'yes'),
            'signature' => $signature,
            'merchant_parameters' => $merchantParameters,
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

            if ($logger) { $logger->info('Redsys standard callback', ['source'=>'zero-sense-redsys-standard','order_id'=>$orderId,'resp'=>$dsResponse,'sig_ok'=>$signatureOk?'1':'0']); }

            if (!$signatureOk) {
                $order->add_order_note(__('Redsys callback: signature verification failed. No status change.', 'zero-sense'));
                $order->save(); status_header(200); exit;
            }

            $isSuccess = ($dsResponse >= 0 && $dsResponse <= 99);
            if ($isSuccess) {
                $order->add_order_note(sprintf(__('Redsys payment success. Response: %d', 'zero-sense'), $dsResponse));
                $order->update_status('fully-paid');
                $order->save();
                if ($logger) { $logger->info('Redsys standard success; status fully-paid', ['source'=>'zero-sense-redsys-standard','order_id'=>$orderId]); }
            } else {
                $order->add_order_note(sprintf(__('Redsys payment failed. Response: %d. Order left unchanged.', 'zero-sense'), $dsResponse));
                $order->save();
                if ($logger) { $logger->warning('Redsys standard failure; no status change', ['source'=>'zero-sense-redsys-standard','order_id'=>$orderId,'resp'=>$dsResponse]); }
            }

            status_header(200); exit;
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) { wc_get_logger()->error('Redsys standard callback exception: '.$e->getMessage(), ['source'=>'zero-sense-redsys-standard']); }
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
                wc_get_logger()->error('RedsysStandard: missing params on receipt', ['source' => 'zero-sense-redsys', 'order_id' => $orderId]);
            }
            echo '<p>' . esc_html__('Unable to initiate payment. Please contact support.', 'zero-sense') . '</p>';
            return;
        }

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
}
