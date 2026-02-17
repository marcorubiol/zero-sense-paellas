(function($) {
    'use strict';
    
    class StockAdminUI {
        constructor() {
            this.dirtyFields = new Set();
            this.searchTimeout = null;
            this.isLocked = true; // Always start locked
            this.init();
        }
        
        init() {
            this.initLockToggle();
            this.initChangeTracking();
            this.initSearch();
            this.initSaveButtons();
        }
        
        initLockToggle() {
            const $lockBtn = $('.zs-lock-toggle');
            
            $lockBtn.on('click', () => {
                if (!this.isLocked && this.dirtyFields.size > 0) {
                    // Prevent locking with unsaved changes
                    this.showToast('Please save your changes before locking the table.', 'warning');
                    return;
                }
                
                this.toggleLock();
            });
        }
        
        toggleLock() {
            this.isLocked = !this.isLocked;
            const $lockBtn = $('.zs-lock-toggle');
            const $inputs = $('.stock-input');
            
            if (this.isLocked) {
                // Lock the table
                $inputs.prop('disabled', true);
                $lockBtn.attr('data-locked', 'true');
                $lockBtn.attr('title', 'Click to unlock table for editing');
                $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                $lockBtn.find('.lock-text').text('Locked');
                this.showToast('Table locked. Click the lock button to edit.', 'info');
            } else {
                // Unlock the table
                $inputs.prop('disabled', false);
                $lockBtn.attr('data-locked', 'false');
                $lockBtn.attr('title', 'Click to lock table');
                $lockBtn.find('.dashicons').removeClass('dashicons-lock').addClass('dashicons-unlock');
                $lockBtn.find('.lock-text').text('Unlocked');
                this.showToast('Table unlocked. You can now edit stock quantities.', 'success');
            }
        }
        
        initChangeTracking() {
            $('.stock-input').on('change', (e) => {
                const key = $(e.target).data('key');
                this.dirtyFields.add(key);
                $(e.target).addClass('is-dirty');
            });
        }
        
        initSearch() {
            $('#zs-stock-search').on('keyup', (e) => {
                clearTimeout(this.searchTimeout);
                
                this.searchTimeout = setTimeout(() => {
                    this.filterRows(e.target.value);
                }, 300); // Debounce 300ms
            });
        }
        
        filterRows(searchTerm) {
            const term = searchTerm.toLowerCase();
            
            $('.zs-stock-table tbody tr').each(function() {
                // Skip category headers in filtering
                if ($(this).hasClass('zs-category-header')) {
                    return;
                }
                
                const materialName = $(this).find('.zs-sticky-col').text().toLowerCase();
                const category = $(this).data('category') || '';
                
                if (term === '' || materialName.indexOf(term) !== -1 || category.indexOf(term) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
            
            // Show/hide category headers based on visible items
            $('.zs-stock-table tbody tr.zs-category-header').each(function() {
                const $header = $(this);
                let hasVisibleItems = false;
                
                // Check if any items in this category are visible
                $header.nextUntil('.zs-category-header').each(function() {
                    if ($(this).is(':visible')) {
                        hasVisibleItems = true;
                        return false; // break
                    }
                });
                
                if (hasVisibleItems || term === '') {
                    $header.show();
                } else {
                    $header.hide();
                }
            });
        }
        
        initSaveButtons() {
            $('.zs-save-stock').on('click', () => {
                if (this.isLocked) {
                    this.showToast('Table is locked. Unlock it to make changes.', 'error');
                    return;
                }
                this.saveChanges();
            });
        }
        
        saveChanges() {
            if (this.dirtyFields.size === 0) {
                this.showToast('No changes to save', 'error');
                return;
            }
            
            const changedData = {};
            
            this.dirtyFields.forEach(key => {
                const input = $(`.stock-input[data-key="${key}"]`);
                changedData[key] = input.val();
            });
            
            // Hide icon and show spinner
            const saveBtn = $('.zs-save-stock');
            saveBtn.prop('disabled', true);
            saveBtn.addClass('is-saving');
            
            // Prepare FormData for WordPress AJAX
            const formData = new FormData();
            formData.append('action', 'zs_update_stock');
            formData.append('nonce', zsStockAdmin.nonce);
            formData.append('changes', JSON.stringify(changedData));
            
            // AJAX: Solo actualiza campos modificados
            fetch(zsStockAdmin.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Eliminar clase .is-dirty de campos guardados
                    this.dirtyFields.forEach(key => {
                        const input = $(`.stock-input[data-key="${key}"]`);
                        input.removeClass('is-dirty');
                    });
                    
                    // Limpiar set de campos modificados
                    this.dirtyFields.clear();
                    
                    // Mostrar toast de éxito
                    this.showToast('Stock updated successfully', 'success');
                } else {
                    this.showToast('Error saving changes', 'error');
                }
            })
            .catch(error => {
                this.showToast('Connection error', 'error');
            })
            .finally(() => {
                // Restore button
                saveBtn.prop('disabled', false);
                saveBtn.removeClass('is-saving');
            });
        }
        
        showToast(message, type) {
            // Crear toast flotante
            const toast = $('<div></div>')
                .addClass('zs-toast')
                .addClass('zs-toast-' + type)
                .text(message);
            
            $('body').append(toast);
            
            // Animar entrada
            setTimeout(() => {
                toast.addClass('show');
            }, 10);
            
            // Auto-cerrar después de 3 segundos
            setTimeout(() => {
                toast.removeClass('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }
    }
    
    // Inicializar cuando el DOM esté listo
    $(document).ready(function() {
        if ($('.zs-stock-admin-page').length) {
            new StockAdminUI();
        }
    });
    
})(jQuery);
