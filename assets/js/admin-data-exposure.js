/**
 * Data Exposure Metabox JavaScript
 * Handles copy to clipboard and expand/collapse functionality
 */

(function() {
    'use strict';

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        const metabox = document.getElementById('zs_data_exposure');
        if (!metabox) {
            return;
        }

        // Bind copy buttons
        metabox.addEventListener('click', function(e) {
            const copyBtn = e.target.closest('.zs-exposure-copy');
            if (copyBtn) {
                handleCopy(copyBtn);
                return;
            }

            const expandBtn = e.target.closest('.zs-exposure-expand');
            if (expandBtn) {
                handleExpand(expandBtn);
                return;
            }
        });
    }

    function handleCopy(button) {
        const textToCopy = button.getAttribute('data-copy');
        if (!textToCopy) {
            return;
        }

        // Try modern clipboard API first
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                showCopiedFeedback(button);
            }).catch(function() {
                // Fallback to legacy method
                fallbackCopy(textToCopy, button);
            });
        } else {
            // Fallback for older browsers
            fallbackCopy(textToCopy, button);
        }
    }

    function fallbackCopy(text, button) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            showCopiedFeedback(button);
        } catch (err) {
            console.error('Copy failed:', err);
        }
        
        document.body.removeChild(textarea);
    }

    function showCopiedFeedback(button) {
        const originalText = button.textContent;
        button.classList.add('copied');
        button.textContent = '✓';
        
        setTimeout(function() {
            button.classList.remove('copied');
            button.textContent = originalText;
        }, 2000);
    }

    function handleExpand(button) {
        const valueDiv = button.closest('.zs-exposure-value');
        if (!valueDiv) {
            return;
        }

        const isExpanded = valueDiv.classList.contains('is-expanded');
        
        if (isExpanded) {
            // Collapse
            const truncatedValue = valueDiv.getAttribute('data-display-value');
            if (truncatedValue) {
                const textNode = Array.from(valueDiv.childNodes).find(node => node.nodeType === Node.TEXT_NODE);
                if (textNode) {
                    textNode.textContent = truncatedValue;
                }
            }
            valueDiv.classList.remove('is-expanded');
        } else {
            // Expand
            const fullValue = valueDiv.getAttribute('data-full-value');
            if (fullValue) {
                const textNode = Array.from(valueDiv.childNodes).find(node => node.nodeType === Node.TEXT_NODE);
                if (textNode) {
                    textNode.textContent = fullValue;
                }
            }
            valueDiv.classList.add('is-expanded');
        }
    }
})();
