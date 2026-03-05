/**
 * Zero Sense v3.0 Admin JavaScript - Modular Version
 * 
 * This is the modular entry point that loads specialized modules.
 * 
 * Modules:
 * - admin-tabs.js: Tab navigation ✓
 * - admin-toggles.js: Feature toggles with AJAX ✓
 * - admin-config.js: Configuration panels and form handling ✓
 * - admin-flowmattic.js: Flowmattic CRUD operations ✓
 * 
 * Total reduction: ~1400 lines → 4 modules (~200-400 lines each)
 */

// Use vanilla JS to avoid jQuery dependency issues
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Initialize dashboard
    ZeroSenseAdmin.init();
});

// Fallback for jQuery if available
if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function($) {
        'use strict';
        // Initialize dashboard
        ZeroSenseAdmin.init();
    });
}

/**
 * Main Admin Coordinator
 * Delegates to specialized modules
 */
const ZeroSenseAdmin = {
    /**
     * Initialize the admin interface
     */
    init: function() {
        // Guard against double-initialization
        if (this._initialized) {
            return;
        }
        this._initialized = true;
        
        // Add JS class to body
        document.body.classList.add('js');
        
        // Initialize modules in order
        if (typeof ZeroSenseTabs !== 'undefined') {
            ZeroSenseTabs.init();
        }
        
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.init();
        }
        
        if (typeof ZeroSenseConfig !== 'undefined') {
            ZeroSenseConfig.init();
        }
        
        if (typeof ZeroSenseFlowmattic !== 'undefined') {
            ZeroSenseFlowmattic.init();
        }
        
        // Initialize clear cache button
        this.initClearCacheButton();
    },
    
    /**
     * Initialize clear cache button
     */
    initClearCacheButton: function() {
        const btn = document.getElementById('zs-clear-cache-btn');
        if (!btn) return;
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Show loading state
            const icon = btn.querySelector('.dashicons');
            const originalText = btn.textContent.trim();
            btn.disabled = true;
            icon.classList.add('zs-spin');
            
            // Make AJAX request
            fetch(zsAdmin.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'zs_clear_cache',
                    nonce: zsAdmin.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message briefly
                    btn.innerHTML = '<span class="dashicons dashicons-yes"></span> Cleared!';
                    btn.style.background = '#00a32a';
                    btn.style.borderColor = '#00a32a';
                    btn.style.color = '#fff';
                    
                    // Reload page after 800ms
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    // Show error
                    btn.innerHTML = '<span class="dashicons dashicons-warning"></span> Error';
                    btn.style.background = '#d63638';
                    btn.style.borderColor = '#d63638';
                    btn.style.color = '#fff';
                    
                    // Reset after 2s
                    setTimeout(function() {
                        btn.innerHTML = '<span class="dashicons dashicons-update"></span> ' + originalText;
                        btn.style.background = '';
                        btn.style.borderColor = '';
                        btn.style.color = '';
                        btn.disabled = false;
                        icon.classList.remove('zs-spin');
                    }, 2000);
                }
            })
            .catch(error => {
                console.error('Clear cache error:', error);
                btn.innerHTML = '<span class="dashicons dashicons-warning"></span> Error';
                btn.style.background = '#d63638';
                btn.style.borderColor = '#d63638';
                btn.style.color = '#fff';
                
                setTimeout(function() {
                    btn.innerHTML = '<span class="dashicons dashicons-update"></span> ' + originalText;
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                    btn.disabled = false;
                    icon.classList.remove('zs-spin');
                }, 2000);
            });
        });
    },
    
    // Re-export commonly used methods for backward compatibility
    showMessage: function(message, type) {
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showMessage(message, type);
        }
    },
    
    showCardMessage: function(card, message, type) {
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showCardMessage(card, message, type);
        }
    },
    
    showInlineFeedback: function(card, selector, message, type) {
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showInlineFeedback(card, selector, message, type);
        }
    },
    
    // Re-export Flowmattic helpers for backward compatibility
    createEditForm: function(li, currentTag, currentWid) {
        if (typeof ZeroSenseFlowmattic !== 'undefined') {
            ZeroSenseFlowmattic.createEditForm(li, currentTag, currentWid);
        }
    },
    
    updateEmailFieldsForActionType: function(isClass, isStatus, isEmailChecked) {
        if (typeof ZeroSenseFlowmattic !== 'undefined') {
            ZeroSenseFlowmattic.updateEmailFieldsForActionType(isClass, isStatus, isEmailChecked);
        }
    },
    
    updateGeneratedClass: function(form, currentTag) {
        if (typeof ZeroSenseFlowmattic !== 'undefined') {
            ZeroSenseFlowmattic.updateGeneratedClass(form, currentTag);
        }
    },
    
    updateMainFormGeneratedClass: function() {
        if (typeof ZeroSenseFlowmattic !== 'undefined') {
            ZeroSenseFlowmattic.updateMainFormGeneratedClass();
        }
    },
    
    // Re-export Config helpers for backward compatibility
    validateSettingsIcon: function(card) {
        if (typeof ZeroSenseConfig !== 'undefined') {
            ZeroSenseConfig.validateSettingsIcon(card);
        }
    },
    
    markSettingsIconDirty: function(card) {
        if (typeof ZeroSenseConfig !== 'undefined') {
            ZeroSenseConfig.markSettingsIconDirty(card);
        }
    },
    
    saveConfiguration: function(button) {
        if (typeof ZeroSenseConfig !== 'undefined') {
            ZeroSenseConfig.saveConfiguration(button);
        }
    }
};
