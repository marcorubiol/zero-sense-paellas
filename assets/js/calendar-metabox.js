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
            
            const saveBtn = this;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
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
        // Sync buttons (Create, Reserve, Sync) - all trigger zs-calendar-sync
        const syncButtons = document.querySelectorAll('.zs-calendar-sync');
        
        syncButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.disabled) return;
                
                const orderId = this.getAttribute('data-order-id');
                const labelEl = this.querySelector('.zs-calendar-btn-label');
                const originalText = labelEl ? labelEl.textContent : '';
                const button = this;
                
                const confirmMsg = 'Sync event to Google Calendar?';
                if (!confirm(confirmMsg)) return;
                
                button.disabled = true;
                if (labelEl) {
                    labelEl.textContent = 'Processing...';
                }
                
                // Trigger AJAX to execute FlowMattic workflow
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'zs_calendar_sync_event',
                        order_id: orderId,
                        nonce: config.nonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                })
                .catch(function(err) {
                    alert('Error: ' + err.message);
                    button.disabled = false;
                    if (labelEl) {
                        labelEl.textContent = originalText;
                    }
                });
            });
        });
        
        // Reserve buttons - marks as reserved and triggers sync
        const reserveButtons = document.querySelectorAll('.zs-calendar-reserve');
        
        reserveButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.disabled) return;
                
                const orderId = this.getAttribute('data-order-id');
                const labelEl = this.querySelector('.zs-calendar-btn-label');
                const originalText = labelEl ? labelEl.textContent : '';
                const button = this;
                
                const confirmMsg = 'Mark event as reserved?';
                if (!confirm(confirmMsg)) return;
                
                button.disabled = true;
                if (labelEl) {
                    labelEl.textContent = 'Reserving...';
                }
                
                // Trigger AJAX to mark as reserved and sync
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'zs_calendar_reserve_event',
                        order_id: orderId,
                        nonce: config.nonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                })
                .catch(function(err) {
                    alert('Error: ' + err.message);
                    button.disabled = false;
                    if (labelEl) {
                        labelEl.textContent = originalText;
                    }
                });
            });
        });
        
        // Delete button - triggers zs-calendar-delete
        const deleteButtons = document.querySelectorAll('.zs-calendar-delete');
        deleteButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (this.disabled) return;
                
                const orderId = this.getAttribute('data-order-id');
                const labelEl = this.querySelector('.zs-calendar-btn-label');
                const originalText = labelEl.textContent;
                const button = this;
                
                const confirmMsg = config.i18n.confirmDelete || 'Delete event from Google Calendar?';
                if (!confirm(confirmMsg)) return;
                
                button.disabled = true;
                labelEl.textContent = 'Deleting...';
                
                // Trigger AJAX to execute FlowMattic workflow
                fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'zs_calendar_delete_event',
                        order_id: orderId,
                        nonce: config.nonce
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    // Reload page after 2 seconds to show changes
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                })
                .catch(function(err) {
                    alert('Error: ' + err.message);
                    button.disabled = false;
                    labelEl.textContent = originalText;
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
