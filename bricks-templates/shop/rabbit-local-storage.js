/**
 * Checkbox State Management for "Sin conejo" option
 * Stores checkbox state in local storage and restores it when returning to the page
 */
jQuery(function($) {
    // Constants
    const STORAGE_KEY = 'rabbit';
    const STORAGE_VALUE = 'Sin conejo';
    const CHECKBOX_SELECTOR = 'input[value="sin-conejo"]';
    
    // Safe localStorage helpers
    const safeGet = (k) => { try { return localStorage.getItem(k); } catch(e) { return null; } };
    const safeSet = (k, v) => { try { localStorage.setItem(k, v); } catch(e) {} };
    const safeRemove = (k) => { try { localStorage.removeItem(k); } catch(e) {} };
    
    // Function to save checkbox state to local storage
    function saveCheckboxState($checkbox) {
        if (!$checkbox || $checkbox.length === 0) return;
        if ($checkbox.is(':checked')) {
            safeSet(STORAGE_KEY, STORAGE_VALUE);
        } else {
            safeRemove(STORAGE_KEY);
        }
    }
    
    // Function to restore checkbox state from local storage
    function restoreCheckboxState() {
        const storedValue = safeGet(STORAGE_KEY);
        const $checkbox = $(CHECKBOX_SELECTOR);
        if ($checkbox.length === 0) return;
        if (storedValue === STORAGE_VALUE && !$checkbox.is(':checked')) {
            $checkbox.prop('checked', true).trigger('change');
        }
    }
    
    // When the document is ready, restore the checkbox state
    $(document).ready(function() {
        restoreCheckboxState();
    });
    
    // Set up an event listener to save the state when the checkbox changes
    $(document).on('change', CHECKBOX_SELECTOR, function() {
        saveCheckboxState($(this));
    });
    
    // Special handling for Bricks Builder's form submit
    // This ensures the state is saved even when using Bricks forms
    $(document).on('submit', 'form', function() {
        const $checkbox = $(CHECKBOX_SELECTOR);
        if ($checkbox.length > 0) {
            saveCheckboxState($checkbox);
        }
    });
    
    // If using AJAX forms, also listen for AJAX completion
    $(document).ajaxComplete(function() {
        // Restore only if checkbox exists
        if ($(CHECKBOX_SELECTOR).length) restoreCheckboxState();
    });
});
