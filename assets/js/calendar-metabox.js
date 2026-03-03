/**
 * Google Calendar Metabox JavaScript
 * Handles Create/Reserve/Delete event buttons with AJAX and polling
 */
(function() {
    'use strict';
    
    // Wait for DOM and config to be ready
    if (typeof zsCalendarConfig === 'undefined') {
        console.error('[Calendar] zsCalendarConfig not found');
        return;
    }
    
    const config = zsCalendarConfig;
    
    function attachButtonListeners() {
        const buttons = document.querySelectorAll('.zs-calendar-action-btn');
        
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (this.disabled) return;
                
                const action = this.getAttribute('data-action');
                const orderId = this.getAttribute('data-order-id');
                const labelEl = this.querySelector('.zs-calendar-btn-label');
                const originalText = labelEl.textContent;
                const button = this;
                
                // Get confirmation message
                let confirmMsg, loadingText;
                if (action === 'delete') {
                    confirmMsg = config.i18n.confirmDelete;
                    loadingText = config.i18n.loadingDelete;
                } else if (action === 'update') {
                    confirmMsg = config.i18n.confirmUpdate;
                    loadingText = config.i18n.loadingUpdate;
                } else {
                    confirmMsg = config.i18n.confirmCreate;
                    loadingText = config.i18n.loadingCreate;
                }
                
                if (!confirm(confirmMsg)) return;
                
                // Update button state
                button.disabled = true;
                labelEl.textContent = loadingText;
                
                // Trigger workflow via AJAX
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'zs_calendar_' + action + '_event',
                        order_id: orderId,
                        nonce: config.nonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    // Start polling for changes
                    let attempts = 0;
                    const maxAttempts = 10;
                    
                    const poll = function() {
                        fetch(config.ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'zs_calendar_check_status',
                                order_id: orderId,
                                check_action: action,
                                nonce: config.nonce
                            })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success && res.data && res.data.changed) {
                                // If it was an update (reserve) action, show RESERVED badge immediately
                                if (action === 'update') {
                                    const actionsDiv = button.closest('.zs-calendar-actions');
                                    if (actionsDiv) {
                                        // Remove the Reserve button
                                        button.remove();
                                        
                                        // Add RESERVED badge
                                        const badge = document.createElement('span');
                                        badge.className = 'zs-badge zs-badge-reserved';
                                        badge.textContent = 'RESERVED';
                                        
                                        // Insert badge before delete button
                                        const deleteBtn = actionsDiv.querySelector('[data-action="delete"]');
                                        if (deleteBtn) {
                                            actionsDiv.insertBefore(badge, deleteBtn);
                                        }
                                    }
                                }
                                
                                // Reload the page to show new logs
                                setTimeout(function() {
                                    location.reload();
                                }, 500);
                            } else if (attempts < maxAttempts) {
                                attempts++;
                                setTimeout(poll, 1000);
                            } else {
                                // Timeout, restore button
                                button.disabled = false;
                                labelEl.textContent = originalText;
                            }
                        })
                        .catch(function(err) {
                            attempts++;
                            if (attempts < maxAttempts) {
                                setTimeout(poll, 1000);
                            } else {
                                button.disabled = false;
                                labelEl.textContent = originalText;
                            }
                        });
                    };
                    
                    setTimeout(poll, 1000);
                })
                .catch(function(err) {
                    button.disabled = false;
                    labelEl.textContent = originalText;
                    alert('Error: ' + err.message);
                });
            });
        });
    }
    
    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachButtonListeners);
    } else {
        attachButtonListeners();
    }
})();
