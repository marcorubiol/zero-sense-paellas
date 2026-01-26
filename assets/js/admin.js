/**
 * Zero Sense v3.0 Admin JavaScript
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
 * Main Admin Object
 */
const ZeroSenseAdmin = {
    /**
     * Initialize the admin interface
     */
    init: function() {
        // Guard against double-initialization (e.g., DOMContentLoaded + jQuery ready)
        if (this._initialized) {
            return;
        }
        this._initialized = true;
        // Add JS class to body to indicate JavaScript is loaded
        document.body.classList.add('js');
        
        this.initTabs();
        this.initToggles();
        this.initFormHandling();
        this.initConfigHandlers();
        this.initHeaderIcons();
        this.initFlowmatticEmailConfig();
    },

    /**
     * Initialize tab functionality
     */
    initTabs: function() {
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
        
        // Activate the selected tab
        if (tabToActivate) {
            tabToActivate.click();
        }
    },

    /**
     * Initialize toggle switches with auto-save
     */
    initToggles: function() {
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
                ZeroSenseAdmin.saveToggleState(optionName, isEnabled, card);
            });
        });
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
                        ZeroSenseAdmin.validateSettingsIcon(card);
                    }
                }
            }
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
     * Save toggle state via AJAX
     */
    saveToggleState: function(optionName, isEnabled, card) {
        if (typeof zsAdmin === 'undefined') {
            console.error('ZeroSense: zsAdmin object not found! AJAX will fail.');
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
    },

    /**
     * Initialize configuration handlers
     */
    initConfigHandlers: function() {
        // Handle save configuration buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('zs-save-config-btn')) {
                e.preventDefault();
                ZeroSenseAdmin.saveConfiguration(e.target);
            }
        });

        // Note: Config visibility is now handled in initToggles to avoid duplicate events

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
            // Also set inline style as a fallback to guarantee the visual change (use !important)
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

            // Don't persist state - always start collapsed
        });

        // Initialize all panels as collapsed (no localStorage)
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
            ZeroSenseAdmin.markSettingsIconDirty(card);
            ZeroSenseAdmin.validateSettingsIcon(card);
        });

        // Flowmattic: Add new custom trigger from dashboard
        document.addEventListener('click', function(e) {
            const addBtn = e.target.closest('#zs-flow-add');
            if (!addBtn) return;
            e.preventDefault();

            if (typeof zsAdmin === 'undefined') {
                alert('Error: AJAX configuration not loaded. Please refresh the page.');
                return;
            }

            const idEl = document.getElementById('zs-flow-id');
            const tagEl = document.getElementById('zs-flow-tag');
            const titleEl = document.getElementById('zs-flow-title');
            const warnEl = document.getElementById('zs-flow-dup-warn');

            const wid = (idEl && idEl.value || '').trim();
            const tag = (tagEl && tagEl.value) || '';
            const titleVal = (titleEl && titleEl.value || '').trim();

            if (!wid || !tag) {
                alert('Please fill Workflow ID and Action Type.');
                return;
            }

            addBtn.disabled = true;
            const originalText = addBtn.textContent;
            addBtn.textContent = 'Adding…';

            const fd = new FormData();
            fd.append('action', 'zs_flow_add_trigger');
            fd.append('nonce', zsAdmin.nonce);
            fd.append('workflow_id', wid);
            fd.append('tag', tag);
            if (titleVal) fd.append('title', titleVal);
            
            // Email configuration
            const isEmailEl = document.getElementById('zs-flow-is-email');
            const emailDescEl = document.getElementById('zs-flow-email-desc');
            const sendOnceEl = document.getElementById('zs-flow-send-once');
            const manualStatesEl = document.getElementById('zs-flow-manual-states');
            
            if (isEmailEl && isEmailEl.checked) {
                fd.append('is_email', 'true');
                
                // Validate required email fields
                if (emailDescEl) {
                    const emailDescValue = emailDescEl.value.trim();
                    if (!emailDescValue) {
                        alert(tag === 'status' ? 'Email description is required when email features are enabled' : 'Button name is required when email features are enabled');
                        addBtn.disabled = false;
                        addBtn.textContent = originalText;
                        return;
                    }
                    fd.append('email_description', emailDescValue);
                }
                
                if (tag === 'status' && sendOnceEl && sendOnceEl.checked) {
                    fd.append('send_once', 'true');
                }
                if (manualStatesEl) {
                    const selectedStates = Array.from(manualStatesEl.selectedOptions).map(opt => opt.value);
                    selectedStates.forEach(state => fd.append('manual_states[]', state));
                }
            }
            
            if (tag === 'status') {
                const fromEl = document.getElementById('zs-flow-from');
                const toEl = document.getElementById('zs-flow-to');
                const from = (fromEl && fromEl.value) || '';
                const to = (toEl && toEl.value) || '';
                if (!from || !to) { alert('Please select From and To status'); addBtn.disabled=false; addBtn.textContent = originalText; return; }
                fd.append('from_status', from);
                fd.append('to_status', to);
            } else if (tag === 'class') {
                const clsEl = document.getElementById('zs-flow-class');
                const cls = (clsEl && clsEl.value || '').trim();
                if (!cls) { alert('Please provide Class'); addBtn.disabled=false; addBtn.textContent = originalText; return; }
                fd.append('class', cls);
            }

            fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        alert('Error adding trigger: ' + (data && data.data ? data.data : 'unknown'));
                        return;
                    }
                    // Append to the grouped list based on action type
                    const groupIdMap = {
                        'status': 'zs-flow-custom-list-status',
                        'class': 'zs-flow-custom-list-class'
                    };
                    const targetListId = groupIdMap[tag];
                    const targetList = document.getElementById(targetListId);
                    if (targetList) {
                        const li = document.createElement('li');
                        const safeWid = wid.replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        const safeTitle = (data && data.data && data.data.title || 'Untitled').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        
                        let extraHtml = '';
                        let emailIndicator = '';
                        
                        // Process email configuration for display
                        if (isEmailEl && isEmailEl.checked) {
                            const emailDesc = emailDescEl ? emailDescEl.value.trim() : '';
                            const sendOnce = (tag === 'status' && sendOnceEl && sendOnceEl.checked);
                            const emailTitle = emailDesc || 'Email workflow';
                            const sendOnceText = sendOnce ? ' (once)' : '';
                            emailIndicator = '<span class="zs-flow-email" title="' + emailTitle + sendOnceText + '" style="color:#0073aa;font-weight:bold;">📧</span> ';
                        }
                        
                        if (tag === 'status') {
                            const fromLabel = document.querySelector('#zs-flow-from option:checked')?.textContent || '';
                            const toLabel = document.querySelector('#zs-flow-to option:checked')?.textContent || '';
                            extraHtml = '<span class="zs-flow-extra">' + fromLabel + ' → ' + toLabel + '</span> · ';
                        } else if (tag === 'class') {
                            const cls = (document.getElementById('zs-flow-class')?.value || '').trim().replace(/</g,'&lt;').replace(/>/g,'&gt;');
                            
                            // Handle class display for email workflows
                            let displayClass = cls;
                            if (isEmailEl && isEmailEl.checked && emailDescEl && emailDescEl.value.trim()) {
                                const buttonName = emailDescEl.value.trim();
                                displayClass = cls + ' [' + buttonName + ']';
                            }
                            
                            extraHtml = '<span class="zs-flow-extra">.' + displayClass + '</span> · ';
                        }
                        
                        li.innerHTML = '<button type="button" class="zs-btn-icon zs-flow-play" data-workflow-id="' + safeWid + '" title="Run workflow">▶</button> '
                                     + '<span class="zs-flow-title">' + safeTitle + '</span> · '
                                     + emailIndicator + extraHtml + '<code class="zs-flow-id">' + safeWid + '</code> · '
                                     + '<button type="button" class="button-link zs-flow-edit">Edit</button> · '
                                     + '<button type="button" class="button-link zs-flow-delete">Delete</button>';
                        
                        li.setAttribute('data-tag', tag);
                        li.setAttribute('data-workflow-id', wid);
                        if (data && data.data && data.data.uid) li.setAttribute('data-uid', data.data.uid);
                        
                        // Add email data attributes
                        if (isEmailEl && isEmailEl.checked) {
                            li.setAttribute('data-is-email', 'true');
                            if (emailDescEl && emailDescEl.value.trim()) {
                                li.setAttribute('data-email-desc', emailDescEl.value.trim());
                            }
                            if (tag === 'status' && sendOnceEl && sendOnceEl.checked) {
                                li.setAttribute('data-send-once', 'true');
                            }
                            if (manualStatesEl) {
                                const selectedStates = Array.from(manualStatesEl.selectedOptions).map(opt => opt.value);
                                if (selectedStates.length > 0) {
                                    li.setAttribute('data-manual-states', selectedStates.join(','));
                                }
                            }
                        }
                        
                        // Add status or class specific attributes
                        if (tag === 'status') {
                            const fromEl = document.getElementById('zs-flow-from');
                            const toEl = document.getElementById('zs-flow-to');
                            if (fromEl) li.setAttribute('data-from', fromEl.value);
                            if (toEl) li.setAttribute('data-to', toEl.value);
                        } else if (tag === 'class') {
                            const clsEl = document.getElementById('zs-flow-class');
                            if (clsEl) {
                                li.setAttribute('data-class', clsEl.value.trim());
                                // If email is enabled and has description, store original class
                                if (isEmailEl && isEmailEl.checked && emailDescEl && emailDescEl.value.trim()) {
                                    li.setAttribute('data-original-class', clsEl.value.trim());
                                }
                            }
                        }
                        targetList.appendChild(li);
                        
                        // Show duplicate indicator inline for new items (after li is created and added)
                        if (data.data && data.data.duplicate) {
                            const duplicateSpan = document.createElement('span');
                            duplicateSpan.className = 'zs-duplicate-indicator';
                            duplicateSpan.textContent = 'DUPLICATE';
                            duplicateSpan.style.cssText = 'margin-left:8px;padding:2px 6px;background:#fff3cd;color:#856404;border:1px solid #ffeaa7;border-radius:3px;font-size:10px;font-weight:600;';
                            li.appendChild(duplicateSpan);
                            // Remove after 3 seconds
                            setTimeout(() => {
                                if (duplicateSpan.parentNode) duplicateSpan.remove();
                            }, 3000);
                        }
                    }
                    // Clear inputs
                    if (idEl) idEl.value = '';
                    if (titleEl) titleEl.value = '';
                    if (tagEl) {
                        tagEl.value = 'status';
                        // Trigger change event to update conditional fields
                        tagEl.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    // Clear conditional fields
                    const fromEl = document.getElementById('zs-flow-from');
                    const toEl = document.getElementById('zs-flow-to');
                    const clsEl = document.getElementById('zs-flow-class');
                    if (fromEl) fromEl.selectedIndex = 0;
                    if (toEl) toEl.selectedIndex = 0;
                    if (clsEl) clsEl.value = '';
                })
                .catch(err => {
                    alert('Network error: ' + err.message);
                })
                .finally(() => {
                    addBtn.disabled = false;
                    addBtn.textContent = originalText;
                });
        });

        // Toggle fields based on Action Type selection (inline)
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'zs-flow-tag') {
                const fromContainer = document.getElementById('zs-flow-from-container');
                const toContainer = document.getElementById('zs-flow-to-container');
                const classContainer = document.getElementById('zs-flow-class-container');
                if (e.target.value === 'status') {
                    if (fromContainer) fromContainer.style.display = 'block';
                    if (toContainer) toContainer.style.display = 'block';
                    if (classContainer) classContainer.style.display = 'none';
                } else if (e.target.value === 'class') {
                    if (fromContainer) fromContainer.style.display = 'none';
                    if (toContainer) toContainer.style.display = 'none';
                    if (classContainer) classContainer.style.display = 'block';
                }
            }
        });

        // Flowmattic: Edit/Delete handlers for custom triggers
        document.addEventListener('click', function(e){
            // Play workflow directly
            const playBtn = e.target.closest('.zs-flow-play');
            if (playBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') { alert('AJAX not ready'); return; }
                const wid = playBtn.getAttribute('data-workflow-id');
                if (!wid) return;
                const originalHTML = playBtn.innerHTML;
                playBtn.disabled = true;
                playBtn.innerHTML = '<i class="zs-spinner" aria-hidden="true"></i>';

                const fd = new FormData();
                fd.append('action', 'zs_flow_run_workflow');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('workflow_id', wid);
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        const li = playBtn.closest('li');
                        if (!li) return;
                        let fb = li.querySelector('.zs-inline-feedback');
                        if (!fb) {
                            fb = document.createElement('span');
                            fb.className = 'zs-feature-feedback zs-inline-feedback';
                            li.appendChild(fb);
                        }
                        fb.textContent = (data && data.success) ? 'TRIGGERED' : 'ERROR';
                        fb.classList.remove('is-success','is-error','is-visible');
                        fb.classList.add((data && data.success) ? 'is-success' : 'is-error','is-visible');
                        clearTimeout(fb._hideTimeout);
                        fb._hideTimeout = setTimeout(()=>{
                            fb.classList.remove('is-visible');
                        }, 2000);
                    })
                    .catch(err => {
                        const li = playBtn.closest('li');
                        if (!li) return;
                        let fb = li.querySelector('.zs-inline-feedback');
                        if (!fb) { fb = document.createElement('span'); fb.className = 'zs-feature-feedback zs-inline-feedback'; li.appendChild(fb); }
                        fb.textContent = 'ERROR';
                        fb.classList.remove('is-success','is-error','is-visible');
                        fb.classList.add('is-error','is-visible');
                        clearTimeout(fb._hideTimeout);
                        fb._hideTimeout = setTimeout(()=>{ fb.classList.remove('is-visible'); }, 2000);
                    })
                    .finally(() => {
                        playBtn.disabled = false;
                        playBtn.innerHTML = originalHTML;
                    });
                return;
            }

            // Delete
            const delBtn = e.target.closest('.zs-flow-delete');
            if (delBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') { alert('AJAX not ready'); return; }
                const li = delBtn.closest('li[data-uid]');
                if (!li) return;
                const uid = li.getAttribute('data-uid');
                if (!confirm('Delete this trigger?')) return;

                const fd = new FormData();
                fd.append('action', 'zs_flow_delete_trigger');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('uid', uid);
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r=>r.json())
                    .then(data => {
                        if (!data || !data.success) { alert('Delete failed'); return; }
                        li.parentNode.removeChild(li);
                    })
                    .catch(err => alert('Network error: ' + err.message));
                return;
            }

            // Enter edit mode
            const editBtn = e.target.closest('.zs-flow-edit');
            if (editBtn) {
                e.preventDefault();
                const li = editBtn.closest('li[data-uid]');
                if (!li) return;
                if (li.querySelector('.zs-flow-edit-form')) return; // already editing

                // Close any other open edit forms to ensure only one is open
                document.querySelectorAll('.zs-flow-edit-form').forEach(function(openForm){
                    if (!li.contains(openForm) && openForm.parentNode) {
                        openForm.parentNode.removeChild(openForm);
                    }
                });

                const titleSpan = li.querySelector('.zs-flow-title');
                const idCode = li.querySelector('.zs-flow-id');
                const currentTitle = titleSpan ? titleSpan.textContent.trim() : '';
                const currentWid = idCode ? idCode.textContent.trim() : '';
                const currentTag = li.getAttribute('data-tag') || 'status';

                // Create comprehensive edit form with email fields
                ZeroSenseAdmin.createEditForm(li, currentTag, currentWid);
                return;
            }

            // Save edit
            const saveBtn = e.target.closest('.zs-flow-save');
            if (saveBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') { alert('AJAX not ready'); return; }
                const li = saveBtn.closest('li[data-uid]');
                if (!li) return;
                const uid = li.getAttribute('data-uid');
                const form = li.querySelector('.zs-flow-edit-form');
                if (!form) return;
                const currentTag = li.getAttribute('data-tag');
                const wid = form.querySelector('.zs-flow-edit-id').value.trim();
                const titleVal = (form.querySelector('.zs-flow-edit-title')?.value || '').trim();
                if (!wid) { alert('Workflow ID is required'); return; }
                
                const fd = new FormData();
                fd.append('action', 'zs_flow_update_trigger');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('uid', uid);
                fd.append('workflow_id', wid);
                fd.append('tag', currentTag);
                if (titleVal) fd.append('title', titleVal);
                
                // Email configuration
                const isEmailEl = form.querySelector('.zs-flow-edit-is-email');
                const emailDescEl = form.querySelector('.zs-flow-edit-email-desc');
                const sendOnceEl = form.querySelector('.zs-flow-edit-send-once');
                const manualStatesEl = form.querySelector('.zs-flow-edit-manual-states');
                
                if (isEmailEl && isEmailEl.checked) {
                    fd.append('is_email', 'true');
                    
                    // Validate required email fields
                    if (emailDescEl) {
                        const emailDescValue = emailDescEl.value.trim();
                        if (!emailDescValue) {
                            alert(currentTag === 'status' ? 'Email description is required when email features are enabled' : 'Button name is required when email features are enabled');
                            return;
                        }
                        fd.append('email_description', emailDescValue);
                    }
                    
                    if (currentTag === 'status' && sendOnceEl && sendOnceEl.checked) {
                        fd.append('send_once', 'true');
                    }
                    if (manualStatesEl) {
                        const selectedStates = Array.from(manualStatesEl.selectedOptions).map(opt => opt.value);
                        selectedStates.forEach(state => fd.append('manual_states[]', state));
                    }
                }
                
                if (currentTag === 'status') {
                    const from = form.querySelector('.zs-flow-edit-from').value;
                    const to = form.querySelector('.zs-flow-edit-to').value;
                    if (!from || !to) { alert('From and To status are required'); return; }
                    fd.append('from_status', from);
                    fd.append('to_status', to);
                } else if (currentTag === 'class') {
                    const cls = form.querySelector('.zs-flow-edit-class').value.trim();
                    if (!cls) { alert('Class is required'); return; }
                    fd.append('class', cls);
                }

                saveBtn.disabled = true;
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r=>r.json())
                    .then(data => {
                        if (!data || !data.success) { alert('Save failed'); return; }
                        
                        // Get updated values
                        let extraHtml = '';
                        let updatedDataAttrs = {};
                        let emailIndicator = '';
                        
                        // Process email configuration for display
                        if (isEmailEl && isEmailEl.checked) {
                            const emailDesc = emailDescEl ? emailDescEl.value.trim() : '';
                            const sendOnce = (currentTag === 'status' && sendOnceEl && sendOnceEl.checked);
                            const emailTitle = emailDesc || 'Email workflow';
                            const sendOnceText = sendOnce ? ' (once)' : '';
                            emailIndicator = '<span class="zs-flow-email" title="' + emailTitle + sendOnceText + '" style="color:#0073aa;font-weight:bold;">📧</span> ';
                            
                            // Update email data attributes
                            updatedDataAttrs['data-is-email'] = 'true';
                            if (emailDesc) {
                                updatedDataAttrs['data-email-desc'] = emailDesc;
                            }
                            if (sendOnce) {
                                updatedDataAttrs['data-send-once'] = 'true';
                            } else {
                                li.removeAttribute('data-send-once');
                            }
                            if (manualStatesEl) {
                                const selectedStates = Array.from(manualStatesEl.selectedOptions).map(opt => opt.value);
                                if (selectedStates.length > 0) {
                                    updatedDataAttrs['data-manual-states'] = selectedStates.join(',');
                                } else {
                                    li.removeAttribute('data-manual-states');
                                }
                            }
                        } else {
                            // Remove email data attributes if email is disabled
                            li.removeAttribute('data-is-email');
                            li.removeAttribute('data-email-desc');
                            li.removeAttribute('data-send-once');
                            li.removeAttribute('data-manual-states');
                        }
                        
                        if (currentTag === 'status') {
                            const from = form.querySelector('.zs-flow-edit-from').value;
                            const to = form.querySelector('.zs-flow-edit-to').value;
                            const fromLabel = form.querySelector('.zs-flow-edit-from option:checked')?.textContent || from;
                            const toLabel = form.querySelector('.zs-flow-edit-to option:checked')?.textContent || to;
                            extraHtml = '<span class="zs-flow-extra">' + fromLabel + ' → ' + toLabel + '</span> · ';
                            updatedDataAttrs['data-from'] = from;
                            updatedDataAttrs['data-to'] = to;
                            li.removeAttribute('data-class');
                        } else if (currentTag === 'class') {
                            const cls = form.querySelector('.zs-flow-edit-class').value.trim();
                            
                            // Handle class display for email workflows
                            let displayClass = cls;
                            if (isEmailEl && isEmailEl.checked && emailDescEl && emailDescEl.value.trim()) {
                                const buttonName = emailDescEl.value.trim();
                                displayClass = cls + ' [' + buttonName + ']';
                                updatedDataAttrs['data-original-class'] = cls;
                            }
                            
                            extraHtml = '<span class="zs-flow-extra">.' + displayClass + '</span> · ';
                            updatedDataAttrs['data-class'] = cls;
                            li.removeAttribute('data-from');
                            li.removeAttribute('data-to');
                        }
                        
                        // Update data attributes
                        Object.keys(updatedDataAttrs).forEach(key => {
                            li.setAttribute(key, updatedDataAttrs[key]);
                        });
                        
                        // Remove edit form first, before updating content
                        form.remove();
                        
                        // Update the visible content of the row
                        const playBtn = li.querySelector('.zs-btn-icon');
                        
                        // Rebuild the row content with updated values (including email indicator)
                        const safeTitle = (titleVal || (li.querySelector('.zs-flow-title')?.textContent || 'Untitled')).replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        li.innerHTML = playBtn.outerHTML + ' ' + '<span class="zs-flow-title">' + safeTitle + '</span> · ' + emailIndicator + extraHtml + '<code class="zs-flow-id">' + wid + '</code> · '
                                     + '<button type="button" class="button-link zs-flow-edit">Edit</button> · '
                                     + '<button type="button" class="button-link zs-flow-delete">Delete</button>';
                        
                        // Restore data attributes after innerHTML update
                        li.setAttribute('data-uid', uid);
                        li.setAttribute('data-tag', currentTag);
                        Object.keys(updatedDataAttrs).forEach(key => {
                            li.setAttribute(key, updatedDataAttrs[key]);
                        });
                        // Show duplicate indicator inline if needed
                        if (data.data && data.data.duplicate) {
                            const duplicateSpan = document.createElement('span');
                            duplicateSpan.className = 'zs-duplicate-indicator';
                            duplicateSpan.textContent = 'DUPLICATE';
                            duplicateSpan.style.cssText = 'margin-left:8px;padding:2px 6px;background:#fff3cd;color:#856404;border:1px solid #ffeaa7;border-radius:3px;font-size:10px;font-weight:600;';
                            li.appendChild(duplicateSpan);
                            // Remove after 3 seconds
                            setTimeout(() => {
                                if (duplicateSpan.parentNode) duplicateSpan.remove();
                            }, 3000);
                        }
                    })
                    .catch(err => alert('Network error: ' + err.message))
                    .finally(()=>{ saveBtn.disabled = false; });
                return;
            }

            // Cancel edit
            const cancelBtn = e.target.closest('.zs-flow-cancel');
            if (cancelBtn) {
                e.preventDefault();
                const form = cancelBtn.closest('.zs-flow-edit-form');
                if (form && form.parentNode) form.parentNode.removeChild(form);
                return;
            }
        });
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
                ZeroSenseAdmin.showCardMessage(card, 'Configuration saved successfully!', 'success');
                // Clear dirty flag on Settings icon and revalidate errors
                const sIcon = card.querySelector('.zs-card-settings');
                if (sIcon) sIcon.classList.remove('is-dirty');
                ZeroSenseAdmin.validateSettingsIcon(card);
            } else {
                ZeroSenseAdmin.showCardMessage(card, 'Error: ' + data.data, 'error');
            }
        })
        .catch(error => {
            button.disabled = false;
            button.textContent = '💾 Save Configuration';
            ZeroSenseAdmin.showCardMessage(card, 'Network error: ' + error.message, 'error');
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
                
                // Form will submit normally, loading state will be cleared on page reload
            });
        }
    },

    /**
     * Initialize Flowmattic email configuration handlers
     */
    initFlowmatticEmailConfig: function() {
        // Handle email checkbox toggle
        document.addEventListener('change', function(e) {
            if (e.target.id === 'zs-flow-is-email') {
                const isChecked = e.target.checked;
                const emailDescEl = document.getElementById('zs-flow-email-desc');
                const sendOnceEl = document.getElementById('zs-flow-send-once');
                const manualStatesEl = document.getElementById('zs-flow-manual-states');
                const generatedClassEl = document.getElementById('zs-flow-generated-class');
                
                const tagEl = document.getElementById('zs-flow-tag');
                const isClass = tagEl?.value === 'class';
                const isStatus = tagEl?.value === 'status';
                const emailFieldsContainer = document.getElementById('zs-email-fields');
                const emailHelpEl = document.getElementById('zs-flow-email-help');
                
                // Toggle entire email fields container
                if (emailFieldsContainer) {
                    emailFieldsContainer.style.display = isChecked ? 'block' : 'none';
                }
                
                // Show/hide help text and update content
                if (emailHelpEl) {
                    emailHelpEl.style.display = isChecked ? 'block' : 'none';
                    if (isChecked) {
                        // Update help text based on current action type
                        if (isStatus) {
                            emailHelpEl.textContent = 'Status Transitions: Email description and send-once option.';
                        } else if (isClass) {
                            emailHelpEl.textContent = 'Class Actions: Button name (generates class flm-action-{name}). Select states where button appears, or leave empty to disable.';
                        }
                    }
                }
                
                // Email description: always available when email is checked
                // (No need to disable, just show/hide the container)
                
                // Update field labels and visibility based on action type
                ZeroSenseAdmin.updateEmailFieldsForActionType(isClass, isStatus, isChecked);
                
                // Update generated class for Class Actions
                if (isClass) {
                    ZeroSenseAdmin.updateMainFormGeneratedClass();
                }
                
                // Clear values when disabling
                if (!isChecked) {
                    if (emailDescEl) emailDescEl.value = '';
                    if (sendOnceEl) sendOnceEl.checked = false;
                    if (manualStatesEl) {
                        Array.from(manualStatesEl.options).forEach(opt => opt.selected = false);
                    }
                }
            }
        });
        
        // Handle action type change to show/hide fields conditionally
        document.addEventListener('change', function(e) {
            if (e.target.id === 'zs-flow-tag') {
                const isClass = e.target.value === 'class';
                const isStatus = e.target.value === 'status';
                const isEmailChecked = document.getElementById('zs-flow-is-email')?.checked;
                
                const manualStatesEl = document.getElementById('zs-flow-manual-states');
                const emailDescEl = document.getElementById('zs-flow-email-desc');
                const sendOnceEl = document.getElementById('zs-flow-send-once');
                
                // Clear values when switching action types
                if (!isClass && manualStatesEl) {
                    Array.from(manualStatesEl.options).forEach(opt => opt.selected = false);
                }
                
                // Reset send-once when leaving Status context to avoid accidental persistence
                if (!isStatus && sendOnceEl) {
                    sendOnceEl.checked = false;
                }
                
                // Update email fields for new action type
                ZeroSenseAdmin.updateEmailFieldsForActionType(isClass, isStatus, isEmailChecked);
                
                // Update generated class for Class Actions
                if (isClass && isEmailChecked) {
                    ZeroSenseAdmin.updateMainFormGeneratedClass();
                }
            }
        });
        
        // Handle email description changes for Class Actions
        document.addEventListener('input', function(e) {
            if (e.target.id === 'zs-flow-email-desc') {
                const tagEl = document.getElementById('zs-flow-tag');
                const isClass = tagEl?.value === 'class';
                const isEmailChecked = document.getElementById('zs-flow-is-email')?.checked;
                
                if (isClass && isEmailChecked) {
                    ZeroSenseAdmin.updateMainFormGeneratedClass();
                }
            }
        });
    },

    /**
     * Update email fields based on action type
     */
    updateEmailFieldsForActionType: function(isClass, isStatus, isEmailChecked) {
        const emailDescLabel = document.getElementById('zs-flow-email-desc-label');
        const emailDescEl = document.getElementById('zs-flow-email-desc');
        const sendOnceContainer = document.getElementById('zs-flow-send-once-container');
        const manualStatesContainer = document.getElementById('zs-flow-manual-states-container');
        const generatedClassContainer = document.getElementById('zs-flow-generated-class-container');
        const emailHelpEl = document.getElementById('zs-flow-email-help');
        
        // Update label and placeholder based on action type
        if (emailDescLabel && emailDescEl) {
            if (isStatus) {
                emailDescLabel.textContent = 'Email Description';
                emailDescEl.placeholder = 'e.g., Order confirmation email';
            } else if (isClass) {
                emailDescLabel.textContent = 'Button Name';
                emailDescEl.placeholder = 'e.g., Send Invoice';
            }
        }
        
        // Update help text based on action type
        if (emailHelpEl && isEmailChecked) {
            if (isStatus) {
                emailHelpEl.textContent = 'Status Transitions: Email description and send-once option.';
            } else if (isClass) {
                emailHelpEl.textContent = 'Class Actions: Button name (generates class flm-action-{name}). Select states where button appears, or leave empty to disable.';
            }
        }
        
        // Show/hide conditional fields (no need to disable, just show/hide)
        if (sendOnceContainer) {
            const shouldShowSendOnce = (isEmailChecked && isStatus);
            sendOnceContainer.style.display = shouldShowSendOnce ? 'block' : 'none';
        }
        
        if (manualStatesContainer) {
            manualStatesContainer.style.display = (isClass && isEmailChecked) ? 'block' : 'none';
        }
        
        if (generatedClassContainer) {
            generatedClassContainer.style.display = (isClass && isEmailChecked) ? 'block' : 'none';
        }
    },

    /**
     * Create comprehensive edit form with email fields
     */
    createEditForm: function(li, currentTag, currentWid) {
        const form = document.createElement('div');
        form.className = 'zs-flow-edit-form';
        form.style.marginTop = '6px';
        
        // Get current email config from data attributes
        const isEmail = li.getAttribute('data-is-email') === 'true';
        const emailDesc = li.getAttribute('data-email-desc') || '';
        const sendOnce = (currentTag === 'status') && li.getAttribute('data-send-once') === 'true';
        const manualStates = li.getAttribute('data-manual-states')?.split(',') || [];
        
        // Build main form (same format as original)
        let formHtml = '';
        const currentTitle = (li.querySelector('.zs-flow-title')?.textContent || '').trim();
        if (currentTag === 'status') {
            const fromOptions = document.getElementById('zs-flow-from')?.innerHTML || '<option value="any">Any</option>';
            const toOptions = document.getElementById('zs-flow-to')?.innerHTML || '<option value="any">Any</option>';
            formHtml = '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">Name</label><input type="text" class="zs-config-input zs-flow-edit-title" placeholder="Short label" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;" /></div>' +
                      '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">Workflow ID</label><input type="text" class="zs-config-input zs-flow-edit-id" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;" /></div>' +
                      '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">From Status</label><select class="zs-config-input zs-flow-edit-from" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;">' + fromOptions + '</select></div>' +
                      '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">To Status</label><select class="zs-config-input zs-flow-edit-to" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;">' + toOptions + '</select></div>' +
                      '<div style="display:flex;align-self:end;gap:4px;"><button type="button" class="zs-btn-primary zs-flow-save" style="margin-right:4px;height:36px;padding:8px 12px;">Save</button>' +
                      '<button type="button" class="zs-btn-secondary zs-flow-cancel" style="height:36px;padding:8px 12px;">Cancel</button></div>';
        } else if (currentTag === 'class') {
            const originalClass = li.getAttribute('data-original-class') || li.getAttribute('data-class') || '';
            formHtml = '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">Name</label><input type="text" class="zs-config-input zs-flow-edit-title" placeholder="Short label" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;" /></div>' +
                      '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">Workflow ID</label><input type="text" class="zs-config-input zs-flow-edit-id" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;" /></div>' +
                      '<div style="display:flex;flex-direction:column;justify-content:center;"><label class="zs-config-label">Class (no dot)</label><input type="text" class="zs-config-input zs-flow-edit-class" placeholder="class_name" style="height:36px;box-sizing:border-box;padding:8px 12px;line-height:20px;" /></div>' +
                      '<div style="display:flex;align-self:end;gap:4px;"><button type="button" class="zs-btn-primary zs-flow-save" style="margin-right:4px;height:36px;padding:8px 12px;">Save</button>' +
                      '<button type="button" class="zs-btn-secondary zs-flow-cancel" style="height:36px;padding:8px 12px;">Cancel</button></div>';
        }
        
        // Email configuration section (same as main form)
        let emailSectionHtml = '<div class="zs-flow-email-config" style="margin-top:12px;padding:12px;background:#f9f9f9;border-radius:4px;grid-column:1 / -1;">' +
            '<h6 style="margin:0 0 8px;color:#666;">Email Configuration (Optional)</h6>' +
            '<label style="display:block;margin-bottom:12px;"><input type="checkbox" class="zs-flow-edit-is-email" style="margin-right:6px;"' + (isEmail ? ' checked' : '') + ' /> Enable Email Features</label>' +
            '<div class="zs-flow-edit-email-fields" style="' + (isEmail ? '' : 'display:none;') + 'max-width:350px;">' +
            '<div style="display:flex;flex-direction:column;gap:12px;">' +
            '<div><label class="zs-config-label zs-flow-edit-email-desc-label">' + (currentTag === 'status' ? 'Email Description' : 'Button Name') + '</label>' +
            '<input type="text" class="zs-config-input zs-flow-edit-email-desc" placeholder="' + (currentTag === 'status' ? 'e.g., Order confirmation email' : 'e.g., Send Invoice') + '" value="' + emailDesc + '" /></div>';
        
        // Add generated class display for Class Actions
        if (currentTag === 'class') {
            const originalClass = li.getAttribute('data-original-class') || li.getAttribute('data-class') || '';
            let generatedClass = originalClass;
            if (isEmail && emailDesc) {
                generatedClass = 'flm-action-' + emailDesc.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            }
            emailSectionHtml += '<div><label class="zs-config-label">Generated CSS Class</label>' +
                '<input type="text" class="zs-config-input zs-flow-edit-generated-class" value="' + generatedClass + '" readonly style="background:#f0f0f0;color:#666;" /></div>';
        }
        
        if (currentTag === 'status') {
            const sendOnceDisplay = isEmail ? 'block' : 'none';
            emailSectionHtml += '<div class="zs-flow-edit-send-once-container" style="display:' + sendOnceDisplay + ';"><label class="zs-config-label"><input type="checkbox" class="zs-flow-edit-send-once" style="margin-right:6px;"' + (sendOnce ? ' checked' : '') + ' /> Send only once per order</label></div>';
        }

        if (currentTag === 'class') {
            const manualStatesDisplay = isEmail ? 'block' : 'none';
            emailSectionHtml += '<div class="zs-flow-edit-manual-states-container" style="display:' + manualStatesDisplay + ';"><label class="zs-config-label">Show button in these order states (optional)</label><select class="zs-config-input zs-flow-edit-manual-states" multiple style="height:80px;">' + (document.getElementById('zs-flow-manual-states')?.innerHTML || '') + '</select></div>';
        }

        emailSectionHtml += '</div>' +
            '<p class="zs-flow-edit-email-help" style="margin:8px 0 0;font-size:11px;color:#666;">' + (currentTag === 'status' ? 'Status Transitions: Email description and send-once option.' : 'Class Actions: Button name (generates class flm-action-{name}). Select states where button appears, or leave empty to disable.') + '</p>' +
            '</div>' +
            '</div>';
        
        form.innerHTML = formHtml + emailSectionHtml;
        form.style.display = 'grid';
        form.style.gridTemplateColumns = currentTag === 'status' ? '1fr 1fr 1fr 1fr auto' : '1fr 1fr 1fr auto';
        form.style.gap = '6px';
        form.style.alignItems = 'center';
        
        li.appendChild(form);
        
        // Populate existing values
        const idEl = form.querySelector('.zs-flow-edit-id');
        const titleEl = form.querySelector('.zs-flow-edit-title');
        if (idEl) idEl.value = currentWid;
        if (titleEl) titleEl.value = currentTitle;
        
        if (currentTag === 'status') {
            const fromEl = form.querySelector('.zs-flow-edit-from');
            const toEl = form.querySelector('.zs-flow-edit-to');
            if (fromEl) fromEl.value = li.getAttribute('data-from') || 'any';
            if (toEl) toEl.value = li.getAttribute('data-to') || 'any';
        } else if (currentTag === 'class') {
            const classEl = form.querySelector('.zs-flow-edit-class');
            const originalClass = li.getAttribute('data-original-class') || li.getAttribute('data-class') || '';
            if (classEl) classEl.value = originalClass;
        }
        
        if (currentTag === 'class' && manualStates.length > 0) {
            const statesEl = form.querySelector('.zs-flow-edit-manual-states');
            if (statesEl) {
                Array.from(statesEl.options).forEach(opt => {
                    opt.selected = manualStates.includes(opt.value);
                });
            }
        }
        
        // Add event listener for email checkbox
        const emailCheckbox = form.querySelector('.zs-flow-edit-is-email');
        const emailFields = form.querySelector('.zs-flow-edit-email-fields');
        const sendOnceContainer = form.querySelector('.zs-flow-edit-send-once-container');
        const sendOnceCheckbox = form.querySelector('.zs-flow-edit-send-once');
        if (emailCheckbox && emailFields) {
            emailCheckbox.addEventListener('change', function() {
                emailFields.style.display = this.checked ? 'block' : 'none';
                if (sendOnceContainer) {
                    sendOnceContainer.style.display = this.checked ? 'block' : 'none';
                    if (!this.checked && sendOnceCheckbox) {
                        sendOnceCheckbox.checked = false;
                    }
                }
                // Update generated class when email is toggled
                ZeroSenseAdmin.updateGeneratedClass(form, currentTag);
            });
        }
        
        // Add event listener for button name changes (Class Actions only)
        if (currentTag === 'class') {
            const emailDescEl = form.querySelector('.zs-flow-edit-email-desc');
            if (emailDescEl) {
                emailDescEl.addEventListener('input', function() {
                    ZeroSenseAdmin.updateGeneratedClass(form, currentTag);
                });
            }
        }
    },

    /**
     * Update generated CSS class display in real-time
     */
    updateGeneratedClass: function(form, currentTag) {
        if (currentTag !== 'class') return;
        
        const emailCheckbox = form.querySelector('.zs-flow-edit-is-email');
        const emailDescEl = form.querySelector('.zs-flow-edit-email-desc');
        const generatedClassEl = form.querySelector('.zs-flow-edit-generated-class');
        const originalClassEl = form.querySelector('.zs-flow-edit-class');
        
        if (!generatedClassEl) return;
        
        let generatedClass = '';
        
        if (emailCheckbox && emailCheckbox.checked && emailDescEl && emailDescEl.value.trim()) {
            // Generate class from button name
            const buttonName = emailDescEl.value.trim();
            generatedClass = 'flm-action-' + buttonName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        } else if (originalClassEl) {
            // Use original class
            generatedClass = originalClassEl.value.trim();
        }
        
        generatedClassEl.value = generatedClass;
    },

    /**
     * Update generated CSS class in main form
     */
    updateMainFormGeneratedClass: function() {
        const emailDescEl = document.getElementById('zs-flow-email-desc');
        const classEl = document.getElementById('zs-flow-class');
        const generatedClassEl = document.getElementById('zs-flow-generated-class');
        const emailCheckbox = document.getElementById('zs-flow-is-email');
        
        if (!generatedClassEl) return;
        
        let generatedClass = '';
        
        if (emailCheckbox && emailCheckbox.checked && emailDescEl && emailDescEl.value.trim()) {
            // Generate class from button name
            const buttonName = emailDescEl.value.trim();
            generatedClass = 'flm-action-' + buttonName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        } else if (classEl) {
            // Use original class
            generatedClass = classEl.value.trim();
        }
        
        generatedClassEl.value = generatedClass;
    }
};
