<?php
declare(strict_types=1);

/**
 * Vista para la página pública de Shopping List
 * 
 * @var string $from
 * @var string $to
 * @var int $loc
 * @var array $locations
 * @var array $preOrders
 * @var array $preItemKeys
 * @var array $preList
 */
?>
<div class="zs-sl" id="zs-sl">
    <div class="zs-sl__filters no-print">
        <div class="zs-sl__filter-row">
            <label class="zs-sl__filter-group" for="zs-sl-from">
                <span class="zs-sl__label"><?php esc_html_e('Des de', 'zero-sense'); ?></span>
                <input class="zs-sl__input" type="date" id="zs-sl-from" value="<?php echo esc_attr($from); ?>">
            </label>
            <label class="zs-sl__filter-group" for="zs-sl-to">
                <span class="zs-sl__label"><?php esc_html_e('Fins a', 'zero-sense'); ?></span>
                <input class="zs-sl__input" type="date" id="zs-sl-to" value="<?php echo esc_attr($to); ?>">
            </label>
            <div class="zs-sl__filter-group zs-sl__filter-group--full">
                <label class="zs-sl__label" for="zs-sl-loc"><?php esc_html_e('Localització', 'zero-sense'); ?></label>
                <select class="zs-sl__select" id="zs-sl-loc">
                    <option value=""><?php esc_html_e('Selecciona...', 'zero-sense'); ?></option>
                    <?php foreach ($locations as $term) : if (!$term instanceof \WP_Term) { continue; } ?>
                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($loc, $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="zs-sl__filter-group zs-sl__filter-group--full">
                <button type="button" class="btn--primary zs-sl__search-btn" id="zs-sl-search"><?php esc_html_e('Cercar comandes', 'zero-sense'); ?></button>
            </div>
        </div>
    </div>
    
    <div class="zs-sl__body" id="zs-sl-body">
        <?php if (!empty($preOrders)) : ?>
            <?php 
                // Render orders panel
                require __DIR__ . '/shopping-list-orders.php'; 
            ?>
            <div id="zs-sl-list-wrap">
                <?php 
                    // Render list
                    require __DIR__ . '/shopping-list-ingredients.php'; 
                ?>
            </div>
        <?php else : ?>
            <div class="zs-sl__empty" id="zs-sl-empty">
                <p><?php esc_html_e('Selecciona un rang de dates i una localització per veure la llista de la compra.', 'zero-sense'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="zs-sl__loading" id="zs-sl-loading" style="display:none;">
        <span><?php esc_html_e('Carregant...', 'zero-sense'); ?></span>
    </div>
</div>
