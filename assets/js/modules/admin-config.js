/**
 * Zero Sense - Configuration Module
 * Handles feature configuration panels, forms, and validation
 */

const ZeroSenseConfig = {
    /**
     * Initialize all configuration handlers
     */
    init: function() {
        this.initHeaderIcons();
        this.initConfigHandlers();
        this.initFormHandling();
    },

    /**
     * Initialize compact header icons (settings/info) to toggle panels
     */
    initHeaderIcons: function() {
        document.addEventListener('click', function(e) {
            const iconBtn = e.target.closest('.zs-card-icon');
            if (!iconBtn) return;
            e.preventDefault();
            e.stopPropagation();

            const selector = iconBtn.getAttribute('data-target');
            if (!selector) return;
            const panel = document.querySelector(selector);
            if (!panel) return;

            const container = panel.closest('.zs-feature-config');
            const header = container ? container.querySelector('.zs-config-toggle-header') : null;
            if (!container || !header) return;

            const expanded = header.getAttribute('aria-expanded') === 'true';
            const nextState = !expanded;

            header.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            iconBtn.setAttribute('aria-expanded', nextState ? 'true' : 'false');

            container.classList.toggle('zs-config-collapsed', !nextState);
            if (nextState) {
                panel.hidden = false;
                try { panel.style.removeProperty('display'); } catch (_) { panel.style.display = ''; }
            } else {
                panel.hidden = true;
                try { panel.style.setProperty('display', 'none', 'important'); } catch (_) { panel.style.display = 'none'; }
            }

            const tinyToggle = header.querySelector('.zs-config-toggle');
            if (tinyToggle) {
                tinyToggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
                tinyToggle.classList.toggle('is-collapsed', !nextState);
            }

            // Ensure only one panel open at a time within the same feature card
            if (nextState) {
                const card = iconBtn.closest('.zs-feature-card');
                if (card) {
                    const allPanels = card.querySelectorAll('.zs-feature-config');
                    allPanels.forEach(function(p) {
                        if (p === container) return; // skip current
                        const pHeader = p.querySelector('.zs-config-toggle-header');
                        const pFields = p.querySelector('.zs-config-fields');
                        // Collapse panel
                        p.classList.add('zs-config-collapsed');
                        if (pFields) {
                            pFields.hidden = true;
                            try { pFields.style.setProperty('display', 'none', 'important'); } catch (_) { pFields.style.display = 'none'; }
                        }
                        if (pHeader) {
                            pHeader.setAttribute('aria-expanded', 'false');
                        }
                        // Sync corresponding header icon aria-expanded=false
                        const targetId = pFields && pFields.id ? ('#' + pFields.id) : null;
                        if (targetId) {
                            const relatedIcon = card.querySelector('.zs-card-icon[data-target="' + targetId.replace(/"/g, '\\"') + '"]');
                            if (relatedIcon) relatedIcon.setAttribute('aria-expanded', 'false');
                        }
                        // Sync tiny chevron state
                        const pTiny = pHeader ? pHeader.querySelector('.zs-config-toggle') : null;
                        if (pTiny) {
                            pTiny.setAttribute('aria-expanded', 'false');
                            pTiny.classList.add('is-collapsed');
                        }
                    });
                    // If we opened the settings panel, revalidate to clear error if now satisfied
                    if (iconBtn.classList.contains('zs-card-settings')) {
                        ZeroSenseConfig.validateSettingsIcon(card);
                    }
                }
            }
        });
    },

    /**
     * Initialize configuration handlers
     */
    initConfigHandlers: function() {
        // Handle save configuration buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('zs-save-config-btn')) {
                e.preventDefault();
                ZeroSenseConfig.saveConfiguration(e.target);
            }
        });

        // Handle config collapse/expand per feature card
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.zs-config-toggle');
            const header = e.target.closest('.zs-config-toggle-header');
            
            if (!btn && !header) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            const targetHeader = header || btn.closest('.zs-config-header');
            const container = targetHeader ? targetHeader.parentElement : null; // .zs-feature-config
            if (!container) return;
            
            const toggleBtn = targetHeader.querySelector('.zs-config-toggle');
            const id = targetHeader.getAttribute('aria-controls');
            const fields = id ? document.getElementById(id) : container.querySelector('.zs-config-fields');
            if (!fields) return;

            const expanded = targetHeader.getAttribute('aria-expanded') === 'true';
            const nextState = !expanded;
            
            targetHeader.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            }
            
            // Toggle container class so CSS controls visibility consistently
            container.classList.toggle('zs-config-collapsed', !nextState);
            // Also set inline style as a fallback to guarantee the visual change
            if (fields) {
                if (nextState) {
                    fields.hidden = false;
                    fields.style.removeProperty('display');
                } else {
                    fields.hidden = true;
                    try { fields.style.setProperty('display', 'none', 'important'); } catch(_){ fields.style.display = 'none'; }
                }
            }
            // Update state class (icon-only)
            if (toggleBtn) {
                toggleBtn.classList.toggle('is-collapsed', !nextState);
            }
        });

        // Initialize all panels as collapsed
        try {
            const toggles = document.querySelectorAll('.zs-config-toggle');
            const headers = document.querySelectorAll('.zs-config-toggle-header');
            
            toggles.forEach(function(btn){
                const id = btn.getAttribute('aria-controls');
                if (!id) return;
                const container = btn.closest('.zs-feature-config');
                if (!container) return;
                // Always start collapsed
                container.classList.add('zs-config-collapsed');
                const fields = document.getElementById(id);
                if (fields) {
                    fields.hidden = true;
                    try { fields.style.setProperty('display', 'none', 'important'); } catch(_){ fields.style.display = 'none'; }
                }
                btn.setAttribute('aria-expanded', 'false');
                btn.classList.add('is-collapsed');
            });
            
            headers.forEach(function(header){
                header.setAttribute('aria-expanded', 'false');
                const btn = header.querySelector('.zs-config-toggle');
                if (btn) {
                    btn.classList.add('is-collapsed');
                }
            });
        } catch (_) {}

        // Mark settings icon dirty/error on config input changes
        document.addEventListener('input', function(e) {
            const input = e.target.closest('.zs-config-input');
            if (!input) return;
            const card = input.closest('.zs-feature-card');
            if (!card) return;
            ZeroSenseConfig.markSettingsIconDirty(card);
            ZeroSenseConfig.validateSettingsIcon(card);
        });
    },

    /**
     * Mark settings icon dirty when user changes any config input within the card
     */
    markSettingsIconDirty: function(card) {
        const icon = card.querySelector('.zs-card-settings');
        if (icon) icon.classList.add('is-dirty');
    },

    /**
     * Validate required fields and reflect error state on settings icon
     */
    validateSettingsIcon: function(card) {
        const icon = card.querySelector('.zs-card-settings');
        if (!icon) return;
        const requiredInputs = card.querySelectorAll('.zs-config-input[required]');
        let hasError = false;
        requiredInputs.forEach(function(inp){
            const v = (inp.value || '').trim();
            if (!v) hasError = true;
        });

        icon.classList.toggle('is-error', hasError);
    },

    /**
     * Save feature configuration via AJAX
     */
    saveConfiguration: function(button) {
        const featureName = button.getAttribute('data-feature');
        const card = button.closest('.zs-feature-card');
        const configInputs = card.querySelectorAll('.zs-config-input');
        
        // Collect configuration data
        const configData = {};
        configInputs.forEach(function(input) {
            configData[input.name] = input.value;
        });

        // Show loading state
        button.disabled = true;
        button.textContent = '💾 Saving...';

        // Check if zsAdmin is available
        if (typeof zsAdmin === 'undefined') {
            button.disabled = false;
            button.textContent = '💾 Save Configuration';
            this.showMessage('Error: AJAX configuration not loaded. Please refresh the page.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'zs_save_config');
        formData.append('feature', featureName);
        formData.append('nonce', zsAdmin.nonce);
        
        // Add config data
        Object.keys(configData).forEach(function(key) {
            formData.append('config[' + key + ']', configData[key]);
        });

        fetch(zsAdmin.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Reset button state
            button.disabled = false;
            button.textContent = '💾 Save Configuration';
            
            if (data.success) {
                ZeroSenseConfig.showCardMessage(card, 'Configuration saved successfully!', 'success');
                // Clear dirty flag on Settings icon and revalidate errors
                const sIcon = card.querySelector('.zs-card-settings');
                if (sIcon) sIcon.classList.remove('is-dirty');
                ZeroSenseConfig.validateSettingsIcon(card);
            } else {
                ZeroSenseConfig.showCardMessage(card, 'Error: ' + data.data, 'error');
            }
        })
        .catch(error => {
            button.disabled = false;
            button.textContent = '💾 Save Configuration';
            ZeroSenseConfig.showCardMessage(card, 'Network error: ' + error.message, 'error');
        });
    },

    /**
     * Initialize form handling
     */
    initFormHandling: function() {
        const form = document.querySelector('.zs-dashboard form');
        const submitButton = form ? form.querySelector('button[type="submit"]') : null;
        
        if (form && submitButton) {
            form.addEventListener('submit', function() {
                // Show loading state
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
                
                const dashboard = document.querySelector('.zs-dashboard');
                if (dashboard) {
                    dashboard.classList.add('zs-loading');
                }
            });
        }
    },

    /**
     * Show temporary message to user
     */
    showMessage: function(message, type) {
        // Delegate to toggles module if available
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showMessage(message, type);
            return;
        }
        
        // Fallback implementation
        const messageDiv = document.createElement('div');
        let background = 'var(--zs-color-status-danger)';

        if (type === 'success') {
            background = 'var(--zs-color-status-success)';
        } else if (type === 'warning') {
            background = 'var(--zs-color-status-warning)';
        }

        messageDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: var(--zs-color-white);
            font-weight: bold;
            z-index: 9999;
            max-width: 300px;
            background: ${background};
        `;
        messageDiv.textContent = message;

        document.body.appendChild(messageDiv);

        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.parentNode.removeChild(messageDiv);
            }
        }, 5000);
    },

    /**
     * Show message within a feature card
     */
    showCardMessage: function(card, message, type) {
        // Delegate to toggles module if available
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showCardMessage(card, message, type);
            return;
        }
        
        // Fallback
        if (!card) {
            this.showMessage(message, type);
            return;
        }
        this.showInlineFeedback(card, '.zs-feature-feedback', message, type);
    },

    /**
     * Show an inline feedback chip inside the card
     */
    showInlineFeedback: function(card, selector, message, type) {
        // Delegate to toggles module if available
        if (typeof ZeroSenseToggles !== 'undefined') {
            ZeroSenseToggles.showInlineFeedback(card, selector, message, type);
            return;
        }
        
        // Fallback
        if (!card) return this.showMessage(message, type);
        const target = card.querySelector(selector) || card.querySelector('.zs-feature-feedback');
        if (!target) return this.showMessage(message, type);

        target.textContent = message;
        target.classList.remove('is-success', 'is-warning', 'is-error', 'is-visible');

        if (type === 'success') {
            target.classList.add('is-success');
        } else if (type === 'warning') {
            target.classList.add('is-warning');
        } else {
            target.classList.add('is-error');
        }

        target.classList.add('is-visible');
        if (target._hideTimeout) clearTimeout(target._hideTimeout);
        target._hideTimeout = setTimeout(() => {
            target.classList.remove('is-visible');
            target.textContent = '';
        }, 3000);
    }
};

// Export for use in main admin.js
if (typeof window !== 'undefined') {
    window.ZeroSenseConfig = ZeroSenseConfig;
}
