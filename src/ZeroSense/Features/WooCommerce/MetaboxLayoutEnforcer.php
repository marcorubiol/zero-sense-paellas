<?php

declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

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
        return 5; // Early priority
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function isToggleable(): bool
    {
        return false; // Always on
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
        // Solo ejecutar en páginas de pedidos de WooCommerce
        if (!in_array($screen->id, ['shop-order', 'woocommerce_page_wc-orders'])) {
            return;
        }

        $user_id = get_current_user_id();
        $meta_key = 'zs_metabox_layout_forced_' . $user_id;

        // Si ya se forzó anteriormente, no hacer nada
        if (get_user_meta($user_id, $meta_key, true)) {
            return;
        }

        // Obtener layout actual del usuario
        $current_layout = get_user_meta($user_id, 'meta-box-order_shop_order', true);

        // Si el usuario no tiene configuración personalizada, aplicar la preferida
        if (empty($current_layout) || $this->isDefaultLayout($current_layout)) {
            $this->applyPreferredLayout($user_id);
            $this->markAsForced($user_id);
            
            // Mostrar notificación una sola vez
            add_action('admin_notices', [$this, 'showLayoutNotice']);
        }
    }

    /**
     * Verifica si el layout actual es el predeterminado de WooCommerce
     */
    private function isDefaultLayout($layout): bool
    {
        // Si está vacío, es predeterminado
        if (empty($layout)) {
            return true;
        }

        // Verificar si tiene los metaboxes en orden por defecto
        $side_metaboxes = $layout['side'] ?? '';
        $normal_metaboxes = $layout['normal'] ?? '';
        
        $default_indicators = [
            strpos($side_metaboxes, 'woocommerce-order-actions') !== false,
            strpos($side_metaboxes, 'submitdiv') !== false,
            strpos($normal_metaboxes, 'woocommerce-order-data') !== false
        ];

        return in_array(false, $default_indicators, true);
    }

    /**
     * Aplica la configuración preferida basada en tu layout actual
     */
    private function applyPreferredLayout(int $user_id): void
    {
        // Tu configuración preferida exacta
        $preferred_layout = [
            'form_top' => '',
            'before_permalink' => '',
            'after_title' => '',
            'after_editor' => '',
            'side' => 'woocommerce-order-actions,zs_flowmattic_emails,sobre-el-evento,zs_deposits_logs,zs_email_logs,zs_order_status_logs,woocommerce-order-notes,woocommerce-order-source-data,iawp-wc-referrer-source,fluentcrm_woo_order_widget',
            'normal' => 'woocommerce-order-data,zs_event_details,emails-content,intolerancias,woocommerce-order-items,zs_deposits_calculator,informacion-interna,woocommerce-order-downloads,zs_ops_notes,postcustom,zs_ops_material',
            'advanced' => ''
        ];

        update_user_meta($user_id, 'meta-box-order_shop_order', $preferred_layout);
    }

    /**
     * Marca que ya se aplicó la configuración para este usuario
     */
    private function markAsForced(int $user_id): void
    {
        update_user_meta($user_id, 'zs_metabox_layout_forced_' . $user_id, time());
    }

    /**
     * Muestra una notificación informativa
     */
    public function showLayoutNotice(): void
    {
        $screen = get_current_screen();
        if (!in_array($screen->id, ['shop-order', 'woocommerce_page_wc-orders'])) {
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

    /**
     * Método público para resetear el layout de un usuario (útil para testing)
     */
    public static function resetUserLayout(int $user_id): void
    {
        delete_user_meta($user_id, 'meta-box-order_shop_order');
        delete_user_meta($user_id, 'zs_metabox_layout_forced_' . $user_id);
    }

    /**
     * Método público para forzar reaplicación del layout
     */
    public static function forceLayoutForUser(int $user_id): void
    {
        $instance = new self();
        $instance->applyPreferredLayout($user_id);
        $instance->markAsForced($user_id);
    }
}
