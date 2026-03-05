(function($) {
    'use strict';

    class BulkProcessor {
        constructor(operation, config) {
            this.operation = operation; // 'create' or 'delete'
            this.config = config;
            this.queue = [];
            this.processed = 0;
            this.stats = {
                created: 0,
                reserved: 0,
                deleted: 0,
                skipped: 0,
                errors: 0,
                cleaned: 0
            };
            this.running = false;
            this.paused = false;
            this.startTime = null;
        }

        async start(statuses = null) {
            if (this.running) return;

            this.running = true;
            this.paused = false;
            this.processed = 0;
            this.stats = {
                created: 0,
                reserved: 0,
                deleted: 0,
                skipped: 0,
                errors: 0,
                cleaned: 0
            };
            this.startTime = Date.now();

            this.config.onStart();

            try {
                // Get queue
                const queueData = {
                    action: 'zs_bulk_get_queue',
                    nonce: window.zsBulkSync.nonce,
                    operation: this.operation
                };

                if (statuses) {
                    queueData.statuses = statuses;
                }

                const queueResponse = await $.post(window.zsBulkSync.ajaxUrl, queueData);

                if (!queueResponse.success) {
                    throw new Error(queueResponse.data || 'Failed to get queue');
                }

                this.queue = queueResponse.data.queue;
                this.config.onQueueReady(this.queue.length);

                // Process queue (cleanup is now a separate operation)
                await this.processQueue();

                this.config.onComplete(this.stats);
            } catch (error) {
                this.config.onError(error.message);
            } finally {
                this.running = false;
            }
        }

        async runCleanup() {
            this.config.onCleanupStart(this.queue.length);

            for (let i = 0; i < this.queue.length; i++) {
                if (this.paused) {
                    await this.waitForResume();
                }

                const orderId = this.queue[i];

                try {
                    const response = await $.post(window.zsBulkSync.ajaxUrl, {
                        action: 'zs_bulk_cleanup_one',
                        nonce: window.zsBulkSync.nonce,
                        order_id: orderId
                    });

                    if (response.success) {
                        if (response.data.action === 'cleaned') {
                            this.stats.cleaned++;
                        }
                        this.config.onCleanupProgress(i + 1, this.queue.length, response.data.message);
                    }
                } catch (error) {
                    // Continue on cleanup errors
                }

                await this.delay(500); // Small delay between cleanup
            }

            this.config.onCleanupComplete(this.stats.cleaned);
        }

        async processQueue() {
            for (let i = 0; i < this.queue.length; i++) {
                if (this.paused) {
                    await this.waitForResume();
                }

                const orderId = this.queue[i];
                const delay = this.config.getDelay() * 1000;

                try {
                    let response;
                    
                    if (this.operation === 'cleanup') {
                        response = await $.post(window.zsBulkSync.ajaxUrl, {
                            action: 'zs_bulk_cleanup_one',
                            nonce: window.zsBulkSync.nonce,
                            order_id: orderId
                        });
                    } else {
                        response = await $.post(window.zsBulkSync.ajaxUrl, {
                            action: 'zs_bulk_process_one',
                            nonce: window.zsBulkSync.nonce,
                            order_id: orderId,
                            operation: this.operation,
                            statuses: this.statuses
                        });
                    }

                    if (response.success) {
                        const action = response.data.action;
                        
                        if (action === 'created') {
                            this.stats.created++;
                            if (response.data.reserved) {
                                this.stats.reserved++;
                            }
                        } else if (action === 'deleted') {
                            this.stats.deleted++;
                        } else if (action === 'cleaned') {
                            this.stats.cleaned++;
                        } else if (action === 'skipped') {
                            this.stats.skipped++;
                        }

                        this.processed++;
                        this.config.onProgress(this.processed, this.queue.length, response.data.message, action, this.stats);
                    } else {
                        this.stats.errors++;
                        this.processed++;
                        this.config.onProgress(this.processed, this.queue.length, response.data || 'Unknown error', 'error', this.stats);
                    }
                } catch (error) {
                    this.stats.errors++;
                    this.processed++;
                    this.config.onProgress(this.processed, this.queue.length, error.message, 'error', this.stats);
                }

                // Delay before next order
                if (i < this.queue.length - 1) {
                    await this.delay(delay);
                }
            }
        }

        pause() {
            this.paused = true;
            this.config.onPause();
        }

        resume() {
            this.paused = false;
            this.config.onResume();
        }

        cancel() {
            this.running = false;
            this.paused = false;
            this.config.onCancel();
        }

        async waitForResume() {
            return new Promise(resolve => {
                const checkInterval = setInterval(() => {
                    if (!this.paused) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 100);
            });
        }

        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        getETA() {
            if (this.processed === 0) return 0;
            
            const elapsed = Date.now() - this.startTime;
            const avgTimePerOrder = elapsed / this.processed;
            const remaining = this.queue.length - this.processed;
            
            return Math.ceil((avgTimePerOrder * remaining) / 1000);
        }
    }

    // Create operation UI controller
    const createUI = {
        processor: null,
        
        init() {
            $('#zs-create-start').on('click', () => this.start());
            $('#zs-create-pause').on('click', () => this.togglePause());
            $('#zs-create-cancel').on('click', () => this.cancel());
        },

        start() {
            const statuses = [];
            $('input[name="create_statuses[]"]:checked').each(function() {
                statuses.push($(this).val());
            });

            if (statuses.length === 0) {
                alert('Please select at least one status');
                return;
            }

            $('#zs-create-start').hide();
            $('#zs-create-pause').show();
            $('#zs-create-cancel').show();
            $('#zs-create-delay').prop('disabled', true);
            $('input[name="create_statuses[]"]').prop('disabled', true);
            $('.zs-bulk-progress').first().show();
            $('.zs-bulk-stats').first().show();
            $('#zs-create-log').show().empty();

            this.processor = new BulkProcessor('create', {
                getDelay: () => parseInt($('#zs-create-delay').val()),
                onStart: () => this.updateLog('Starting...', 'info'),
                onQueueReady: (total) => this.updateLog(`Found ${total} orders to process`, 'info'),
                onCleanupStart: () => {},
                onCleanupProgress: () => {},
                onCleanupComplete: () => {},
                onProgress: (current, total, message, action, stats) => {
                    const percent = Math.round((current / total) * 100);
                    $('.zs-progress-fill').first().css('width', percent + '%');
                    $('.zs-progress-text').first().text(`${percent}% (${current}/${total} orders)`);
                    
                    const eta = this.processor.getETA();
                    if (eta > 0) {
                        $('.zs-progress-eta').first().text(`Estimated time: ${this.formatTime(eta)}`);
                    }
                    
                    this.updateStats(stats);
                    this.updateLog(message, action);
                },
                onPause: () => {
                    $('#zs-create-pause').text('Resume');
                    this.updateLog('⏸ Paused', 'info');
                },
                onResume: () => {
                    $('#zs-create-pause').text('Pause');
                    this.updateLog('▶ Resumed', 'info');
                },
                onCancel: () => {
                    this.reset();
                    this.updateLog('✖ Cancelled', 'error');
                },
                onComplete: (stats) => {
                    this.reset();
                    this.updateLog(`✓ Complete! Created: ${stats.created}, Reserved: ${stats.reserved}, Skipped: ${stats.skipped}, Errors: ${stats.errors}`, 'success');
                },
                onError: (message) => {
                    this.reset();
                    this.updateLog(`✖ Error: ${message}`, 'error');
                }
            });

            this.processor.start(statuses);
        },

        togglePause() {
            if (this.processor.paused) {
                this.processor.resume();
            } else {
                this.processor.pause();
            }
        },

        cancel() {
            if (confirm('Are you sure you want to cancel?')) {
                this.processor.cancel();
            }
        },

        reset() {
            $('#zs-create-start').show();
            $('#zs-create-pause').hide().text('Pause');
            $('#zs-create-cancel').hide();
            $('#zs-create-delay').prop('disabled', false);
            $('input[name="create_statuses[]"]').prop('disabled', false);
        },

        updateStats(stats) {
            $('#zs-create-count-created').text(stats.created);
            $('#zs-create-count-reserved').text(stats.reserved);
            $('#zs-create-count-skipped').text(stats.skipped);
            $('#zs-create-count-errors').text(stats.errors);
        },

        updateLog(message, type) {
            const icon = {
                created: '✓',
                deleted: '✓',
                skipped: '⚠',
                error: '✖',
                info: '→',
                success: '✓'
            }[type] || '•';

            const className = {
                created: 'success',
                deleted: 'success',
                skipped: 'warning',
                error: 'error',
                info: 'info',
                success: 'success'
            }[type] || '';

            const $log = $('#zs-create-log');
            $log.append(`<div class="zs-log-entry zs-log-${className}">${icon} ${message}</div>`);
            $log.scrollTop($log[0].scrollHeight);
        },

        formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
        }
    };

    // Delete operation UI controller
    const deleteUI = {
        processor: null,
        
        init() {
            $('#zs-delete-start').on('click', () => this.start());
            $('#zs-delete-pause').on('click', () => this.togglePause());
            $('#zs-delete-cancel').on('click', () => this.cancel());
        },

        start() {
            const statuses = [];
            $('input[name="delete_statuses[]"]:checked').each(function() {
                statuses.push($(this).val());
            });

            if (statuses.length === 0) {
                alert('Please select at least one status');
                return;
            }

            if (!confirm(`⚠️ WARNING: This will DELETE calendar events for orders with these statuses: ${statuses.join(', ')}. This cannot be undone. Are you sure?`)) {
                return;
            }

            $('#zs-delete-start').hide();
            $('#zs-delete-pause').show();
            $('#zs-delete-cancel').show();
            $('#zs-delete-delay').prop('disabled', true);
            $('input[name="delete_statuses[]"]').prop('disabled', true);
            $('.zs-bulk-progress').last().show();
            $('.zs-bulk-stats').last().show();
            $('#zs-delete-log').show().empty();

            this.processor = new BulkProcessor('delete', {
                getDelay: () => parseInt($('#zs-delete-delay').val()),
                onStart: () => this.updateLog('Starting...', 'info'),
                onQueueReady: (total) => this.updateLog(`Found ${total} orders to delete`, 'info'),
                onCleanupStart: () => {},
                onCleanupProgress: () => {},
                onCleanupComplete: () => {},
                onProgress: (current, total, message, action, stats) => {
                    const percent = Math.round((current / total) * 100);
                    $('.zs-progress-fill').last().css('width', percent + '%');
                    $('.zs-progress-text').last().text(`${percent}% (${current}/${total} orders)`);
                    
                    const eta = this.processor.getETA();
                    if (eta > 0) {
                        $('.zs-progress-eta').last().text(`Estimated time: ${this.formatTime(eta)}`);
                    }
                    
                    this.updateStats(stats);
                    this.updateLog(message, action);
                },
                onPause: () => {
                    $('#zs-delete-pause').text('Resume');
                    this.updateLog('⏸ Paused', 'info');
                },
                onResume: () => {
                    $('#zs-delete-pause').text('Pause');
                    this.updateLog('▶ Resumed', 'info');
                },
                onCancel: () => {
                    this.reset();
                    this.updateLog('✖ Cancelled', 'error');
                },
                onComplete: (stats) => {
                    this.reset();
                    this.updateLog(`✓ Complete! Deleted: ${stats.deleted}, Skipped: ${stats.skipped}, Errors: ${stats.errors}`, 'success');
                },
                onError: (message) => {
                    this.reset();
                    this.updateLog(`✖ Error: ${message}`, 'error');
                }
            });

            this.processor.start(statuses);
        },

        togglePause() {
            if (this.processor.paused) {
                this.processor.resume();
            } else {
                this.processor.pause();
            }
        },

        cancel() {
            if (confirm('Are you sure you want to cancel?')) {
                this.processor.cancel();
            }
        },

        reset() {
            $('#zs-delete-start').show();
            $('#zs-delete-pause').hide().text('Pause');
            $('#zs-delete-cancel').hide();
            $('#zs-delete-delay').prop('disabled', false);
            $('input[name="delete_statuses[]"]').prop('disabled', false);
        },

        updateStats(stats) {
            $('#zs-delete-count-deleted').text(stats.deleted);
            $('#zs-delete-count-skipped').text(stats.skipped);
            $('#zs-delete-count-errors').text(stats.errors);
        },

        updateLog(message, type) {
            const icon = {
                created: '✓',
                deleted: '✓',
                skipped: '⚠',
                error: '✖',
                info: '→',
                success: '✓'
            }[type] || '•';

            const className = {
                created: 'success',
                deleted: 'success',
                skipped: 'warning',
                error: 'error',
                info: 'info',
                success: 'success'
            }[type] || '';

            const $log = $('#zs-delete-log');
            $log.append(`<div class="zs-log-entry zs-log-${className}">${icon} ${message}</div>`);
            $log.scrollTop($log[0].scrollHeight);
        },

        formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
        }
    };

    // Cleanup operation UI controller
    const cleanupUI = {
        processor: null,
        
        init() {
            $('#zs-cleanup-start').on('click', () => this.start());
            $('#zs-cleanup-pause').on('click', () => this.togglePause());
            $('#zs-cleanup-cancel').on('click', () => this.cancel());
        },

        start() {
            const statuses = [];
            $('input[name="cleanup_statuses[]"]:checked').each(function() {
                statuses.push($(this).val());
            });

            if (statuses.length === 0) {
                alert('Please select at least one status');
                return;
            }

            if (!confirm(`⚠️ WARNING: This will REMOVE all Google Calendar Event IDs from orders with these statuses: ${statuses.join(', ')}. You will need to recreate all events. This cannot be undone. Are you absolutely sure?`)) {
                return;
            }

            $('#zs-cleanup-start').hide();
            $('#zs-cleanup-pause').show();
            $('#zs-cleanup-cancel').show();
            $('#zs-cleanup-delay').prop('disabled', true);
            $('input[name="cleanup_statuses[]"]').prop('disabled', true);
            $('.zs-bulk-section').eq(1).find('.zs-bulk-progress').show();
            $('.zs-bulk-section').eq(1).find('.zs-bulk-stats').show();
            $('#zs-cleanup-log').show().empty();

            this.processor = new BulkProcessor('cleanup', {
                getDelay: () => parseInt($('#zs-cleanup-delay').val()),
                onStart: () => this.updateLog('Starting cleanup...', 'info'),
                onQueueReady: (total) => this.updateLog(`Found ${total} orders with event IDs to clean`, 'info'),
                onCleanupStart: () => {},
                onCleanupProgress: () => {},
                onCleanupComplete: () => {},
                onProgress: (current, total, message, action, stats) => {
                    const percent = Math.round((current / total) * 100);
                    $('.zs-bulk-section').eq(1).find('.zs-progress-fill').css('width', percent + '%');
                    $('.zs-bulk-section').eq(1).find('.zs-progress-text').text(`${percent}% (${current}/${total} orders)`);
                    
                    const eta = this.processor.getETA();
                    if (eta > 0) {
                        $('.zs-bulk-section').eq(1).find('.zs-progress-eta').text(`Estimated time: ${this.formatTime(eta)}`);
                    }
                    
                    this.updateStats(stats);
                    this.updateLog(message, action);
                },
                onPause: () => {
                    $('#zs-cleanup-pause').text('Resume');
                    this.updateLog('⏸ Paused', 'info');
                },
                onResume: () => {
                    $('#zs-cleanup-pause').text('Pause');
                    this.updateLog('▶ Resumed', 'info');
                },
                onCancel: () => {
                    this.reset();
                    this.updateLog('✖ Cancelled', 'error');
                },
                onComplete: (stats) => {
                    this.reset();
                    this.updateLog(`✓ Complete! Cleaned: ${stats.cleaned}, Skipped: ${stats.skipped}, Errors: ${stats.errors}`, 'success');
                },
                onError: (message) => {
                    this.reset();
                    this.updateLog(`✖ Error: ${message}`, 'error');
                }
            });

            this.processor.start(statuses);
        },

        togglePause() {
            if (this.processor.paused) {
                this.processor.resume();
            } else {
                this.processor.pause();
            }
        },

        cancel() {
            if (confirm('Are you sure you want to cancel?')) {
                this.processor.cancel();
            }
        },

        reset() {
            $('#zs-cleanup-start').show();
            $('#zs-cleanup-pause').hide().text('Pause');
            $('#zs-cleanup-cancel').hide();
            $('#zs-cleanup-delay').prop('disabled', false);
            $('input[name="cleanup_statuses[]"]').prop('disabled', false);
        },

        updateStats(stats) {
            $('#zs-cleanup-count-cleaned').text(stats.cleaned);
            $('#zs-cleanup-count-skipped').text(stats.skipped);
            $('#zs-cleanup-count-errors').text(stats.errors);
        },

        updateLog(message, type) {
            const icon = {
                cleaned: '✓',
                skipped: '⚠',
                error: '✖',
                info: '→',
                success: '✓'
            }[type] || '•';

            const className = {
                cleaned: 'success',
                skipped: 'warning',
                error: 'error',
                info: 'info',
                success: 'success'
            }[type] || '';

            const $log = $('#zs-cleanup-log');
            $log.append(`<div class="zs-log-entry zs-log-${className}">${icon} ${message}</div>`);
            $log.scrollTop($log[0].scrollHeight);
        },

        formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
        }
    };

    // Statistics UI controller
    const statsUI = {
        withEventIds: [],
        withoutEventIds: [],
        
        init() {
            $('#zs-stats-check').on('click', () => this.checkStats());
            $('#zs-stat-card-with').on('click', () => this.showOrders('with'));
            $('#zs-stat-card-without').on('click', () => this.showOrders('without'));
            $('#zs-stats-orders-close').on('click', () => this.hideOrders());
        },

        async checkStats() {
            const statuses = [];
            $('input[name="stats_statuses[]"]:checked').each(function() {
                statuses.push($(this).val());
            });

            if (statuses.length === 0) {
                alert('Please select at least one status');
                return;
            }

            const $button = $('#zs-stats-check');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Loading...');

            try {
                const response = await $.post(window.zsBulkSync.ajaxUrl, {
                    action: 'zs_bulk_get_stats',
                    nonce: window.zsBulkSync.nonce,
                    statuses: statuses
                });

                if (response.success) {
                    const data = response.data;
                    
                    $('#zs-stat-total').text(data.total);
                    $('#zs-stat-with-event').text(data.with_event);
                    $('#zs-stat-without-event').text(data.without_event);
                    $('#zs-stat-percentage').text(data.percentage + '%');
                    
                    // Store IDs for later display
                    this.withEventIds = data.with_event_ids || [];
                    this.withoutEventIds = data.without_event_ids || [];
                    
                    $('#zs-stats-results').slideDown();
                    this.hideOrders();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                $button.prop('disabled', false).text(originalText);
            }
        },

        showOrders(type) {
            const ids = type === 'with' ? this.withEventIds : this.withoutEventIds;
            const title = type === 'with' ? 'Orders With Event' : 'Orders Without Event';
            
            if (ids.length === 0) {
                return;
            }
            
            $('#zs-stats-orders-title').text(`${title} (${ids.length})`);
            
            const $list = $('#zs-stats-orders-list');
            $list.empty();
            
            const editUrl = window.location.origin + '/wp-admin/post.php?post=ORDER_ID&action=edit';
            
            ids.forEach(orderId => {
                const url = editUrl.replace('ORDER_ID', orderId);
                $list.append(
                    `<div style="padding: 8px; border-bottom: 1px solid #f0f0f1;">
                        <a href="${url}" target="_blank" style="text-decoration: none; color: #2271b1; font-weight: 500;">
                            Order #${orderId} →
                        </a>
                    </div>`
                );
            });
            
            $('#zs-stats-orders').slideDown();
        },

        hideOrders() {
            $('#zs-stats-orders').slideUp();
        }
    };

    // Select All / Unselect All functionality
    function initSelectAllButtons() {
        console.log('initSelectAllButtons called');
        console.log('Found .zs-select-all elements:', $('.zs-select-all').length);
        console.log('Found .zs-unselect-all elements:', $('.zs-unselect-all').length);
        
        // Use event delegation to handle dynamically added elements
        $(document).on('click', '.zs-select-all', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const target = $(this).data('target');
            console.log('Select All clicked for:', target);
            const checkboxes = $(`input[name="${target}"]`);
            console.log('Found checkboxes:', checkboxes.length);
            checkboxes.prop('checked', true);
            return false;
        });

        $(document).on('click', '.zs-unselect-all', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const target = $(this).data('target');
            console.log('Unselect All clicked for:', target);
            const checkboxes = $(`input[name="${target}"]`);
            console.log('Found checkboxes:', checkboxes.length);
            checkboxes.prop('checked', false);
            return false;
        });
        
        console.log('Event listeners attached');
    }

    // Initialize on document ready
    $(document).ready(function() {
        console.log('Document ready - bulk-sync.js');
        statsUI.init();
        createUI.init();
        cleanupUI.init();
        deleteUI.init();
        initSelectAllButtons();
    });

})(jQuery);
