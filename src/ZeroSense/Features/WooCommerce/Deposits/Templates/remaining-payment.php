<?php
if (!defined('ABSPATH')) {
    exit;
}

$depositDisplay = $deposit_display ?? '';
$remainingDisplay = $remaining_display ?? '';
?>
<div id="zs-deposits-payment-options" class="payment-options">
    <h3 class="payment-options__title"><?php esc_html_e('Complete Your Payment', 'zero-sense'); ?></h3>
    <p class="payment-options__description">
        <?php
        echo wp_kses_post(sprintf(
            /* translators: %1$s deposit amount */
            __('We\'ve received your deposit of %1$s. You can pay the remaining balance anytime via this page. If you prefer, you can also pay in cash on the day of the event, when the cooking team arrives.', 'zero-sense'),
            '<strong>' . wp_kses_post($depositDisplay) . '</strong>'
        ));
        ?>
    </p>

    <div class="payment-options__summary">
        <p class="payment-summary__text">
            <?php esc_html_e('Remaining balance to be paid:', 'zero-sense'); ?>
            <span class="payment-summary__amount"><strong><?php echo wp_kses_post($remainingDisplay); ?></strong></span>
        </p>
    </div>
</div>
<input type="hidden" id="zs_deposits_payment_choice_submit" name="zs_deposits_payment_choice_submit" value="remaining">
