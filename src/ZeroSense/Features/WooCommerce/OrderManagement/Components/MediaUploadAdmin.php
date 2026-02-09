<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement\Components;

use WC_Order;

class MediaUploadAdmin
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('add_meta_boxes', [$this, 'add_media_upload_metabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_media_field'], 20);
    }

    public function enqueue_admin_scripts()
    {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
            wp_enqueue_media();
            wp_enqueue_script('zs-media-upload-admin', ZERO_SENSE_URL . 'assets/js/media-upload-admin.js', ['jquery'], ZERO_SENSE_VERSION, true);
            wp_enqueue_style('zs-media-upload-admin', ZERO_SENSE_URL . 'assets/css/media-upload-admin.css', [], ZERO_SENSE_VERSION);
        }
    }

    public function add_media_upload_metabox()
    {
        $screen = wc_get_page_screen_id('shop-order');
        
        add_meta_box(
            'zs_event_media_upload',
            __('Event Media', 'zero-sense'),
            [$this, 'render_media_upload_metabox'],
            $screen,
            'normal',
            'high'
        );
    }

    public function render_media_upload_metabox($postOrOrder)
    {
        $order = $postOrOrder instanceof \WP_Post 
            ? wc_get_order($postOrOrder->ID) 
            : $postOrOrder;
            
        if (!$order instanceof WC_Order) {
            return;
        }

        // Get existing media
        $media_ids = $order->get_meta('_zs_event_media', true);
        $existing_media = [];
        if ($media_ids) {
            $ids = explode(',', $media_ids);
            foreach ($ids as $id) {
                $id = trim($id);
                if (empty($id)) continue;
                
                $attachment = get_post($id);
                if ($attachment && $attachment->post_type === 'attachment') {
                    $existing_media[] = [
                        'id' => $id,
                        'url' => wp_get_attachment_url($id),
                        'type' => get_post_mime_type($id),
                        'title' => get_the_title($id)
                    ];
                }
            }
        }

        wp_nonce_field('zs_media_upload_save', 'zs_media_upload_nonce');
        ?>
        <div class="zs-media-upload-admin-wrapper">
            <div class="zs-media-existing">
                <h4><?php esc_html_e('Current Media Files', 'zero-sense'); ?></h4>
                <p class="zs-empty-msg" <?php if (!empty($existing_media)) echo 'style="display:none;"'; ?>><?php esc_html_e('No media files uploaded yet.', 'zero-sense'); ?></p>
                <div class="zs-media-grid" <?php if (empty($existing_media)) echo 'style="display:none;"'; ?>>
                    <?php foreach ($existing_media as $media): ?>
                        <div class="zs-media-item" data-id="<?php echo esc_attr($media['id']); ?>">
                            <?php if (strpos($media['type'], 'image') !== false): ?>
                                <img src="<?php echo esc_url($media['url']); ?>" alt="<?php echo esc_attr($media['title']); ?>">
                            <?php elseif (strpos($media['type'], 'video') !== false): ?>
                                <video src="<?php echo esc_url($media['url']); ?>"></video>
                            <?php endif; ?>
                            <div class="media-actions">
                                <a href="<?php echo esc_url($media['url']); ?>" target="_blank" class="button button-small"><?php esc_html_e('View', 'zero-sense'); ?></a>
                                <button type="button" class="button button-small remove-media"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="zs-media-upload-section">
                <div class="zs-media-upload-area">
                    <button type="button" class="button button-primary zs-media-upload-btn" id="zs-media-upload-btn">
                        <?php esc_html_e('Choose Files', 'zero-sense'); ?>
                    </button>
                    <span class="description">
                        <?php esc_html_e('JPG, PNG, GIF, MP4, MOV — Max 10MB per file', 'zero-sense'); ?>
                    </span>
                </div>
                <input type="hidden" id="zs_event_media" name="zs_event_media" value="<?php echo esc_attr($media_ids); ?>">
            </div>
        </div>
        <?php
    }

    public function save_media_field($orderId)
    {
        // Verify nonce
        if (!isset($_POST['zs_media_upload_nonce']) || 
            !wp_verify_nonce($_POST['zs_media_upload_nonce'], 'zs_media_upload_save')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['zs_event_media'])) {
            $media_ids = sanitize_text_field($_POST['zs_event_media']);
            
            // Validate each media ID
            if (!empty($media_ids)) {
                $ids = explode(',', $media_ids);
                $valid_ids = [];
                
                foreach ($ids as $id) {
                    $id = trim($id);
                    if (empty($id)) continue;
                    
                    $attachment = get_post($id);
                    if ($attachment && $attachment->post_type === 'attachment') {
                        $valid_ids[] = $id;
                    }
                }
                
                if (!empty($valid_ids)) {
                    $order->update_meta_data('_zs_event_media', implode(',', $valid_ids));
                } else {
                    $order->delete_meta_data('_zs_event_media');
                }
            } else {
                $order->delete_meta_data('_zs_event_media');
            }
        }

        $order->save();
    }
}
