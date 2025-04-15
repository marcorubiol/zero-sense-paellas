<?php
/**
 * Bricks Builder customizations
 *
 * @package ZeroSense
 */

defined('ABSPATH') || exit;

/**
 * Add functions that should be automatically echoed in Bricks code elements
 */
add_filter('bricks/code/echo_function_names', function() {
  return [
    'post_title_formatted', 
    'date',
  ];
});

/**
 * Add form and select tags to allowed HTML tags in Bricks
 */
add_filter('bricks/allowed_html_tags', function($allowed_html_tags) {
    // Define the additional tags to be added
    $additional_tags = ['form', 'select'];

    // Merge additional tags with the existing allowed tags
    return array_merge($allowed_html_tags, $additional_tags);
}); 