jQuery(document).ready(function($) {
    let mediaUploader;
    let mediaItems = [];

    // Lightbox
    function initLightbox() {
        if ($('#zs-lightbox').length) return;
        $('body').append(
            '<div id="zs-lightbox" class="zs-lightbox">' +
                '<div class="zs-lightbox-overlay"></div>' +
                '<div class="zs-lightbox-content">' +
                    '<button type="button" class="zs-lightbox-close">&times;</button>' +
                    '<div class="zs-lightbox-body"></div>' +
                '</div>' +
            '</div>'
        );
    }

    function openLightbox(url, type) {
        initLightbox();
        var $body = $('#zs-lightbox .zs-lightbox-body');
        $body.empty();
        if (type === 'video') {
            $body.html('<video src="' + url + '" controls autoplay style="max-width:100%;max-height:80vh;"></video>');
        } else {
            $body.html('<img src="' + url + '" style="max-width:100%;max-height:80vh;">');
        }
        $('#zs-lightbox').addClass('active');
    }

    $(document).on('click', '.zs-lightbox-close, .zs-lightbox-overlay', function() {
        var $lb = $('#zs-lightbox');
        $lb.find('video').each(function() { this.pause(); });
        $lb.removeClass('active');
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            var $lb = $('#zs-lightbox');
            $lb.find('video').each(function() { this.pause(); });
            $lb.removeClass('active');
        }
    });

    $(document).on('click', '.zs-lightbox-trigger', function(e) {
        e.preventDefault();
        openLightbox($(this).attr('href'), $(this).data('type') || 'image');
    });

    // Media uploader
    function initializeExistingMedia() {
        var existingIds = $('#zs_event_media').val();
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
                if (att.filesizeInBytes && att.filesizeInBytes > 10 * 1024 * 1024) {
                    alert('File ' + att.filename + ' exceeds 10MB limit');
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
        var dataType = att.type || 'image';
        if (att.type === 'image') {
            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            thumb = '<img src="' + src + '" alt="' + (att.title || '') + '">';
        } else if (att.type === 'video') {
            thumb = '<video src="' + att.url + '"></video>';
        }

        var html = '<div class="zs-media-item" data-id="' + att.id + '">' +
            thumb +
            '<div class="media-actions">' +
                '<a href="' + att.url + '" class="button button-small zs-lightbox-trigger" data-type="' + dataType + '">View</a>' +
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

    initializeExistingMedia();
});
