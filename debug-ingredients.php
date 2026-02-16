// Script para probar AJAX de ingredientes
// Copiar y pegar en la consola del navegador estando en el admin de WordPress

jQuery.post(ajaxurl, {
    action: 'zs_ingredient_search',
    nonce: '<?php echo wp_create_nonce('zs_ingredient_ajax'); ?>',
    q: ''
}, function(response) {
    console.log('Search response:', response);
});

jQuery.post(ajaxurl, {
    action: 'zs_ingredient_create',
    nonce: '<?php echo wp_create_nonce('zs_ingredient_ajax'); ?>',
    name: 'test_ingredient_debug'
}, function(response) {
    console.log('Create response:', response);
});
