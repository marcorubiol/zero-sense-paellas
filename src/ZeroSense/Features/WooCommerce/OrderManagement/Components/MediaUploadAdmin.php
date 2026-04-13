<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement\Components;

use WC_Order;

class MediaUploadAdmin
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        // Run after HappyFiles' has_media_modal() + enqueue_scripts() (both priority 10)
        // to override the wc-orders exclusion and manually trigger asset loading.
        add_action('admin_enqueue_scripts', [$this, 'enable_happyfiles_on_orders'], 11);
        add_action('add_meta_boxes', [$this, 'add_media_upload_metabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_media_field'], 20);
    }

    /**
     * Allow HappyFiles inside the media modal on WooCommerce order screens.
     *
     * HappyFiles explicitly skips wc-orders (setup.php:120-122). We run at
     * priority 11, after both has_media_modal() and enqueue_scripts() (priority 10),
     * then enable the flag and trigger asset loading manually.
     */
    public function enable_happyfiles_on_orders()
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        if (!class_exists(\HappyFiles\Init::class) || !wp_script_is('media-editor', 'registered')) {
            return;
        }

        // Force HappyFiles to load as if we're on the media library (attachment),
        // not the current post type (shop_order). Otherwise it shows order folders
        // instead of media folders in the modal.
        \HappyFiles\Data::$post_type = 'attachment';
        \HappyFiles\Data::$taxonomy  = HAPPYFILES_TAXONOMY;
        \HappyFiles\Data::$load_plugin = true;
        \HappyFiles\Data::get_folders();

        $hf = \HappyFiles\Init::$instance;
        if ($hf && isset($hf->setup)) {
            $hf->setup->enqueue_scripts();
        }

        // HappyFiles Vue checks body classes to decide page-mode vs modal-mode.
        // It treats woocommerce_page_wc-orders as a page (sidebar in #wpbody).
        // Remove the class before Vue mounts so it enters modal-mode instead,
        // then restore it after HappyFiles JS has initialised.
        wp_add_inline_script('happyfiles', implode('', [
            'document.body.classList.remove("woocommerce_page_wc-orders");',
            'document.addEventListener("DOMContentLoaded",function(){',
            'setTimeout(function(){document.body.classList.add("woocommerce_page_wc-orders");},0);',
            '});',
        ]), 'before');
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
                    $thumb = wp_get_attachment_image_url((int) $id, 'medium');
                    $fullUrl = wp_get_attachment_url($id);
                    $existing_media[] = [
                        'id' => $id,
                        'url' => $fullUrl,
                        'thumb' => $thumb ?: $fullUrl,
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
                                <img src="<?php echo esc_url($media['thumb']); ?>" alt="<?php echo esc_attr($media['title']); ?>" data-full="<?php echo esc_url($media['url']); ?>">
                            <?php elseif (strpos($media['type'], 'video') !== false): ?>
                                <video src="<?php echo esc_url($media['url']); ?>" data-full="<?php echo esc_url($media['url']); ?>"></video>
                            <?php endif; ?>
                            <div class="zs-media-item-title"><?php echo esc_html($media['title']); ?></div>
                            <div class="media-actions">
                                <button type="button" class="button button-small zs-media-view" data-url="<?php echo esc_url($media['url']); ?>" data-thumb="<?php echo esc_url($media['thumb']); ?>" data-type="<?php echo esc_attr(strpos($media['type'], 'video') !== false ? 'video' : 'image'); ?>" data-title="<?php echo esc_attr($media['title']); ?>"><?php esc_html_e('View', 'zero-sense'); ?></button>
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
                        <?php esc_html_e('JPG, PNG, GIF, WEBP, MP4, MOV — Max 20MB per file', 'zero-sense'); ?>
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
