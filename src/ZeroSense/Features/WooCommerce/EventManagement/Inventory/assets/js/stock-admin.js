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
            this.initAccordions();
            this.initSearchClear();
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
            const $saveBtn = $('.zs-save-stock');
            const $inputs = $('.stock-input');
            
            if (this.isLocked) {
                // Lock the table
                $inputs.prop('disabled', true);
                $lockBtn.attr('data-locked', 'true');
                $lockBtn.attr('title', 'Click to unlock for editing');
                $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                $lockBtn.find('.lock-text').text('Unlock');
                $lockBtn.show();
                $saveBtn.hide();
            } else {
                // Unlock the table
                $inputs.prop('disabled', false);
                $lockBtn.attr('data-locked', 'false');
                $lockBtn.attr('title', 'Click to lock table');
                $lockBtn.find('.dashicons').removeClass('dashicons-lock').addClass('dashicons-unlock');
                $lockBtn.find('.lock-text').text('Unlock');
                $lockBtn.hide();
                $saveBtn.show();
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
            
            // Filter rows within each accordion
            $('.zs-stock-accordion').each(function() {
                const $accordion = $(this);
                let hasVisibleItems = false;
                
                $accordion.find('.zs-stock-table tbody tr').each(function() {
                    // Skip category headers in filtering
                    if ($(this).hasClass('zs-category-header') || $(this).hasClass('zs-parent-category-header')) {
                        return;
                    }
                    
                    const materialName = $(this).find('.zs-sticky-col').text().toLowerCase();
                    const category = $(this).data('category') || '';
                    
                    if (term === '' || materialName.indexOf(term) !== -1 || category.indexOf(term) !== -1) {
                        $(this).show();
                        hasVisibleItems = true;
                    } else {
                        $(this).hide();
                    }
                });
                
                // Show/hide category headers based on visible items
                $accordion.find('.zs-stock-table tbody tr.zs-category-header').each(function() {
                    const $header = $(this);
                    let categoryHasVisible = false;
                    
                    // Check if any items in this category are visible
                    $header.nextUntil('.zs-category-header').each(function() {
                        if ($(this).is(':visible')) {
                            categoryHasVisible = true;
                            return false; // break
                        }
                    });
                    
                    if (categoryHasVisible || term === '') {
                        $header.show();
                    } else {
                        $header.hide();
                    }
                });
                
                // Show/hide accordion based on whether it has visible items
                if (term === '' || hasVisibleItems) {
                    $accordion.show();
                    $accordion.removeClass('collapsed');
                } else {
                    $accordion.hide();
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
                    
                    // Lock the table after saving
                    this.isLocked = true;
                    const $lockBtn = $('.zs-lock-toggle');
                    const $inputs = $('.stock-input');
                    
                    $inputs.prop('disabled', true);
                    $lockBtn.attr('data-locked', 'true');
                    $lockBtn.find('.dashicons').removeClass('dashicons-unlock').addClass('dashicons-lock');
                    $lockBtn.find('.lock-text').text('Unlock');
                    $lockBtn.show();
                    saveBtn.hide();
                    
                    // Mostrar toast de éxito
                    this.showToast('Stock saved and locked', 'success');
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
        
        initAccordions() {
            $('.zs-stock-accordion-header').on('click', function() {
                $(this).closest('.zs-stock-accordion').toggleClass('collapsed');
            });
        }
        
        initSearchClear() {
            const $searchInput = $('#zs-stock-search');
            const $clearBtn = $('.zs-search-clear');
            
            // Show/hide clear button based on input value
            $searchInput.on('input', function() {
                if ($(this).val().length > 0) {
                    $clearBtn.show();
                } else {
                    $clearBtn.hide();
                }
            });
            
            // Clear search on click
            $clearBtn.on('click', function() {
                $searchInput.val('').trigger('keyup');
                $clearBtn.hide();
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
