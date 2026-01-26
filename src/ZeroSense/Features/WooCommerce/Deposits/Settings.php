<?php
namespace ZeroSense\Features\WooCommerce\Deposits;

/**
 * Lightweight helper to centralise WooCommerce Deposits settings.
 */
class Settings
{
    public const OPTION_ENABLE_DEPOSITS = 'zs_deposits_enable';
    public const OPTION_DEPOSIT_PERCENTAGE = 'zs_deposits_amount_percentage';

    /**
     * Ensure defaults are present and clamp updates to valid ranges.
     */
    public static function bootstrap(): void
    {
        self::ensureDefaults();

        foreach ([
            self::OPTION_DEPOSIT_PERCENTAGE,
        ] as $option) {
            add_filter('pre_update_option_' . $option, [self::class, 'sanitizePercentage'], 10, 2);
        }

        foreach ([
            self::OPTION_ENABLE_DEPOSITS,
        ] as $option) {
            add_filter('pre_update_option_' . $option, [self::class, 'sanitizeEnableFlag'], 10, 2);
        }
    }

    /**
     * Retrieve the deposit percentage value as integer.
     */
    public static function getDepositPercentage(): int
    {
        $value = get_option(self::OPTION_DEPOSIT_PERCENTAGE, false);

        return (int) $value;
    }

    /**
     * Generic accessor mirroring the legacy helper signature.
     */
    public static function get(string $optionName, $default = null)
    {
        switch ($optionName) {
            case self::OPTION_ENABLE_DEPOSITS:
                return get_option(self::OPTION_ENABLE_DEPOSITS, $default ?? 'no');
            case self::OPTION_DEPOSIT_PERCENTAGE:
                return get_option(self::OPTION_DEPOSIT_PERCENTAGE, $default ?? 30);
            case 'zs_deposits_deposit_type':
                return 'percentage';
            case 'zs_deposits_deposit_amount_fixed':
                return 0;
            default:
                return get_option($optionName, $default);
        }
    }

    /**
     * Clamp the deposit percentage between 1 and 100.
     */
    public static function sanitizePercentage($newValue, $oldValue)
    {
        $value = (int) $newValue;
        if ($value < 1) {
            $value = 1;
        }
        if ($value > 100) {
            $value = 100;
        }

        return (string) $value;
    }

    /**
     * Normalise enable flag to 'yes'/'no'.
     */
    public static function sanitizeEnableFlag($newValue, $oldValue)
    {
        return $newValue === 'yes' ? 'yes' : 'no';
    }

    private static function ensureDefaults(): void
    {
        if (get_option(self::OPTION_ENABLE_DEPOSITS, false) === false) {
            update_option(self::OPTION_ENABLE_DEPOSITS, 'yes');
        }

        if (get_option(self::OPTION_DEPOSIT_PERCENTAGE, false) === false) {
            update_option(self::OPTION_DEPOSIT_PERCENTAGE, 30);
        }
    }
}
