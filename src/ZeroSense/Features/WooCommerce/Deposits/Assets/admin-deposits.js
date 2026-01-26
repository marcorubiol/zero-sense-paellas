(function ($) {
    const settings = window.zsDepositsAdminSettings || {};
    let isCalculating = false;

    function getOrderId() {
        const params = new URLSearchParams(window.location.search);
        return params.get('post');
    }

    function ajaxUpdateDeposit(amount, options = {}) {
        const orderId = getOrderId();
        if (!orderId || amount === undefined) {
            if (!options.mode) {
                return $.Deferred().reject().promise();
            }
        }

        return $.ajax({
            url: settings.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: settings.action || 'zs_deposits_update_amount',
                order_id: orderId,
                security: settings.nonce,
                ...(options.mode ? { mode: options.mode } : {}),
                ...(amount !== undefined && amount !== null ? { deposit_amount: amount } : {})
            }
        });
    }

    function openInlineEditor($displayWrapper, editSelector, inputSelector, editContainerSelector) {
        const $row = $displayWrapper.closest('.total');
        const $editContainer = $row.find(editContainerSelector);
        const $input = $editContainer.find(inputSelector);

        if (!$input.length) {
            return;
        }

        $editContainer.data('originalValue', $input.val());
        $displayWrapper.hide();
        $editContainer.show();
        $input.focus().select();
    }

    function closeInlineEditor($displayWrapper, editContainerSelector, restoreOriginal = true) {
        const $row = $displayWrapper.closest('.total');
        const $editContainer = $row.find(editContainerSelector);
        if (restoreOriginal) {
            const original = $editContainer.data('originalValue');
            if (original !== undefined) {
                $editContainer.find('input').val(original);
            }
        }

        $editContainer.hide();
        $displayWrapper.show();
    }

    function applyUpdatedValues(response) {
        if (!response || !response.success || !response.data) {
            return;
        }

        const depositHtml = response.data.deposit_amount || '';
        const formattedDeposit = response.data.formatted_deposit_amount || '';
        const remainingHtml = response.data.remaining_amount || '';
        const mode = response.data.mode || 'manual';

        if (depositHtml) {
            $('.deposit-amount-value').html(depositHtml);
            $('.zs-deposits-deposit-display').html(depositHtml);
        }

        if (formattedDeposit) {
            $('.deposit-amount-input').val(formattedDeposit);
            $('#zs_deposits_deposit_amount').val(formattedDeposit);
        }

        if (remainingHtml) {
            $('.remaining-balance-display').html(remainingHtml);
            const remainingText = $('<div>').html(remainingHtml).text() || remainingHtml;
            $('.zs-deposits-remaining-amount').text(remainingText);
        }

        const $indicator = $('.zs-deposit-mode-indicator');
        if ($indicator.length) {
            if (mode === 'auto') {
                $indicator.text('AUTO').removeClass('is-manual').addClass('is-auto');
            } else {
                $indicator.text('MAN').removeClass('is-auto').addClass('is-manual');
            }
        }
    }

    function setupInlineEditing() {
        $(document).on('click', '.edit-deposit', function (e) {
            e.preventDefault();
            const $displayWrapper = $(this).closest('.deposit-amount-display');
            openInlineEditor($displayWrapper, '.edit-deposit', '.deposit-amount-input', '.deposit-amount-edit');
        });

        $(document).on('click', '.cancel-deposit', function (e) {
            e.preventDefault();
            const $displayWrapper = $(this).closest('.total').find('.deposit-amount-display');
            closeInlineEditor($displayWrapper, '.deposit-amount-edit');
        });

        $(document).on('click', '.save-deposit', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $row = $button.closest('.total');
            const $displayWrapper = $row.find('.deposit-amount-display');
            const $editContainer = $row.find('.deposit-amount-edit');
            const newAmount = $row.find('.deposit-amount-input').val();
            if (!newAmount) {
                return;
            }

            $button.prop('disabled', true);
            $editContainer.addClass('is-saving');
            const $spinner = $('<span class="spinner is-active" style="float:none;"></span>');
            $editContainer.append($spinner);

            ajaxUpdateDeposit(newAmount)
                .done(function (response) {
                    applyUpdatedValues(response);
                    // Do not restore old input value after saving.
                    closeInlineEditor($displayWrapper, '.deposit-amount-edit', false);
                })
                .always(function () {
                    $spinner.remove();
                    $editContainer.removeClass('is-saving');
                    $button.prop('disabled', false);
                });
        });

        // Keep hidden field in sync while typing (so Order Update submits the latest value)
        $(document).on('input', '.deposit-amount-input', function () {
            const val = $(this).val();
            const $hidden = $('#zs_deposits_deposit_amount');
            if ($hidden.length) {
                $hidden.val(val);
            }
        });

        $(document).on('keypress', '.deposit-amount-input', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).closest('.total').find('.save-deposit').trigger('click');
            }
        });

        $(document).on('click', '.zs-deposits-edit-deposit', function (e) {
            e.preventDefault();
            const $container = $(this).closest('.zs-deposits-deposit-container');
            $container.addClass('is-editing');
            $container.find('.zs-deposits-deposit-input').focus().select();
        });

        $(document).on('click', '.zs-deposits-cancel-deposit', function (e) {
            e.preventDefault();
            const $container = $(this).closest('.zs-deposits-deposit-container');
            $container.removeClass('is-editing');
        });

        $(document).on('click', '.zs-deposits-save-deposit', function (e) {
            e.preventDefault();
            const $button = $(this);
            const $container = $button.closest('.zs-deposits-deposit-container');
            const $editSection = $container.find('.zs-deposits-deposit-edit');
            const newAmount = $container.find('.zs-deposits-deposit-input').val();
            if (!newAmount) {
                return;
            }

            $button.prop('disabled', true);
            $container.addClass('is-saving');
            const $spinner = $('<span class="spinner is-active" style="float:none;"></span>');
            $editSection.append($spinner);

            ajaxUpdateDeposit(newAmount)
                .done(function (response) {
                    if (!response.success) {
                        return;
                    }

                    const amountText = $(response.data.deposit_amount).text();
                    $container.find('.zs-deposits-deposit-display').text(amountText);
                    $('.zs-deposits-remaining-amount').text($(response.data.remaining_amount).text());
                    $('#zs_deposits_deposit_amount').val(response.data.formatted_deposit_amount);
                    applyUpdatedValues(response);
                    $container.removeClass('is-editing');
                })
                .always(function () {
                    $spinner.remove();
                    $container.removeClass('is-saving');
                    $button.prop('disabled', false);
                });
        });

        $(document).on('keypress', '.zs-deposits-deposit-input', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).closest('.zs-deposits-deposit-container').find('.zs-deposits-save-deposit').trigger('click');
            }
        });
    }

    function watchWooRecalculate() {
        function triggerAutoRecalc() {
            return ajaxUpdateDeposit(null, { mode: 'auto' })
                .done(function (response) {
                    applyUpdatedValues(response);
                });
        }

        $(document).on('click', 'button.calculate-action', function () {
            if (isCalculating) {
                return;
            }

            isCalculating = true;
            setTimeout(function () {
                triggerAutoRecalc().always(function () {
                    isCalculating = false;
                });
            }, 1000);
        });

        $(document.body).on('updated_order_totals', function () {
            if (isCalculating) {
                return;
            }

            isCalculating = true;
            triggerAutoRecalc().always(function () {
                isCalculating = false;
            });
        });

        $(document).ajaxComplete(function (event, xhr, settings) {
            if (isCalculating) {
                return;
            }

            if (!settings || !settings.data) {
                return;
            }

            if (settings.data.indexOf('action=woocommerce_calc_line_taxes') === -1 &&
                settings.data.indexOf('action=woocommerce_calc_line_tax') === -1 &&
                settings.data.indexOf('action=woocommerce_save_order_items') === -1) {
                return;
            }

            isCalculating = true;
            triggerAutoRecalc().always(function () {
                isCalculating = false;
            });
        });
    }

    function setupResetToAuto() {
        $(document).on('click', '.zs-deposits-reset-to-auto', function (e) {
            e.preventDefault();

            const $button = $(this);
            const orderId = $button.data('order-id');

            if (!orderId) {
                return;
            }

            if (!confirm('Reset deposit to automatic calculation?\n\nThis will recalculate the deposit based on the configured percentage.')) {
                return;
            }

            $button.prop('disabled', true);
            const $spinner = $('<span class="spinner is-active" style="float:none;margin:0 4px;"></span>');
            $button.after($spinner);

            const ajaxUrl = settings.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
            const nonce = settings.nonce || '';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'zs_deposits_reset_to_auto',
                    order_id: orderId,
                    security: nonce
                }
            }).done(function (response) {
                if (response.success) {
                    // Reload page to show updated values
                    window.location.reload();
                } else {
                    alert(response.data && response.data.message ? response.data.message : 'Error resetting deposit');
                    $button.prop('disabled', false);
                    $spinner.remove();
                }
            }).fail(function () {
                alert('Error resetting deposit. Please try again.');
                $button.prop('disabled', false);
                $spinner.remove();
            });
        });
    }

    $(function () {
        setupInlineEditing();
        watchWooRecalculate();
        setupResetToAuto();
        
        // Ensure hidden fields have correct values before form submission
        $('form#post').on('submit', function() {
            // Get current deposit amount from display
            var currentDeposit = $('.deposit-amount-input').val() || $('.deposit-amount-value').text();
            
            // Update hidden fields
            $('#zs_deposits_deposit_amount').val(currentDeposit);
            $('#zs_deposits_deposit_manual_override').val('yes');
            
        });
    });
})(jQuery);
