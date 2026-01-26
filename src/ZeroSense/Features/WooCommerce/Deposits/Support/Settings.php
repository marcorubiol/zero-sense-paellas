<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Support;

class Settings
{
    public const OPTION_DEPOSIT_PERCENTAGE = 'zs_deposits_amount_percentage';
    public const OPTION_DEPOSITS_ENABLED = 'zs_deposits_enable';

    public static function getDepositPercentage(): float
    {
        $percentage = get_option(self::OPTION_DEPOSIT_PERCENTAGE, 30);

        return (float) $percentage;
    }

    public static function isEnabled(): bool
    {
        $value = get_option(self::OPTION_DEPOSITS_ENABLED, 'yes');
        return $value === 'yes';
    }
}
