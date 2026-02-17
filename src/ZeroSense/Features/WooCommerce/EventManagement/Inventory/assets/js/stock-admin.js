(function($) {
    'use strict';
    
    class StockAdminUI {
        constructor() {
            this.dirtyFields = new Set();
            this.searchTimeout = null;
            this.init();
        }
        
        init() {
            this.initChangeTracking();
            this.initSearch();
            this.initSaveButtons();
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
            
            // Cambiar botón a estado "Guardando..."
            const saveBtn = $('.zs-save-stock');
            saveBtn.prop('disabled', true);
            saveBtn.html('<span class="spinner is-active"></span> Guardando...');
            
            // AJAX: Solo actualiza campos modificados
            fetch(zsStockAdmin.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'zs_update_stock',
                    nonce: zsStockAdmin.nonce,
                    changes: changedData
                })
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
                    this.showToast('✅ Stock actualizado', 'success');
                } else {
                    this.showToast('❌ Error al guardar', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                this.showToast('❌ Error de conexión', 'error');
            })
            .finally(() => {
                // Restaurar botón
                saveBtn.prop('disabled', false);
                saveBtn.html('💾 Save All Changes');
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
