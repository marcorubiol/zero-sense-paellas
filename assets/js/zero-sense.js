/**
 * Zero Sense Plugin JavaScript
 */

(function($) {
    'use strict';

    // Initialize the plugin
    $(document).ready(function() {
        // Plugin initialization code
        $('.zero-sense-button').on('click', function(e) {
            e.preventDefault();
            alert('Zero Sense button clicked!');
        });
    });

})(jQuery); 