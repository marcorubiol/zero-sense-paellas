<?php
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;

if (!defined('ABSPATH')) {
    exit;
}

$initialChoice = $initial_choice ?? 'deposit';
$depositAmountRaw = $deposit_amount ?? 0;
$fullAmountRaw = $order_total ?? 0;
$depositDisplay = $deposit_display ?? wc_price($depositAmountRaw);
$fullDisplay = $full_display ?? wc_price($fullAmountRaw);
$currencySettings = $currency_settings ?? [
    'symbol' => get_woocommerce_currency_symbol(),
    'position' => get_option('woocommerce_currency_pos'),
    'decimal_separator' => wc_get_price_decimal_separator(),
    'thousand_separator' => wc_get_price_thousand_separator(),
    'decimals' => wc_get_price_decimals(),
];
$depositPercentageDisplay = $deposit_percentage_display ?? 0;
$orderId = $order_id ?? 0;
?>
<div id="zs-deposits-payment-options" class="payment-options">
    <h3 class="payment-options__title"><?php esc_html_e('Decide how much to pay now', 'zero-sense'); ?></h3>
    <p class="payment-options__description"><?php esc_html_e('Select whether to pay the deposit or the full amount now:', 'zero-sense'); ?></p>
    <ul class="payment-options__list">
        <li class="payment-options__list-item">
            <label for="zs_deposits_payment_choice_deposit">
                <input type="radio"
                       id="zs_deposits_payment_choice_deposit"
                       name="zs_deposits_payment_choice"
                       value="deposit"
                       <?php checked('deposit', $initialChoice); ?>
                       data-raw-price="<?php echo esc_attr($depositAmountRaw); ?>"
                       data-formatted-price="<?php echo esc_attr($depositDisplay); ?>"
                >
                <?php echo sprintf(
                    esc_html__('Pay Deposit (%s%%): %s', 'zero-sense'),
                    esc_html($depositPercentageDisplay),
                    '<span class="wd-price-amount">' . wp_kses_post($depositDisplay) . '</span>'
                ); ?>
            </label>
        </li>
        <li class="payment-options__list-item">
            <label for="zs_deposits_payment_choice_full">
                <input type="radio"
                       id="zs_deposits_payment_choice_full"
                       name="zs_deposits_payment_choice"
                       value="full"
                       <?php checked('full', $initialChoice); ?>
                       data-raw-price="<?php echo esc_attr($fullAmountRaw); ?>"
                       data-formatted-price="<?php echo esc_attr($fullDisplay); ?>"
                >
                <?php echo sprintf(
                    esc_html__('Pay Full Amount: %s', 'zero-sense'),
                    '<span class="wd-price-amount">' . wp_kses_post($fullDisplay) . '</span>'
                ); ?>
            </label>
        </li>
    </ul>

    <div id="zs-deposits-payment-summary" class="payment-summary">
        <p class="payment-summary__text">
            <?php esc_html_e('Amount to pay now:', 'zero-sense'); ?>
            <span class="payment-summary__amount">
                <strong>
                    <span id="zs-deposits-summary-price-value">
                        <?php echo $initialChoice === 'full' ? wp_kses_post($fullDisplay) : wp_kses_post($depositDisplay); ?>
                    </span>
                </strong>
            </span>
        </p>
    </div>

    <div id="zs-deposits-payment-feedback" class="payment-options__feedback"></div>
</div>

<input type="hidden" id="zs_deposits_payment_choice_submit" name="zs_deposits_payment_choice_submit" value="<?php echo esc_attr($initialChoice); ?>">
