(function($) {
    if (typeof zsRecipesData === 'undefined') {
        return;
    }

    var ajaxUrl = zsRecipesData.ajax_url;
    var nonce = zsRecipesData.nonce;
    var rowCount = parseInt(zsRecipesData.rowCount, 10);
    var utensilRowCount = parseInt(zsRecipesData.utensilRowCount, 10);
    var liquidRowCount = parseInt(zsRecipesData.liquidRowCount, 10);
    var stockRowCount = parseInt(zsRecipesData.stockRowCount || 0, 10);

    function initSelect(element, taxonomy) {
        if (typeof $.fn.selectWoo === 'undefined') {
            return;
        }
        
        var actionSearch = 'zs_' + taxonomy + '_search';
        var actionCreate = 'zs_' + taxonomy + '_create';
        var selectClass = '.zs-' + taxonomy + '-select';
        
        if (!$(element).data('select2')) {
            $(element).selectWoo({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                createTag: function(params) {
                    var term = $.trim(params.term);
                    if (term === '') return null;
                    return {
                        id: term,
                        text: term + ' ' + zsRecipesData.i18n.create_new,
                        newTag: true
                    };
                },
                insertTag: function(data, tag) {
                    data.push(tag);
                },
                ajax: {
                    url: ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        var selectedIds = [];
                        $(selectClass).each(function() {
                            var val = $(this).val();
                            if (val && !isNaN(val)) {
                                selectedIds.push(val);
                            }
                        });
                        
                        return {
                            action: actionSearch,
                            nonce: nonce,
                            q: params.term || '',
                            exclude: selectedIds.join(',')
                        };
                    },
                    processResults: function(data) {
                        return data;
                    },
                    transport: function(params, success, failure) {
                        var $request = $.ajax(params);
                        $request.then(success);
                        $request.fail(function(jqXHR, textStatus) {
                            if (textStatus !== 'abort') failure();
                        });
                        return $request;
                    }
                }
            });
            
            $(element).on('select2:closing', function() {
                setTimeout(function() {
                    var val = $(element).val();
                    if (val && typeof val === 'string' && isNaN(val)) {
                        $(element).val(null).trigger('change.select2');
                        createTerm(val, element, actionCreate, taxonomy);
                    }
                }, 100);
            });
        }
    }
    
    function createTerm(name, selectElement, actionCreate, taxonomy) {
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: actionCreate,
                nonce: nonce,
                name: name
            },
            success: function(resp) {
                if (resp && resp.success && resp.data) {
                    $(selectElement).empty();
                    var option = new Option(resp.data.text, resp.data.id, true, true);
                    $(selectElement).append(option).trigger('change');
                } else {
                    alert(zsRecipesData.i18n['error_create_' + taxonomy] || 'Error');
                }
            },
            error: function() {
                alert(zsRecipesData.i18n['error_conn_' + taxonomy] || 'Connection error');
            }
        });
    }

    // Ingredients
    function addNewRow() {
        var unitOptions = '';
        for (var i = 0; i < zsRecipesData.units.length; i++) {
            unitOptions += '<option value="' + zsRecipesData.units[i] + '">' + zsRecipesData.unitLabels[i] + '</option>';
        }
        
        var newRow = '<tr data-row="' + rowCount + '">' +
            '<td class="zs-drag-handle" style="cursor: grab; text-align: center; color: #a7aaad; vertical-align: middle;"><span class="dashicons dashicons-menu" style="font-size: 16px; line-height: 2;"></span></td>' +
            '<td><select name="zs_recipe_ingredients[ingredient][]" class="zs-ingredient-select" style="width:100%;" data-placeholder="' + zsRecipesData.i18n.search_or_create + '"></select></td>' +
            '<td><input type="number" step="0.001" min="0" name="zs_recipe_ingredients[qty][]" value="" style="width:100%;"></td>' +
            '<td><select name="zs_recipe_ingredients[unit][]" style="width:100%;">' + unitOptions + '</select></td>' +
            '<td><button type="button" class="button zs-recipe-remove">' + zsRecipesData.i18n.remove + '</button></td>' +
        '</tr>';
        
        $('#zs-recipe-rows').append(newRow);
        initSelect($('#zs-recipe-rows tr:last .zs-ingredient-select'), 'ingredient');
        rowCount++;
    }

    // Utensils
    function addNewUtensilRow() {
        var unitOptions = '';
        for (var i = 0; i < zsRecipesData.utensilUnits.length; i++) {
            unitOptions += '<option value="' + zsRecipesData.utensilUnits[i] + '">' + zsRecipesData.utensilUnitLabels[i] + '</option>';
        }
        
        var newRow = '<tr data-row="' + utensilRowCount + '">' +
            '<td class="zs-drag-handle" style="cursor: grab; text-align: center; color: #a7aaad; vertical-align: middle;"><span class="dashicons dashicons-menu" style="font-size: 16px; line-height: 2;"></span></td>' +
            '<td><select name="zs_recipe_utensils[utensil][]" class="zs-utensil-select" style="width:100%;" data-placeholder="' + zsRecipesData.i18n.search_or_create + '"></select></td>' +
            '<td><input type="number" step="0.001" min="0" name="zs_recipe_utensils[qty][]" value="" style="width:100%;"></td>' +
            '<td><input type="number" step="1" min="1" name="zs_recipe_utensils[pax_ratio][]" value="1" style="width:100%;" placeholder="1"></td>' +
            '<td><select name="zs_recipe_utensils[unit][]" style="width:100%;">' + unitOptions + '</select></td>' +
            '<td><button type="button" class="button zs-utensil-remove">' + zsRecipesData.i18n.remove + '</button></td>' +
        '</tr>';
        
        $('#zs-utensil-rows').append(newRow);
        initSelect($('#zs-utensil-rows tr:last .zs-utensil-select'), 'utensil');
        utensilRowCount++;
    }

    // Liquids
    function addNewLiquidRow() {
        var newRow = '<tr data-row="' + liquidRowCount + '">' +
            '<td class="zs-drag-handle" style="cursor: grab; text-align: center; color: #a7aaad; vertical-align: middle;"><span class="dashicons dashicons-menu" style="font-size: 16px; line-height: 2;"></span></td>' +
            '<td><select name="zs_recipe_liquids[liquid][]" class="zs-liquid-select" style="width:100%;" data-placeholder="' + zsRecipesData.i18n.search_or_create + '"></select></td>' +
            '<td><input type="number" step="0.001" min="0" name="zs_recipe_liquids[qty][]" value="" style="width:100%;"></td>' +
            '<td><button type="button" class="button zs-liquid-remove">' + zsRecipesData.i18n.remove + '</button></td>' +
        '</tr>';
        $('#zs-liquid-rows').append(newRow);
        initSelect($('#zs-liquid-rows tr:last .zs-liquid-select'), 'liquid');
        liquidRowCount++;
    }

    $(document).ready(function() {
        $('.zs-ingredient-select').each(function() { initSelect(this, 'ingredient'); });
        $('.zs-utensil-select').each(function() { initSelect(this, 'utensil'); });
        $('.zs-liquid-select').each(function() { initSelect(this, 'liquid'); });

        // Initialize sortable for drag-and-drop reordering
        $('#zs-recipe-rows, #zs-utensil-rows, #zs-liquid-rows, #zs-stock-rows').sortable({
            handle: '.zs-drag-handle',
            items: '> tr',
            cursor: 'grabbing',
            opacity: 0.8,
            helper: function(e, ui) {
                ui.children().each(function() {
                    $(this).width($(this).width());
                });
                return ui;
            },
            start: function(event, ui) {
                // Destroy select2 instances before moving the DOM element to prevent them from breaking
                ui.item.find('select.zs-ingredient-select, select.zs-utensil-select, select.zs-liquid-select').each(function() {
                    if ($(this).data('select2')) {
                        $(this).selectWoo('destroy');
                    }
                });
            },
            stop: function(event, ui) {
                // Re-initialize select2 after the DOM element is dropped in its new position
                ui.item.find('select.zs-ingredient-select').each(function() { initSelect(this, 'ingredient'); });
                ui.item.find('select.zs-utensil-select').each(function() { initSelect(this, 'utensil'); });
                ui.item.find('select.zs-liquid-select').each(function() { initSelect(this, 'liquid'); });
            }
        });
    });
    
    $('#zs-recipe-add-row').on('click', addNewRow);
    $(document).on('click', '.zs-recipe-remove', function() { $(this).closest('tr').remove(); });
    
    $('#zs-utensil-add-row').on('click', addNewUtensilRow);
    $(document).on('click', '.zs-utensil-remove', function() { $(this).closest('tr').remove(); });
    
    $('#zs-liquid-add-row').on('click', addNewLiquidRow);
    $(document).on('click', '.zs-liquid-remove', function() { $(this).closest('tr').remove(); });

    // Stock (Equipment)
    function buildStockMaterialOptions(selectedKey) {
        var groups = zsRecipesData.stockMaterialGroups || {};
        var materials = zsRecipesData.stockMaterials || [];
        var grouped = {};
        for (var i = 0; i < materials.length; i++) {
            var m = materials[i];
            if (!grouped[m.group]) grouped[m.group] = [];
            grouped[m.group].push(m);
        }
        var html = '<option value="">' + zsRecipesData.i18n.select_material + '</option>';
        for (var groupKey in grouped) {
            var groupLabel = groups[groupKey] || groupKey;
            html += '<optgroup label="' + groupLabel + '">';
            var mats = grouped[groupKey];
            for (var j = 0; j < mats.length; j++) {
                var sel = (selectedKey && mats[j].key === selectedKey) ? ' selected="selected"' : '';
                html += '<option value="' + mats[j].key + '"' + sel + '>' + mats[j].label + '</option>';
            }
            html += '</optgroup>';
        }
        return html;
    }

    function addNewStockRow() {
        var newRow = '<tr data-row="' + stockRowCount + '">' +
            '<td class="zs-drag-handle" style="cursor: grab; text-align: center; color: #a7aaad; vertical-align: middle;"><span class="dashicons dashicons-menu" style="font-size: 16px; line-height: 2;"></span></td>' +
            '<td><select name="zs_recipe_stock[material_key][]" style="width:100%;">' + buildStockMaterialOptions('') + '</select></td>' +
            '<td><input type="number" step="0.001" min="0" name="zs_recipe_stock[qty][]" value="" style="width:100%;"></td>' +
            '<td><input type="number" step="1" min="1" name="zs_recipe_stock[pax_ratio][]" value="1" style="width:100%;" placeholder="1"></td>' +
            '<td><button type="button" class="button zs-stock-remove">' + zsRecipesData.i18n.remove + '</button></td>' +
        '</tr>';
        $('#zs-stock-rows').append(newRow);
        stockRowCount++;
    }

    $('#zs-stock-add-row').on('click', addNewStockRow);
    $(document).on('click', '.zs-stock-remove', function() { $(this).closest('tr').remove(); });

    // Paella mode toggle
    $('input[name="zs_recipe_needs_paella"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('.zs-utensils-section').slideUp(300);
            $('.zs-liquids-section').slideDown(300);
            $('.zs-paella-mode-notice').slideDown(300);
        } else {
            $('.zs-utensils-section').slideDown(300);
            $('.zs-liquids-section').slideUp(300);
            $('.zs-paella-mode-notice').slideUp(300);
        }
    });

})(jQuery);
