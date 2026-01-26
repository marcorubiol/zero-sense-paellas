/**
 * Add loading indicator to order-pay page for better UX
 */
(function() {
    'use strict';
    
    function addLoadingIndicator() {
        // Only on order-pay pages
        if (!document.body.classList.contains('woocommerce-order-pay')) {
            return;
        }
        
        const form = document.getElementById('order_review');
        if (!form) {
            return;
        }
        
        // Find submit button
        const submitBtn = form.querySelector('#place_order, button[type="submit"], input[type="submit"]');
        if (!submitBtn) {
            return;
        }
        
        let isSubmitting = false;
        let overlay = null;
        
        // Show overlay when form submits
        form.addEventListener('submit', function(e) {
            if (!isSubmitting && !overlay) {
                showLoadingOverlay();
            }
        });
        
        function showLoadingOverlay() {
            if (isSubmitting || overlay) {
                return;
            }
            
            isSubmitting = true;
            
            // Disable button
            submitBtn.disabled = true;
            
            // Create loading overlay
            overlay = document.createElement('div');
            overlay.id = 'zs-order-pay-loading';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.9);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
            `;
            
            const spinner = document.createElement('div');
            spinner.style.cssText = `
                border: 6px solid #f3f3f3;
                border-top: 6px solid #0073aa;
                border-radius: 50%;
                width: 60px;
                height: 60px;
                animation: spin 0.8s linear infinite;
            `;
            
            // Add CSS animation
            const style = document.createElement('style');
            style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
            document.head.appendChild(style);
            
            overlay.appendChild(spinner);
            document.body.appendChild(overlay);
            
            // Fallback: remove overlay after 30 seconds (in case something goes wrong)
            setTimeout(function() {
                if (overlay && overlay.parentNode) {
                    overlay.remove();
                    overlay = null;
                    submitBtn.disabled = false;
                    isSubmitting = false;
                }
            }, 30000);
        }
    }
    
    // Execute on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addLoadingIndicator);
    } else {
        addLoadingIndicator();
    }
})();
