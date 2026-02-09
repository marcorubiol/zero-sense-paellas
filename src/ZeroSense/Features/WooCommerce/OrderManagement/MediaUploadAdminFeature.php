<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\OrderManagement\Components\MediaUploadAdmin;

if (!defined('ABSPATH')) { exit; }

class MediaUploadAdminFeature implements FeatureInterface
{
    public function getName(): string
    {
        return __('Orders: Media Upload Admin', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Media upload functionality for WooCommerce orders in admin backend. Allows admin to add/remove images and videos for events.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_admin_media_upload';
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            return;
        }

        new MediaUploadAdmin();
    }

    public function hasConfiguration(): bool
    {
        return false;
    }

    public function getConfigurationFields(): array
    {
        return [];
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'title' => __('Code Map', 'zero-sense'),
                'content' => '
                    <ul>
                        <li><code>src/ZeroSense/Features/WooCommerce/OrderManagement/Components/MediaUploadAdmin.php</code> - Main admin component</li>
                        <li><code>assets/js/media-upload-admin.js</code> - Frontend JavaScript</li>
                        <li><code>assets/css/media-upload-admin.css</code> - Admin styling</li>
                    </ul>
                '
            ],
            [
                'title' => __('Hooks & Filters', 'zero-sense'),
                'content' => '
                    <ul>
                        <li><code>add_meta_boxes</code> - Adds media upload metabox to shop order pages</li>
                        <li><code>woocommerce_process_shop_order_meta</code> - Saves media data</li>
                        <li><code>admin_enqueue_scripts</code> - Loads media uploader and assets</li>
                    </ul>
                '
            ],
            [
                'title' => __('Testing Notes', 'zero-sense'),
                'content' => '
                    <ol>
                        <li>Go to WooCommerce → Orders</li>
                        <li>Edit any order</li>
                        <li>Look for "Event Media" metabox</li>
                        <li>Click "Choose Files" to upload media</li>
                        <li>Verify files appear in preview and save correctly</li>
                        <li>Test remove functionality</li>
                        <li>Check that media is stored in <code>_zs_event_media</code> meta field</li>
                    </ol>
                '
            ]
        ];
    }
}
