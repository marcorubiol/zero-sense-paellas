jQuery(document).ready(function($) {
    let mediaUploader;
    let mediaItems = [];
    
    // Initialize existing media items
    function initializeExistingMedia() {
        let existingIds = $('#zs_event_media').val();
        if (existingIds) {
            let ids = existingIds.split(',');
            $.each(ids, function(index, id) {
                id = $.trim(id);
                if (id) {
                    mediaItems.push(id);
                }
            });
        }
    }
    
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
                if (attachment.filesize && attachment.filesizeByte > 10 * 1024 * 1024) {
                    alert('File ' + attachment.filename + ' exceeds 10MB limit');
                    return;
                }
                
                // Check if already exists
                let exists = mediaItems.some(function(item) {
                    return item === attachment.id.toString();
                });
                
                if (!exists) {
                    // Add to list
                    mediaItems.push(attachment.id.toString());
                }
            });
            
            updateHiddenField();
        });
        
        mediaUploader.open();
    });
    
    function updateHiddenField() {
        $('#zs_event_media').val(mediaItems.join(','));
    }
    
    // Remove media item
    $(document).on('click', '.remove-media', function(e) {
        e.preventDefault();
        let $item = $(this).closest('.zs-media-item');
        let id = $item.data('id');
        
        // Remove from array
        mediaItems = mediaItems.filter(function(item) {
            return item !== id.toString();
        });
        
        // Remove from DOM
        $item.fadeOut(300, function() {
            $(this).remove();
        });
        
        updateHiddenField();
    });
    
    // Initialize on page load
    initializeExistingMedia();
});
