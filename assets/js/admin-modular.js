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
