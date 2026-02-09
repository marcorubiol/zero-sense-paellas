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
        if (att.type === 'image') {
            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            thumb = '<img src="' + src + '" alt="' + (att.title || '') + '">';
        } else if (att.type === 'video') {
            thumb = '<video src="' + att.url + '"></video>';
        }

        var html = '<div class="zs-media-item" data-id="' + att.id + '">' +
            thumb +
            '<div class="media-actions">' +
                '<a href="' + att.url + '" target="_blank" class="button button-small">View</a>' +
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
