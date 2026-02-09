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
        var mediaType = att.type || 'image';
        if (mediaType === 'image') {
            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            thumb = '<img src="' + src + '" alt="' + (att.title || '') + '" data-full="' + att.url + '">';
        } else if (mediaType === 'video') {
            thumb = '<video src="' + att.url + '" data-full="' + att.url + '"></video>';
        }

        var html = '<div class="zs-media-item" data-id="' + att.id + '">' +
            thumb +
            '<div class="media-actions">' +
                '<button type="button" class="button button-small zs-media-view" data-url="' + att.url + '" data-type="' + mediaType + '">View</button>' +
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
        var url = $(this).data('url');
        var type = $(this).data('type');
        var content = '';

        if (type === 'video') {
            content = '<video src="' + url + '" controls autoplay style="max-width:90vw;max-height:80vh;"></video>';
        } else {
            content = '<img src="' + url + '" style="max-width:90vw;max-height:80vh;object-fit:contain;">';
        }

        var overlay = '<div id="zs-lightbox" style="position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;cursor:pointer;">' +
            '<span style="position:absolute;top:20px;right:30px;color:#fff;font-size:32px;cursor:pointer;line-height:1;">&times;</span>' +
            content +
        '</div>';

        $('body').append(overlay);
    });

    $(document).on('click', '#zs-lightbox', function() {
        $(this).remove();
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('#zs-lightbox').remove();
    });

    initializeExistingMedia();
});
