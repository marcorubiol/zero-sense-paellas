/**
 * Zero Sense - Toggles Module
 * Handles feature enable/disable toggles with AJAX auto-save
 */

const ZeroSenseToggles = {
    /**
     * Initialize toggle switches with auto-save
     */
    init: function() {
        const toggles = document.querySelectorAll('.zs-toggle-switch input');

        toggles.forEach((toggle) => {
            toggle.addEventListener('change', function() {
                const card = this.closest('.zs-feature-card');
                const optionName = this.name; // This is actually the option name, not feature name
                const isEnabled = this.checked;

                if (card) {
                    // Update card visual state
                    if (isEnabled) {
                        card.classList.add('active');
                        card.classList.remove('inactive');
                    } else {
                        card.classList.add('inactive');
                        card.classList.remove('active');
                    }

                    // Handle config section visibility - only affect settings panels, not info panels
                    const configSection = card.querySelector('.zs-feature-config:not(.zs-feature-info)');
                    if (configSection) {
                        if (isEnabled) {
                            configSection.classList.remove('zs-config-hidden');
                            configSection.classList.add('zs-config-visible');
                        } else {
                            configSection.classList.remove('zs-config-visible');
                            configSection.classList.add('zs-config-hidden');
                        }
                    }
                }

                // Auto-save the toggle state
                ZeroSenseToggles.saveToggleState(optionName, isEnabled, card);
            });
        });
    },

    /**
     * Save toggle state via AJAX
     */
    saveToggleState: function(optionName, isEnabled, card) {
        if (typeof zsAdmin === 'undefined') {
            alert('Error: AJAX configuration not loaded. Please refresh the page.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'zs_toggle_feature');
        formData.append('feature', optionName);
        formData.append('enabled', isEnabled ? '1' : '0');
        formData.append('nonce', zsAdmin.nonce);

        fetch(zsAdmin.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const statusMessage = isEnabled ? 'Enabled' : 'Disabled';
                const messageType = isEnabled ? 'success' : 'warning';
                this.showInlineFeedback(card, '.zs-toggle-feedback', statusMessage, messageType);
            } else {
                this.showInlineFeedback(card, '.zs-toggle-feedback', 'Error: ' + data.data, 'error');
            }
        })
        .catch(error => {
            this.showInlineFeedback(card, '.zs-toggle-feedback', 'Network error: ' + error.message, 'error');
        });
    },

    /**
     * Show temporary message to user
     */
    showMessage: function(message, type) {
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
        if (!card) {
            this.showMessage(message, type);
            return;
        }
        // Fallback: use first feedback chip in card
        this.showInlineFeedback(card, '.zs-feature-feedback', message, type);
    },

    /**
     * Show an inline feedback chip inside the card without altering layout
     */
    showInlineFeedback: function(card, selector, message, type) {
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
    window.ZeroSenseToggles = ZeroSenseToggles;
}
