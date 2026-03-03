/**
 * Zero Sense - Flowmattic Module
 * Handles Flowmattic email configuration, CRUD operations, and triggers
 */

const ZeroSenseFlowmattic = {
    /**
     * Initialize Flowmattic handlers
     */
    init: function() {
        this.initFlowmatticEmailConfig();
        this.initAddTrigger();
        this.initEditDeleteHandlers();
    },

    /**
     * Initialize Flowmattic email configuration handlers
     */
    initFlowmatticEmailConfig: function() {
        // Toggle fields based on Action Type selection (Status vs Class)
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
                
                // Update field labels and visibility based on action type
                ZeroSenseFlowmattic.updateEmailFieldsForActionType(isClass, isStatus, isChecked);
                
                // Update generated class for Class Actions
                if (isClass) {
                    ZeroSenseFlowmattic.updateMainFormGeneratedClass();
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
        
        // Handle Holded checkbox toggle
        document.addEventListener('change', function(e) {
            if (e.target.id === 'zs-flow-is-holded') {
                const isChecked = e.target.checked;
                const holdedDescEl = document.getElementById('zs-flow-holded-desc');
                const runOnceEl = document.getElementById('zs-flow-holded-run-once');
                const holdedManualStatesEl = document.getElementById('zs-flow-holded-manual-states');
                
                const tagEl = document.getElementById('zs-flow-tag');
                const isStatus = tagEl?.value === 'status';
                const holdedFieldsContainer = document.getElementById('zs-holded-fields');
                const holdedHelpEl = document.getElementById('zs-flow-holded-help');
                
                // Toggle entire Holded fields container
                if (holdedFieldsContainer) {
                    holdedFieldsContainer.style.display = isChecked ? 'block' : 'none';
                }
                
                // Show/hide help text
                if (holdedHelpEl) {
                    holdedHelpEl.style.display = isChecked ? 'block' : 'none';
                }
                
                // Update field visibility based on action type
                ZeroSenseFlowmattic.updateHoldedFieldsForActionType(isStatus, isChecked);
                
                // Clear values when disabling
                if (!isChecked) {
                    if (holdedDescEl) holdedDescEl.value = '';
                    if (runOnceEl) runOnceEl.checked = false;
                    if (holdedManualStatesEl) {
                        Array.from(holdedManualStatesEl.options).forEach(opt => opt.selected = false);
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
                const isHoldedChecked = document.getElementById('zs-flow-is-holded')?.checked;
                
                const manualStatesEl = document.getElementById('zs-flow-manual-states');
                const emailDescEl = document.getElementById('zs-flow-email-desc');
                const sendOnceEl = document.getElementById('zs-flow-send-once');
                const runOnceEl = document.getElementById('zs-flow-holded-run-once');
                
                // Clear values when switching action types
                if (!isClass && manualStatesEl) {
                    Array.from(manualStatesEl.options).forEach(opt => opt.selected = false);
                }
                
                // Reset send-once when leaving Status context to avoid accidental persistence
                if (!isStatus && sendOnceEl) {
                    sendOnceEl.checked = false;
                }
                
                // Reset run-once when leaving Status context
                if (!isStatus && runOnceEl) {
                    runOnceEl.checked = false;
                }
                
                // Update email fields for new action type
                ZeroSenseFlowmattic.updateEmailFieldsForActionType(isClass, isStatus, isEmailChecked);
                
                // Update Holded fields for new action type
                ZeroSenseFlowmattic.updateHoldedFieldsForActionType(isStatus, isHoldedChecked);
                
                // Update generated class for Class Actions
                if (isClass && isEmailChecked) {
                    ZeroSenseFlowmattic.updateMainFormGeneratedClass();
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
                    ZeroSenseFlowmattic.updateMainFormGeneratedClass();
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
        
        // Show/hide conditional fields
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
     * Update Holded fields based on action type (only Status Transitions supported)
     */
    updateHoldedFieldsForActionType: function(isStatus, isHoldedChecked) {
        const runOnceContainer = document.getElementById('zs-flow-holded-run-once-container');
        const holdedManualStatesContainer = document.getElementById('zs-flow-holded-manual-states-container');
        
        // Only show Holded fields for Status Transitions
        if (runOnceContainer) {
            runOnceContainer.style.display = (isHoldedChecked && isStatus) ? 'block' : 'none';
        }
        
        if (holdedManualStatesContainer) {
            holdedManualStatesContainer.style.display = (isHoldedChecked && isStatus) ? 'block' : 'none';
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
                ZeroSenseFlowmattic.updateGeneratedClass(form, currentTag);
            });
        }
        
        // Add event listener for button name changes (Class Actions only)
        if (currentTag === 'class') {
            const emailDescEl = form.querySelector('.zs-flow-edit-email-desc');
            if (emailDescEl) {
                emailDescEl.addEventListener('input', function() {
                    ZeroSenseFlowmattic.updateGeneratedClass(form, currentTag);
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
    },

    /**
     * Initialize Add Trigger handler
     */
    initAddTrigger: function() {
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
                
                if (emailDescEl) {
                    const emailDescValue = emailDescEl.value.trim();
                    if (!emailDescValue) {
                        alert(tag === 'status' ? 'Email description is required' : 'Button name is required');
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
            
            // Holded configuration (only for status transitions)
            const isHoldedEl = document.getElementById('zs-flow-is-holded');
            const holdedDescEl = document.getElementById('zs-flow-holded-desc');
            const runOnceEl = document.getElementById('zs-flow-holded-run-once');
            const holdedManualStatesEl = document.getElementById('zs-flow-holded-manual-states');
            
            if (isHoldedEl && isHoldedEl.checked && tag === 'status') {
                fd.append('is_holded', 'true');
                
                if (holdedDescEl) {
                    const holdedDescValue = holdedDescEl.value.trim();
                    if (!holdedDescValue) {
                        alert('Holded sync description is required');
                        addBtn.disabled = false;
                        addBtn.textContent = originalText;
                        return;
                    }
                    fd.append('holded_description', holdedDescValue);
                }
                
                if (runOnceEl && runOnceEl.checked) {
                    fd.append('holded_run_once', 'true');
                }
                
                if (holdedManualStatesEl) {
                    const selectedStates = Array.from(holdedManualStatesEl.selectedOptions).map(opt => opt.value);
                    selectedStates.forEach(state => fd.append('holded_manual_states[]', state));
                }
            }
            
            if (tag === 'status') {
                const fromEl = document.getElementById('zs-flow-from');
                const toEl = document.getElementById('zs-flow-to');
                const from = (fromEl && fromEl.value) || '';
                const to = (toEl && toEl.value) || '';
                if (!from || !to) { 
                    alert('Please select From and To status'); 
                    addBtn.disabled=false; 
                    addBtn.textContent = originalText; 
                    return; 
                }
                fd.append('from_status', from);
                fd.append('to_status', to);
            } else if (tag === 'class') {
                const clsEl = document.getElementById('zs-flow-class');
                const cls = (clsEl && clsEl.value || '').trim();
                if (!cls) { 
                    alert('Please provide Class'); 
                    addBtn.disabled=false; 
                    addBtn.textContent = originalText; 
                    return; 
                }
                fd.append('class', cls);
            }

            fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        alert('Error adding trigger: ' + (data && data.data ? data.data : 'unknown'));
                        return;
                    }
                    
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
                        let holdedIndicator = '';
                        
                        if (isEmailEl && isEmailEl.checked) {
                            const emailDesc = emailDescEl ? emailDescEl.value.trim() : '';
                            const sendOnce = (tag === 'status' && sendOnceEl && sendOnceEl.checked);
                            const emailTitle = emailDesc || 'Email workflow';
                            const sendOnceText = sendOnce ? ' (once)' : '';
                            emailIndicator = '<span class="zs-flow-email" title="' + emailTitle + sendOnceText + '" style="color:#0073aa;font-weight:bold;">📧</span> ';
                        }
                        
                        if (isHoldedEl && isHoldedEl.checked && tag === 'status') {
                            const holdedDesc = holdedDescEl ? holdedDescEl.value.trim() : '';
                            const runOnce = (runOnceEl && runOnceEl.checked);
                            const holdedTitle = holdedDesc || 'Holded sync';
                            const runOnceText = runOnce ? ' (once)' : '';
                            holdedIndicator = '<span class="zs-flow-holded" title="' + holdedTitle + runOnceText + '" style="color:#7C3AED;font-weight:bold;">🔗</span> ';
                        }
                        
                        if (tag === 'status') {
                            const fromLabel = document.querySelector('#zs-flow-from option:checked')?.textContent || '';
                            const toLabel = document.querySelector('#zs-flow-to option:checked')?.textContent || '';
                            extraHtml = '<span class="zs-flow-extra">' + fromLabel + ' → ' + toLabel + '</span> · ';
                        } else if (tag === 'class') {
                            const cls = (document.getElementById('zs-flow-class')?.value || '').trim().replace(/</g,'&lt;').replace(/>/g,'&gt;');
                            let displayClass = cls;
                            if (isEmailEl && isEmailEl.checked && emailDescEl && emailDescEl.value.trim()) {
                                const buttonName = emailDescEl.value.trim();
                                displayClass = cls + ' [' + buttonName + ']';
                            }
                            extraHtml = '<span class="zs-flow-extra">.' + displayClass + '</span> · ';
                        }
                        
                        li.innerHTML = '<button type="button" class="zs-btn-icon zs-flow-play" data-workflow-id="' + safeWid + '" title="Run workflow">▶</button> '
                                     + '<span class="zs-flow-title">' + safeTitle + '</span> · '
                                     + emailIndicator + holdedIndicator + extraHtml + '<code class="zs-flow-id">' + safeWid + '</code> · '
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
                        
                        // Add Holded data attributes
                        if (isHoldedEl && isHoldedEl.checked && tag === 'status') {
                            li.setAttribute('data-is-holded', 'true');
                            if (holdedDescEl && holdedDescEl.value.trim()) {
                                li.setAttribute('data-holded-desc', holdedDescEl.value.trim());
                            }
                            if (runOnceEl && runOnceEl.checked) {
                                li.setAttribute('data-run-once', 'true');
                            }
                            if (holdedManualStatesEl) {
                                const selectedStates = Array.from(holdedManualStatesEl.selectedOptions).map(opt => opt.value);
                                if (selectedStates.length > 0) {
                                    li.setAttribute('data-holded-manual-states', selectedStates.join(','));
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
                                if (isEmailEl && isEmailEl.checked && emailDescEl && emailDescEl.value.trim()) {
                                    li.setAttribute('data-original-class', clsEl.value.trim());
                                }
                            }
                        }
                        targetList.appendChild(li);
                        
                        // Show duplicate indicator
                        if (data.data && data.data.duplicate) {
                            const duplicateSpan = document.createElement('span');
                            duplicateSpan.className = 'zs-duplicate-indicator';
                            duplicateSpan.textContent = 'DUPLICATE';
                            duplicateSpan.style.cssText = 'margin-left:8px;padding:2px 6px;background:#fff3cd;color:#856404;border:1px solid #ffeaa7;border-radius:3px;font-size:10px;font-weight:600;';
                            li.appendChild(duplicateSpan);
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
                        tagEl.dispatchEvent(new Event('change', { bubbles: true }));
                    }
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
    },

    /**
     * Initialize Edit/Delete/Play handlers
     */
    initEditDeleteHandlers: function() {
        document.addEventListener('click', function(e){
            // Play workflow
            const playBtn = e.target.closest('.zs-flow-play');
            if (playBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') { 
                    alert('AJAX not ready'); 
                    return; 
                }
                
                const workflowId = playBtn.getAttribute('data-workflow-id');
                if (!workflowId) return;
                
                playBtn.disabled = true;
                playBtn.textContent = '⏳';
                
                const fd = new FormData();
                fd.append('action', 'zs_flow_run_workflow');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('workflow_id', workflowId);
                
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            alert('Workflow triggered successfully!');
                        } else {
                            alert('Error: ' + (data && data.data ? data.data : 'unknown'));
                        }
                    })
                    .catch(err => alert('Network error: ' + err.message))
                    .finally(() => {
                        playBtn.disabled = false;
                        playBtn.textContent = '▶';
                    });
                return;
            }

            // Delete
            const delBtn = e.target.closest('.zs-flow-delete');
            if (delBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') { 
                    alert('AJAX not ready'); 
                    return; 
                }
                
                if (!confirm('Delete this trigger?')) return;
                
                const li = delBtn.closest('li[data-uid]');
                if (!li) return;
                const uid = li.getAttribute('data-uid');
                if (!uid) return;
                
                const fd = new FormData();
                fd.append('action', 'zs_flow_delete_trigger');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('uid', uid);
                
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) {
                            li.remove();
                        } else {
                            alert('Error deleting: ' + (data && data.data ? data.data : 'unknown'));
                        }
                    })
                    .catch(err => alert('Network error: ' + err.message));
                return;
            }

            // Edit
            const editBtn = e.target.closest('.zs-flow-edit');
            if (editBtn) {
                e.preventDefault();
                const li = editBtn.closest('li[data-uid]');
                if (!li) return;
                if (li.querySelector('.zs-flow-edit-form')) return;
                
                // Close other open forms
                document.querySelectorAll('.zs-flow-edit-form').forEach(function(openForm){
                    if (!li.contains(openForm) && openForm.parentNode) {
                        openForm.parentNode.removeChild(openForm);
                    }
                });
                
                const currentTag = li.getAttribute('data-tag');
                const currentWid = li.getAttribute('data-workflow-id');
                ZeroSenseFlowmattic.createEditForm(li, currentTag, currentWid);
                return;
            }

            // Save edit
            const saveBtn = e.target.closest('.zs-flow-save');
            if (saveBtn) {
                e.preventDefault();
                if (typeof zsAdmin === 'undefined') return;
                
                const li = saveBtn.closest('li[data-uid]');
                if (!li) return;
                const uid = li.getAttribute('data-uid');
                const form = li.querySelector('.zs-flow-edit-form');
                if (!form) return;
                const currentTag = li.getAttribute('data-tag');
                const wid = form.querySelector('.zs-flow-edit-id').value.trim();
                const titleVal = (form.querySelector('.zs-flow-edit-title')?.value || '').trim();
                if (!wid) { 
                    alert('Workflow ID is required'); 
                    return; 
                }
                
                // Show loading state
                saveBtn.disabled = true;
                const originalText = saveBtn.textContent;
                saveBtn.textContent = 'Saving...';
                
                const fd = new FormData();
                fd.append('action', 'zs_flow_update_trigger');
                fd.append('nonce', zsAdmin.nonce);
                fd.append('uid', uid);
                fd.append('workflow_id', wid);
                fd.append('tag', currentTag); // CRITICAL: Backend requires this
                if (titleVal) fd.append('title', titleVal);
                
                // Email configuration
                const isEmailEl = form.querySelector('.zs-flow-edit-is-email');
                const emailDescEl = form.querySelector('.zs-flow-edit-email-desc');
                const sendOnceEl = form.querySelector('.zs-flow-edit-send-once');
                const manualStatesEl = form.querySelector('.zs-flow-edit-manual-states');
                
                if (isEmailEl && isEmailEl.checked) {
                    fd.append('is_email', 'true');
                    if (emailDescEl) {
                        const emailDescValue = emailDescEl.value.trim();
                        if (!emailDescValue) {
                            alert(currentTag === 'status' ? 'Email description required' : 'Button name required');
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
                    if (!from || !to) { 
                        alert('From and To status required'); 
                        return; 
                    }
                    fd.append('from_status', from);
                    fd.append('to_status', to);
                } else if (currentTag === 'class') {
                    const cls = form.querySelector('.zs-flow-edit-class').value.trim();
                    if (!cls) { 
                        alert('Class required'); 
                        return; 
                    }
                    fd.append('class', cls);
                }
                
                fetch(zsAdmin.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success) {
                            alert('Error updating: ' + (data && data.data ? data.data : 'unknown'));
                            saveBtn.disabled = false;
                            saveBtn.textContent = originalText;
                            return;
                        }
                        
                        // Update UI
                        const playBtn = li.querySelector('.zs-flow-play');
                        if (playBtn) playBtn.setAttribute('data-workflow-id', wid);
                        
                        let extraHtml = '';
                        let emailIndicator = '';
                        const updatedDataAttrs = {};
                        
                        if (isEmailEl && isEmailEl.checked) {
                            const emailDesc = emailDescEl ? emailDescEl.value.trim() : '';
                            const sendOnce = (currentTag === 'status' && sendOnceEl && sendOnceEl.checked);
                            const emailTitle = emailDesc || 'Email workflow';
                            const sendOnceText = sendOnce ? ' (once)' : '';
                            emailIndicator = '<span class="zs-flow-email" title="' + emailTitle + sendOnceText + '" style="color:#0073aa;font-weight:bold;">📧</span> ';
                            updatedDataAttrs['data-is-email'] = 'true';
                            if (emailDesc) updatedDataAttrs['data-email-desc'] = emailDesc;
                            if (sendOnce) updatedDataAttrs['data-send-once'] = 'true';
                            if (manualStatesEl) {
                                const selectedStates = Array.from(manualStatesEl.selectedOptions).map(opt => opt.value);
                                if (selectedStates.length > 0) {
                                    updatedDataAttrs['data-manual-states'] = selectedStates.join(',');
                                }
                            }
                        } else {
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
                        
                        const safeTitle = (titleVal || (li.querySelector('.zs-flow-title')?.textContent || 'Untitled')).replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        li.innerHTML = playBtn.outerHTML + ' ' + '<span class="zs-flow-title">' + safeTitle + '</span> · ' + emailIndicator + extraHtml + '<code class="zs-flow-id">' + wid + '</code> · '
                                     + '<button type="button" class="button-link zs-flow-edit">Edit</button> · '
                                     + '<button type="button" class="button-link zs-flow-delete">Delete</button>';
                        
                        li.setAttribute('data-uid', uid);
                        li.setAttribute('data-tag', currentTag);
                        li.setAttribute('data-workflow-id', wid);
                        Object.keys(updatedDataAttrs).forEach(k => li.setAttribute(k, updatedDataAttrs[k]));
                        
                        // Show success indicator
                        const successSpan = document.createElement('span');
                        successSpan.style.cssText = 'margin-left:8px;padding:2px 6px;background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:3px;font-size:10px;font-weight:600;';
                        successSpan.textContent = '✓ Saved';
                        li.appendChild(successSpan);
                        setTimeout(() => {
                            if (successSpan.parentNode) successSpan.remove();
                        }, 2000);
                    })
                    .catch(err => {
                        alert('Network error: ' + err.message);
                        saveBtn.disabled = false;
                        saveBtn.textContent = originalText;
                    });
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
    }
};

// Export for use in main admin.js
if (typeof window !== 'undefined') {
    window.ZeroSenseFlowmattic = ZeroSenseFlowmattic;
}
