<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

use WC_Order;

class BizumGateway extends Gateway
{
    public const GATEWAY_ID = 'bizum_deposits';

    protected function getGatewayId(): string
    {
        return self::GATEWAY_ID;
    }

    protected function getMethodTitle(): string
    {
        return __('Bizum Deposits', 'zero-sense');
    }

    protected function getMethodDescription(): string
    {
        return __('Pay using Bizum through Redsys with deposit or full payment options.', 'zero-sense');
    }

    protected function getDefaultTitle(): string
    {
        return __('Bizum Deposits', 'zero-sense');
    }

    protected function getDefaultDescription(): string
    {
        return __('Pay with Bizum via Redsys.', 'zero-sense');
    }

    protected function getPayMethods(): string
    {
        return Config::PAYMENT_METHOD_BIZUM;
    }

    protected function shouldUseEmv3ds(): bool
    {
        return false;
    }

    protected function getAdditionalParameters(WC_Order $order): array
    {
        return [
            'DS_MERCHANT_PA_BIZUM' => '1',
        ];
    }

    protected function getCallbackEndpoint(): string
    {
        return 'wc_' . self::GATEWAY_ID;
    }
}
