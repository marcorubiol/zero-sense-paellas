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

                // If create operation, run cleanup first
                if (this.operation === 'create' && this.queue.length > 0) {
                    await this.runCleanup();
                }

                // Process queue
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

                if (!this.running) break;

                const orderId = this.queue[i];
                const delay = this.config.getDelay();

                try {
                    const response = await $.post(window.zsBulkSync.ajaxUrl, {
                        action: 'zs_bulk_process_one',
                        nonce: window.zsBulkSync.nonce,
                        order_id: orderId,
                        operation: this.operation
                    });

                    if (response.success) {
                        const action = response.data.action;
                        
                        if (action === 'created') {
                            this.stats.created++;
                            if (response.data.reserved) {
                                this.stats.reserved++;
                            }
                        } else if (action === 'deleted') {
                            this.stats.deleted++;
                        } else if (action === 'skipped') {
                            this.stats.skipped++;
                        }

                        this.config.onProgress(
                            i + 1,
                            this.queue.length,
                            response.data.message,
                            action,
                            this.stats
                        );
                    } else {
                        this.stats.errors++;
                        this.config.onProgress(
                            i + 1,
                            this.queue.length,
                            `Order #${orderId}: Error - ${response.data}`,
                            'error',
                            this.stats
                        );
                    }
                } catch (error) {
                    this.stats.errors++;
                    this.config.onProgress(
                        i + 1,
                        this.queue.length,
                        `Order #${orderId}: Error - ${error.message}`,
                        'error',
                        this.stats
                    );
                }

                this.processed = i + 1;

                // Delay before next order
                if (i < this.queue.length - 1) {
                    await this.delay(delay * 1000);
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
            $('#zs-create-start').hide();
            $('#zs-create-pause').show();
            $('#zs-create-cancel').show();
            $('#zs-create-delay').prop('disabled', true);
            $('.zs-bulk-progress').first().show();
            $('.zs-bulk-stats').first().show();
            $('#zs-create-log').show().empty();

            this.processor = new BulkProcessor('create', {
                getDelay: () => parseInt($('#zs-create-delay').val()),
                onStart: () => this.updateLog('Starting...', 'info'),
                onQueueReady: (total) => this.updateLog(`Found ${total} orders to process`, 'info'),
                onCleanupStart: (total) => this.updateLog(`Cleaning up existing event IDs from ${total} orders...`, 'info'),
                onCleanupProgress: (current, total, message) => {
                    const percent = Math.round((current / total) * 100);
                    $('.zs-progress-fill').first().css('width', percent + '%');
                    $('.zs-progress-text').first().text(`Cleanup: ${percent}% (${current}/${total})`);
                },
                onCleanupComplete: (cleaned) => {
                    if (cleaned > 0) {
                        this.updateLog(`✓ Cleaned ${cleaned} existing event IDs`, 'success');
                    }
                },
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

            this.processor.start();
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

    // Initialize on document ready
    $(document).ready(function() {
        createUI.init();
        deleteUI.init();
    });

})(jQuery);
