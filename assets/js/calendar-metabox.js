/**
 * Google Calendar Metabox JavaScript
 * Handles Create/Reserve/Delete event buttons with AJAX and polling
 */
(function() {
    'use strict';
    
    console.log('[Calendar] Script loading...');
    
    // Wait for DOM and config to be ready
    if (typeof zsCalendarConfig === 'undefined') {
        console.error('[Calendar] zsCalendarConfig not found');
        return;
    }
    
    const config = zsCalendarConfig;
    console.log('[Calendar] Config loaded:', config);
    
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
        console.log('[Calendar] Found sync buttons:', syncButtons.length);
        
        syncButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                console.log('[Calendar] Sync button clicked');
                e.preventDefault();
                
                if (this.disabled) {
                    console.log('[Calendar] Button is disabled, ignoring click');
                    return;
                }
                
                const orderId = this.getAttribute('data-order-id');
                const labelEl = this.querySelector('.zs-calendar-btn-label');
                const originalText = labelEl ? labelEl.textContent : '';
                const button = this;
                
                console.log('[Calendar] Order ID:', orderId);
                console.log('[Calendar] Label element:', labelEl);
                console.log('[Calendar] Original text:', originalText);
                
                const confirmMsg = 'Sync event to Google Calendar?';
                if (!confirm(confirmMsg)) {
                    console.log('[Calendar] User cancelled confirmation');
                    return;
                }
                
                console.log('[Calendar] User confirmed, disabling button and changing text');
                button.disabled = true;
                if (labelEl) {
                    labelEl.textContent = 'Processing...';
                }
                
                console.log('[Calendar] Sending AJAX request to:', config.ajaxUrl);
                
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
                .then(function(r) {
                    console.log('[Calendar] AJAX response received:', r);
                    return r.json();
                })
                .then(function(data) {
                    console.log('[Calendar] AJAX data:', data);
                    // Reload page after 2 seconds to show changes
                    setTimeout(function() {
                        console.log('[Calendar] Reloading page...');
                        location.reload();
                    }, 2000);
                })
                .catch(function(err) {
                    console.error('[Calendar] AJAX error:', err);
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
        console.log('[Calendar] Document still loading, waiting for DOMContentLoaded...');
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[Calendar] DOMContentLoaded fired, attaching listeners...');
            attachButtonListeners();
            attachSaveNotesListener();
            console.log('[Calendar] All listeners attached');
        });
    } else {
        console.log('[Calendar] Document already loaded, attaching listeners immediately...');
        attachButtonListeners();
        attachSaveNotesListener();
        console.log('[Calendar] All listeners attached');
    }
})();
