<?php
/**
 * Zero Sense Shortcodes
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register shortcode
 */
function zero_sense_register_shortcodes() {
    add_shortcode('zero_sense', 'zero_sense_shortcode');
    add_shortcode('paellas', 'zero_sense_shortcode'); // Add an alternative shortcode name
}
add_action('init', 'zero_sense_register_shortcodes');

/**
 * Zero Sense shortcode callback
 */
function zero_sense_shortcode($atts, $content = null) {
    // Parse attributes
    $atts = shortcode_atts(
        array(
            'title' => __('Paellas en Casa', 'zero-sense'),
            'button_text' => __('Ver Paellas', 'zero-sense'),
            'button_url' => home_url('/paellas'),
        ),
        $atts,
        'zero_sense'
    );
    
    // Enqueue needed styles and scripts
    wp_enqueue_style('zero-sense', ZERO_SENSE_URL . 'assets/css/zero-sense.css', array(), ZERO_SENSE_VERSION);
    wp_enqueue_script('zero-sense', ZERO_SENSE_URL . 'assets/js/zero-sense.js', array('jquery'), ZERO_SENSE_VERSION, true);
    
    // Start output buffering
    ob_start();
    ?>
    <div class="zero-sense-container">
        <h2 class="zero-sense-title"><?php echo esc_html($atts['title']); ?></h2>
        <?php if (!empty($content)) : ?>
            <div class="zero-sense-content"><?php echo wp_kses_post($content); ?></div>
        <?php endif; ?>
        <a href="<?php echo esc_url($atts['button_url']); ?>" class="zero-sense-button"><?php echo esc_html($atts['button_text']); ?></a>
    </div>
    <?php
    // Return the buffered content
    return ob_get_clean();
} 