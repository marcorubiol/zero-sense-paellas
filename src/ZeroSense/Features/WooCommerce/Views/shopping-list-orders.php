<?php
declare(strict_types=1);

/**
 * Vista para el panel de órdenes de Shopping List
 * 
 * @var array $preOrders
 * @var array $preItemKeys
 */
?>
<div class="zs-sl__list-actions">
    <button type="button" class="btn--neutral" id="zs-sl-print"><?php esc_html_e('Imprimir', 'zero-sense'); ?></button>
    <button type="button" class="btn--neutral btn--outline" id="zs-sl-share"><?php esc_html_e('Copiar enllaç', 'zero-sense'); ?></button>
</div>

<div class="zs-sl__orders no-print" id="zs-sl-orders">
    <div class="zs-sl__orders-actions">
        <button type="button" class="" id="zs-sl-check-all"><?php esc_html_e('Seleccionar tot', 'zero-sense'); ?></button>
        <button type="button" class="" id="zs-sl-uncheck-all"><?php esc_html_e('Desseleccionar tot', 'zero-sense'); ?></button>
    </div>
    
    <div class="zs-sl__orders-list" id="zs-sl-orders-list">
        <?php foreach ($preOrders as $o) :
            $orderId = (string) $o['id'];
            $allChecked = empty($preItemKeys) || !empty(array_filter($o['items'], function ($i) use ($preItemKeys) { 
                return in_array($i['key'], $preItemKeys, true); 
            }));
        ?>
            <div class="zs-sl__order-item" data-order-id="<?php echo esc_attr($orderId); ?>">
                <div class="zs-sl__order-row1">
                    <label class="zs-sl__switch" title="<?php esc_attr_e('Incloure comanda', 'zero-sense'); ?>">
                        <input type="checkbox" class="zs-sl__order-toggle" data-order-id="<?php echo esc_attr($orderId); ?>" <?php checked($allChecked); ?>>
                        <span class="zs-sl__switch-track"></span>
                    </label>
                    <span class="zs-sl__order-num">#<?php echo esc_html((string) $o['number']); ?></span>
                    <span class="zs-sl__order-customer"><?php echo esc_html($o['customer']); ?></span>
                    <span class="zs-sl__order-date"><?php echo esc_html($o['date']); ?></span>
                    <span class="zs-sl__order-guests"><?php echo esc_html((string) $o['guests']); ?> pax</span>
                </div>
                
                <div class="zs-sl__order-row2">
                    <?php foreach ($o['items'] as $item) : ?>
                        <label class="zs-sl__item-check-label">
                            <span class="zs-sl__switch">
                                <input type="checkbox" class="zs-sl__item-check" value="<?php echo esc_attr($item['key']); ?>" data-order-id="<?php echo esc_attr($orderId); ?>" <?php checked(empty($preItemKeys) || in_array($item['key'], $preItemKeys, true)); ?>>
                                <span class="zs-sl__switch-track"></span>
                            </span>
                            <span><?php echo esc_html($item['name']); ?><?php if ($item['qty'] > 1) : ?> ×<?php echo esc_html((string) $item['qty']); ?><?php endif; ?><?php if ($item['eq'] > 0) : ?> <span class="zs-sl__item-eq">· <?php echo esc_html((string) $item['eq']); ?> rac. eq.</span><?php endif; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
