jQuery(document).ready(function($) {
    let mediaUploader;
    let mediaItems = [];
    
    $('#zs-media-upload-btn').on('click', function(e) {
        e.preventDefault();
        
        // If uploader already exists, open it
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Media Files',
            button: {
                text: 'Select Files'
            },
            multiple: true, // Allow multiple files
            library: {
                type: ['image', 'video'] // Only images and videos
            }
        });
        
        // When files are selected
        mediaUploader.on('select', function() {
            let attachments = mediaUploader.state().get('selection').toJSON();
            
            attachments.forEach(function(attachment) {
                // Validate file size
                if (attachment.filesize && attachment.filesizeByte > 20 * 1024 * 1024) {
                    alert('File ' + attachment.filename + ' exceeds 20MB limit');
                    return;
                }
                
                // Check if already exists
                let exists = mediaItems.some(function(item) {
                    return item.id === attachment.id;
                });
                
                if (!exists) {
                    // Add to list
                    mediaItems.push({
                        id: attachment.id,
                        url: attachment.url,
                        type: attachment.type,
                        size: attachment.filesizeByte || 0,
                        filename: attachment.filename || ''
                    });
                }
            });
            
            updatePreview();
            updateHiddenField();
        });
        
        mediaUploader.open();
    });
    
    function updatePreview() {
        let preview = $('#zs-media-preview');
        preview.empty();
        
        if (mediaItems.length === 0) {
            preview.hide();
            return;
        }
        
        preview.show();
        
        mediaItems.forEach(function(item, index) {
            let mediaHtml = '';
            
            if (item.type === 'image') {
                mediaHtml = '<img src="' + item.url + '" alt="Media ' + (index + 1) + '">';
            } else if (item.type === 'video') {
                mediaHtml = '<video src="' + item.url + '" controls></video>';
            } else {
                mediaHtml = '<div class="media-placeholder">Unsupported format</div>';
            }
            
            let itemHtml = '<div class="zs-media-item" title="' + (item.filename || 'Media ' + (index + 1)) + '">' +
                mediaHtml +
                '<button type="button" class="remove-btn" data-index="' + index + '" title="Remove">×</button>' +
                '</div>';
            
            preview.append(itemHtml);
        });
    }
    
    function updateHiddenField() {
        $('#zs_event_media').val(JSON.stringify(mediaItems));
    }
    
    // Remove media item
    $(document).on('click', '.zs-media-item .remove-btn', function(e) {
        e.preventDefault();
        let index = $(this).data('index');
        mediaItems.splice(index, 1);
        updatePreview();
        updateHiddenField();
    });
    
    // Initialize preview on page load
    updatePreview();
});
