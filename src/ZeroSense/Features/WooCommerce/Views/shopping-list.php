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
    <header class="zs-sl__header no-print">
        <h3 class="zs-sl__header-title">
            <svg class="zs-sl__header-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            <?php esc_html_e('Llista de la compra', 'zero-sense'); ?>
        </h3>
        <p class="zs-sl__header-sub"><?php esc_html_e('Ingredients agregats per rang de dates i localització', 'zero-sense'); ?></p>
    </header>
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
