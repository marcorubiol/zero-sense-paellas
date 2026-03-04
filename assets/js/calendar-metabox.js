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
    
    function attachSaveNotesListener() {
        const saveBtn = document.querySelector('.zs-save-calendar-notes-btn');
        if (!saveBtn) return;
        
        saveBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (this.disabled) return;
            
            const orderId = this.getAttribute('data-order-id');
            const textarea = document.getElementById('zs_calendar_notes');
            const statusEl = document.querySelector('.zs-calendar-notes-status');
            const originalText = this.textContent;
            
            if (!textarea) return;
            
            this.disabled = true;
            this.textContent = 'Saving...';
            statusEl.style.display = 'none';
            
            fetch(config.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'zs_calendar_save_notes',
                    order_id: orderId,
                    notes: textarea.value,
                    nonce: config.nonce
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    statusEl.textContent = '✓ ' + (data.data.message || 'Saved');
                    statusEl.style.display = 'inline';
                    setTimeout(function() {
                        statusEl.style.display = 'none';
                    }, 3000);
                } else {
                    alert('Error: ' + (data.data || 'Unknown error'));
                }
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            })
            .catch(function(err) {
                alert('Error: ' + err.message);
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            });
        });
    }
    
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
                                // Reload page when change detected
                                location.reload();
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
        document.addEventListener('DOMContentLoaded', function() {
            attachButtonListeners();
            attachSaveNotesListener();
        });
    } else {
        attachButtonListeners();
        attachSaveNotesListener();
    }
})();
