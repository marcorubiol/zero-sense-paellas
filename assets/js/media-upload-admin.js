jQuery(document).ready(function($) {
    let mediaUploader;
    let mediaItems = [];

    function initializeExistingMedia() {
        let existingIds = $('#zs_event_media').val();
        if (existingIds) {
            existingIds.split(',').forEach(function(id) {
                id = $.trim(id);
                if (id) mediaItems.push(id);
            });
        }
    }

    $('#zs-media-upload-btn').on('click', function(e) {
        e.preventDefault();

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title: 'Choose Media Files',
            button: { text: 'Select Files' },
            multiple: 'add',
            library: { type: ['image', 'video'] }
        });

        mediaUploader.on('select', function() {
            var attachments = mediaUploader.state().get('selection').toJSON();

            attachments.forEach(function(att) {
                if (att.filesizeInBytes && att.filesizeInBytes > 20 * 1024 * 1024) {
                    alert('File ' + att.filename + ' exceeds 20MB limit');
                    return;
                }
                var id = att.id.toString();
                if (mediaItems.indexOf(id) === -1) {
                    mediaItems.push(id);
                    appendPreviewItem(att);
                }
            });

            updateHiddenField();
            toggleEmptyMessage();
        });

        mediaUploader.open();
    });

    function appendPreviewItem(att) {
        var thumb = '';
        var mediaType = att.type || 'image';
        var title = att.title || att.filename || '';
        if (mediaType === 'image') {
            var src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : ((att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url);
            thumb = '<img src="' + src + '" alt="' + title + '" data-full="' + att.url + '">';
        } else if (mediaType === 'video') {
            thumb = '<video src="' + att.url + '" data-full="' + att.url + '"></video>';
        }

        var html = '<div class="zs-media-item" data-id="' + att.id + '">' +
            thumb +
            '<div class="zs-media-item-title">' + $('<span>').text(title).html() + '</div>' +
            '<div class="media-actions">' +
                '<button type="button" class="button button-small zs-media-view" data-url="' + att.url + '" data-type="' + mediaType + '" data-title="' + $('<span>').text(title).html() + '">View</button>' +
                '<button type="button" class="button button-small remove-media">Remove</button>' +
            '</div>' +
        '</div>';

        $('.zs-media-grid').append(html);
    }

    function updateHiddenField() {
        $('#zs_event_media').val(mediaItems.join(','));
    }

    function toggleEmptyMessage() {
        var $grid = $('.zs-media-grid');
        var $empty = $('.zs-media-existing .zs-empty-msg');
        if (mediaItems.length > 0) {
            if (!$grid.length) {
                $empty.after('<div class="zs-media-grid"></div>');
            }
            $empty.hide();
            $('.zs-media-grid').show();
        } else {
            $empty.show();
            $grid.hide();
        }
    }

    $(document).on('click', '.remove-media', function(e) {
        e.preventDefault();
        var $item = $(this).closest('.zs-media-item');
        var id = $item.data('id').toString();

        mediaItems = mediaItems.filter(function(item) {
            return item !== id;
        });

        $item.fadeOut(300, function() { $(this).remove(); });
        updateHiddenField();
        toggleEmptyMessage();
    });

    // Lightbox
    $(document).on('click', '.zs-media-view', function(e) {
        e.preventDefault();
        var $item = $(this).closest('.zs-media-item');
        var url = $(this).data('url');
        var type = $(this).data('type');
        var title = $item.find('.zs-media-item-title').text() || $(this).data('title') || '';

        // Fallback: use data-full from the img/video element
        if (!url) {
            var $media = $item.find('img[data-full], video[data-full]');
            if ($media.length) url = $media.data('full');
        }

        var content = '';
        if (type === 'video') {
            content = '<video src="' + url + '" controls autoplay style="display:block;max-width:80vw;max-height:70vh;"></video>';
        } else {
            content = '<img src="' + url + '" style="display:block;max-width:80vw;max-height:70vh;width:auto;height:auto;">';
        }

        var titleHtml = title ? '<div style="padding:10px 0 0;font-size:13px;color:#333;text-align:center;">' + $('<span>').text(title).html() + '</div>' : '';

        var overlay = '<div id="zs-lightbox" style="position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px;">' +
            '<div class="zs-lightbox-inner" style="background:#fff;border-radius:8px;padding:20px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.3);cursor:default;">' +
                '<span id="zs-lightbox-close" style="position:absolute;top:-12px;right:-12px;background:#333;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;line-height:1;box-shadow:0 2px 6px rgba(0,0,0,.3);z-index:10;">&times;</span>' +
                content +
                titleHtml +
            '</div>' +
        '</div>';

        $('body').append(overlay);
    });

    $(document).on('click', '#zs-lightbox-close', function(e) {
        e.stopPropagation();
        $('#zs-lightbox').remove();
    });

    $(document).on('click', '#zs-lightbox', function(e) {
        if ($(e.target).closest('.zs-lightbox-inner').length === 0) {
            $(this).remove();
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('#zs-lightbox').remove();
    });

    initializeExistingMedia();
});
