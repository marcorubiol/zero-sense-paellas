<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

/**
 * Redsys API helper — pure PHP 8.x implementation.
 *
 * Replaces the dependency on the third-party RedsyspurAPI class from the
 * redsyspur plugin. Implements only the methods actually used by our gateways:
 *   - Payment form generation (setParameter, createMerchantParameters, createMerchantSignature)
 *   - Callback verification (decodeMerchantParameters, createMerchantSignatureNotif)
 *
 * Cryptographic operations use PHP native functions (openssl, hash_hmac).
 * No external dependencies required.
 */
class RedsysApi
{
    private array $params = [];

    public function setParameter(string $key, string $value): void
    {
        $this->params[$key] = $value;
    }

    public function getParameter(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    // -------------------------------------------------------------------------
    // Payment form generation
    // -------------------------------------------------------------------------

    public function createMerchantParameters(): string
    {
        return base64_encode((string) json_encode($this->params));
    }

    public function createMerchantSignature(string $secretKey): string
    {
        $order = $this->params['DS_MERCHANT_ORDER'] ?? ($this->params['Ds_Merchant_Order'] ?? '');
        $merchantParameters = $this->createMerchantParameters();

        $key        = base64_decode($secretKey);
        $diversified = $this->encrypt3DES($order, $key);
        $mac        = hash_hmac('sha256', $merchantParameters, $diversified, true);

        return base64_encode($mac);
    }

    // -------------------------------------------------------------------------
    // Callback / notification verification
    // -------------------------------------------------------------------------

    public function decodeMerchantParameters(string $b64): string
    {
        $decoded = base64_decode(strtr($b64, '-_', '+/'));
        $arr = json_decode($decoded, true);
        if (is_array($arr)) {
            $this->params = $arr;
        }
        return (string) $decoded;
    }

    public function createMerchantSignatureNotif(string $secretKey, string $b64): string
    {
        $decoded = base64_decode(strtr($b64, '-_', '+/'));
        $arr = json_decode($decoded, true);
        $order = '';
        if (is_array($arr)) {
            $order = (string) ($arr['Ds_Order'] ?? $arr['DS_ORDER'] ?? '');
        }

        $key        = base64_decode($secretKey);
        $diversified = $this->encrypt3DES($order, $key);
        $mac        = hash_hmac('sha256', $b64, $diversified, true);

        return strtr(base64_encode($mac), '+/', '-_');
    }

    // -------------------------------------------------------------------------
    // Internal crypto
    // -------------------------------------------------------------------------

    private function encrypt3DES(string $message, string $key): string
    {
        $iv   = str_repeat("\0", 8);
        $padded = $message . str_repeat("\0", (int) (ceil(strlen($message) / 8) * 8) - strlen($message));
        $encrypted = openssl_encrypt($padded, 'des-ede3-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
        return substr((string) $encrypted, 0, (int) (ceil(strlen($message) / 8) * 8));
    }
}
