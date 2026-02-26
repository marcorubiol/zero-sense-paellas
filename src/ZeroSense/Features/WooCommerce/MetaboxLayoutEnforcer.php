<?php

declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\Logger;

class MetaboxLayoutEnforcer implements FeatureInterface
{
    public function getName(): string
    {
        return __('Metabox Layout Enforcer', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Forces preferred metabox layout for all users on first order page visit', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        add_action('current_screen', [$this, 'forceMetaboxLayoutOnce']);
    }

    public function forceMetaboxLayoutOnce($screen): void
    {
        if (!in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        $user_id  = get_current_user_id();
        $meta_key = 'zs_metabox_layout_forced_' . $user_id;

        if (get_user_meta($user_id, $meta_key, true)) {
            return;
        }

        $current_layout = get_user_meta($user_id, 'meta-box-order_shop_order', true);

        if (empty($current_layout) || $this->isDefaultLayout($current_layout)) {
            $this->applyPreferredLayout($user_id);
            $this->markAsForced($user_id);
            add_action('admin_notices', [$this, 'showLayoutNotice']);
        }
    }

    private function isDefaultLayout($layout): bool
    {
        if (empty($layout)) {
            return true;
        }

        $side   = $layout['side'] ?? '';
        $normal = $layout['normal'] ?? '';

        return strpos($side, 'woocommerce-order-actions') === false
            || strpos($normal, 'woocommerce-order-data') === false;
    }

    private function applyPreferredLayout(int $user_id): void
    {
        $preferred_layout = [
            'form_top'         => '',
            'before_permalink' => '',
            'after_title'      => '',
            'after_editor'     => '',
            'side'   => 'woocommerce-order-actions,zs_flowmattic_emails,sobre-el-evento,zs_deposits_logs,zs_email_logs,zs_order_status_logs,woocommerce-order-notes,woocommerce-order-source-data,iawp-wc-referrer-source,fluentcrm_woo_order_widget',
            'normal' => 'woocommerce-order-data,woocommerce-order-billing,woocommerce-order-shipping,zs_event_details,emails-content,intolerancias,woocommerce-order-items,zs_deposits_calculator,informacion-interna,woocommerce-order-downloads,zs_ops_notes,postcustom,zs_ops_material',
            'advanced'         => '',
        ];

        update_user_meta($user_id, 'meta-box-order_shop_order', $preferred_layout);
        Logger::info('MetaboxLayoutEnforcer: applied preferred layout for user ' . $user_id);
    }

    private function markAsForced(int $user_id): void
    {
        update_user_meta($user_id, 'zs_metabox_layout_forced_' . $user_id, time());
    }

    public function showLayoutNotice(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong>✅ <?php _e('Organización de paneles optimizada', 'zero-sense'); ?></strong><br>
                <?php _e('Los paneles del pedido han sido organizados automáticamente para mejorar tu flujo de trabajo. Puedes personalizarlos arrastrándolos si lo prefieres.', 'zero-sense'); ?>
            </p>
        </div>
        <?php
    }

    public static function resetUserLayout(int $user_id): void
    {
        delete_user_meta($user_id, 'meta-box-order_shop_order');
        delete_user_meta($user_id, 'zs_metabox_layout_forced_' . $user_id);
        Logger::info('MetaboxLayoutEnforcer: reset layout for user ' . $user_id);
    }

    public static function forceLayoutForUser(int $user_id): void
    {
        $instance = new self();
        $instance->applyPreferredLayout($user_id);
        $instance->markAsForced($user_id);
    }
}
