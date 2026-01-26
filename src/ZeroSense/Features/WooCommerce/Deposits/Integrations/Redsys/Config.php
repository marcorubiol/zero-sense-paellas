<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

/**
 * Redsys configuration helper (ported from legacy ZS_Redsys_Config).
 */
class Config
{
    public const REDSYS_PRODUCTION_URL = 'https://sis.redsys.es/sis/realizarPago';
    public const REDSYS_TEST_URL = 'https://sis-t.redsys.es:25443/sis/realizarPago';

    public const RESPONSE_SUCCESS_MIN = 0;
    public const RESPONSE_SUCCESS_MAX = 99;
    public const RESPONSE_CANCELLED_BY_USER = '0195';

    public const TRANSACTION_TYPE_AUTHORIZATION = '0';

    public const PAYMENT_METHOD_ALL = '';
    public const PAYMENT_METHOD_BIZUM = 'z';

    public const CONSUMER_LANGUAGE_SPANISH = '001';
    public const CONSUMER_LANGUAGE_ENGLISH = '002';
    public const CONSUMER_LANGUAGE_CATALAN = '003';

    public const SIGNATURE_VERSION = 'HMAC_SHA256_V1';

    public static function getEndpoint(bool $testMode = false): string
    {
        return $testMode ? self::REDSYS_TEST_URL : self::REDSYS_PRODUCTION_URL;
    }

    public static function isSuccessResponse($responseCode): bool
    {
        $code = (int) $responseCode;
        return $code >= self::RESPONSE_SUCCESS_MIN && $code <= self::RESPONSE_SUCCESS_MAX;
    }

    public static function isCancelledByUser(string $responseCode): bool
    {
        return $responseCode === self::RESPONSE_CANCELLED_BY_USER;
    }

    public static function getConsumerLanguage(?string $locale = null): string
    {
        $locale = $locale ?: get_locale();
        $language = substr($locale, 0, 2);

        return match ($language) {
            'en' => self::CONSUMER_LANGUAGE_ENGLISH,
            'ca' => self::CONSUMER_LANGUAGE_CATALAN,
            default => self::CONSUMER_LANGUAGE_SPANISH,
        };
    }

    public static function getEmv3dsParameters(): array
    {
        return [
            'threeDSRequestorChallengeInd' => '01',
            'browserJavascriptEnabled' => 'true',
            'browserJavaEnabled' => 'false',
            'browserLanguage' => substr(get_locale(), 0, 2),
            'browserColorDepth' => '24',
            'browserScreenHeight' => '1080',
            'browserScreenWidth' => '1920',
            'browserTZ' => (string) (get_option('gmt_offset') * 60),
            'browserUserAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
    }
}
