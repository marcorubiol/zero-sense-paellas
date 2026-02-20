<?php
declare(strict_types=1);

/**
 * Vista para la lista de ingredientes agregados
 * 
 * @var array $preList
 */

if (empty($preList)) {
    echo '<div class="zs-sl__list-empty"><p>' . esc_html__('No hi ha ingredients per a les comandes seleccionades.', 'zero-sense') . '</p></div>';
    return;
}

usort($preList, function (array $a, array $b): int { 
    return strcmp($a['name'], $b['name']); 
});
?>
<div class="zs-sl__list print-only" id="zs-sl-list">
    <div class="zs-sl__list-header">
        <h3 class="zs-sl__list-title"><?php esc_html_e('Llista de la compra', 'zero-sense'); ?></h3>
        <svg class="zs-sl__list-icon" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1"/>
            <circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
    </div>
    
    <div class="zs-sl__list-items">
        <?php foreach ($preList as $item) : ?>
            <div class="zs-sl__list-item">
                <span class="zs-sl__item-name"><?php echo esc_html($item['name']); ?></span>
                <span class="zs-sl__item-qty-wrap">
                    <span class="zs-sl__item-qty"><?php echo esc_html($this->formatNumber($item['qty'])); ?></span>
                    <span class="zs-sl__item-unit"><?php echo esc_html($item['unit']); ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
