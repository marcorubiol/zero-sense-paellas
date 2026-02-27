/**
 * UX/UI improvements for Billing/Shipping Admin Fields 
 * HPOS Compatible
 */
jQuery(document).ready(function ($) {
    
    // We already have a title replacement in admin-section-titles.js, 
    // but just to be sure we're covering all scenarios (view mode vs edit mode), 
    // we can add a targeted replacement here or rely on the other file.
    
    // The main job here is to ensure the Customer Provided Note looks clean 
    // in both 'edit' and 'view' modes of the HPOS order edit screen.
    
    function refineOrderDataLayout() {
        var $orderDataColumn3 = $('#order_data .order_data_column:nth-child(3)');
        
        if ($orderDataColumn3.length) {
            // Find the "Customer provided note:" label and give it some styling if needed
            var $noteLabel = $orderDataColumn3.find('h3');
            if ($noteLabel.length === 0) {
                // Sometime the label is just text, let's wrap it nicely if it's naked
                 $orderDataColumn3.prepend('<h3>Customer Notes</h3>');
            }
        }
    }

    refineOrderDataLayout();
    
    // Wait for WooCommerce scripts that might alter DOM
    setTimeout(refineOrderDataLayout, 500);
});
