/**
 * Zero Sense - Tabs Module
 * Handles tab navigation in the admin dashboard
 */

const ZeroSenseTabs = {
    /**
     * Initialize tab functionality
     */
    init: function() {
        const tabs = document.querySelectorAll('.zs-categories-nav .zs-tab');
        const contents = document.querySelectorAll('.zs-category-content');

        if (tabs.length === 0 || contents.length === 0) {
            return;
        }

        // Tab click handler
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                const category = this.getAttribute('data-category');
                
                // Don't do anything if already active
                if (this.classList.contains('zs-tab-active')) {
                    return;
                }
                
                // Update active tab
                tabs.forEach(function(t) {
                    t.classList.remove('zs-tab-active');
                });
                this.classList.add('zs-tab-active');
                
                // Update active content
                contents.forEach(function(content) {
                    content.classList.remove('active');
                });
                
                const targetContent = document.getElementById('zs-category-' + category);
                if (targetContent) {
                    targetContent.classList.add('active');
                }
                
                // Store active tab in localStorage
                localStorage.setItem('zs-active-tab', category);
            });
        });

        // Restore active tab from localStorage or show first tab
        const activeTab = localStorage.getItem('zs-active-tab');
        let tabToActivate = null;
        
        if (activeTab) {
            tabToActivate = document.querySelector('[data-category="' + activeTab + '"]');
        }
        
        // If no saved tab or saved tab doesn't exist, use first tab
        if (!tabToActivate && tabs[0]) {
            tabToActivate = tabs[0];
        }
        
        // Activate the tab
        if (tabToActivate) {
            tabToActivate.click();
        }
    }
};

// Export for use in main admin.js
if (typeof window !== 'undefined') {
    window.ZeroSenseTabs = ZeroSenseTabs;
}
