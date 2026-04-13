<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;

class MediaUploadField
{
    public function __construct()
    {
        add_action('woocommerce_after_checkout_billing_form', [$this, 'add_media_upload_field']);
        add_action('woocommerce_checkout_process', [$this, 'validate_media_field']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_media_field'], 20, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_media_scripts']);
    }

    public function enqueue_media_scripts()
    {
        if (is_checkout()) {
            wp_enqueue_media(); // Load WordPress media uploader
            wp_enqueue_script('zs-media-upload', ZERO_SENSE_URL . 'assets/js/media-upload.js', ['jquery'], ZERO_SENSE_VERSION, true);
        }
    }

    public function add_media_upload_field($checkout)
    {
        ?>
        <div class="zs-media-upload-wrapper">
            <h3><?php esc_html_e('Event Media', 'zero-sense'); ?></h3>
            <p><?php esc_html_e('Add images or videos for your event (optional)', 'zero-sense'); ?></p>
            
            <div class="zs-media-field-group">
                <label for="zs_event_media">
                    <?php esc_html_e('Upload Media', 'zero-sense'); ?>
                </label>
                
                <div class="zs-media-upload-area">
                    <button type="button" class="button zs-media-upload-btn" id="zs-media-upload-btn">
                        <?php esc_html_e('Choose Files', 'zero-sense'); ?>
                    </button>
                    <div class="zs-media-preview" id="zs-media-preview"></div>
                    <input type="hidden" id="zs_event_media" name="zs_event_media" value="">
                </div>
                
                <p class="description">
                    <?php esc_html_e('Supported formats: JPG, PNG, GIF, WEBP, MP4, MOV. Max file size: 20MB per file.', 'zero-sense'); ?>
                </p>
            </div>
        </div>
        
        <style>
        .zs-media-upload-wrapper {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .zs-media-upload-area {
            border: 2px dashed #ccc;
            padding: 20px;
            text-align: center;
            margin: 10px 0;
            transition: border-color 0.3s ease;
        }
        .zs-media-upload-area:hover {
            border-color: #0073aa;
        }
        .zs-media-preview {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }
        .zs-media-item {
            position: relative;
            width: 100px;
            height: 100px;
            border: 1px solid #ddd;
            border-radius: 3px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .zs-media-item img,
        .zs-media-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .zs-media-item .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 12px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .zs-media-item .remove-btn:hover {
            background: #c82333;
        }
        .zs-media-upload-btn {
            background: #0073aa;
            color: white;
            border: 1px solid #0073aa;
            padding: 10px 20px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
        }
        .zs-media-upload-btn:hover {
            background: #005a87;
        }
        </style>
        <?php
    }

    public function validate_media_field()
    {
        if (isset($_POST['zs_event_media'])) {
            $media_data = json_decode(stripslashes($_POST['zs_event_media']), true);
            
            if ($media_data && is_array($media_data)) {
                foreach ($media_data as $media) {
                    // Validate maximum file size (20MB)
                    if (isset($media['size']) && $media['size'] > 20 * 1024 * 1024) {
                        wc_add_notice(__('File size exceeds 20MB limit.', 'zero-sense'), 'error');
                        return;
                    }
                    
                    // Validate file type
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime'];
                    if (isset($media['type']) && !in_array($media['type'], $allowed_types)) {
                        wc_add_notice(__('File type not allowed. Supported formats: JPG, PNG, GIF, WEBP, MP4, MOV.', 'zero-sense'), 'error');
                        return;
                    }
                    
                    // Validate attachment ID
                    if (!isset($media['id']) || !is_numeric($media['id'])) {
                        wc_add_notice(__('Invalid media file detected.', 'zero-sense'), 'error');
                        return;
                    }
                    
                    // Verify attachment exists and belongs to current user (or is public)
                    $attachment = get_post(absint($media['id']));
                    if (!$attachment || $attachment->post_type !== 'attachment') {
                        wc_add_notice(__('Invalid media attachment.', 'zero-sense'), 'error');
                        return;
                    }
                }
            }
        }
    }

    public function save_media_field(WC_Order $order, $data): void
    {
        if (isset($_POST['zs_event_media'])) {
            $media_data = json_decode(stripslashes($_POST['zs_event_media']), true);
            
            if ($media_data && is_array($media_data)) {
                // Sanitize and save only attachment IDs
                $attachment_ids = [];
                foreach ($media_data as $media) {
                    if (isset($media['id']) && is_numeric($media['id'])) {
                        $attachment_id = absint($media['id']);
                        
                        // Double-check attachment exists
                        $attachment = get_post($attachment_id);
                        if ($attachment && $attachment->post_type === 'attachment') {
                            $attachment_ids[] = $attachment_id;
                        }
                    }
                }
                
                if (!empty($attachment_ids)) {
                    $order->update_meta_data('_zs_event_media', implode(',', $attachment_ids));
                }
            }
        }
    }
}
