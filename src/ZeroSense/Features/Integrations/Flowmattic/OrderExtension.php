<?php
namespace ZeroSense\Features\Integrations\Flowmattic;

class OrderExtension
{
    public static function init(): void
    {
        self::extendWooCommerceOrder();

        if (!did_action('woocommerce_loaded')) {
            add_action('woocommerce_loaded', [self::class, 'extendWooCommerceOrder']);
        }
    }

    public static function extendWooCommerceOrder(): void
    {
        add_filter('woocommerce_order_class', [self::class, 'maybeExtendOrderClass'], 10, 3);
    }

    public static function maybeExtendOrderClass(string $classname, string $orderType, int $orderId): string
    {
        if ($orderType === 'shop_order' && class_exists('WC_Order')) {
            return ExtendedOrder::class;
        }

        return $classname;
    }
}

class ExtendedOrder extends \WC_Order
{
    public function get_fecha_del_evento($context = 'view')
    {
        return $this->get_meta('fecha_del_evento', true, $context);
    }

    public function set_fecha_del_evento($value): self
    {
        $this->update_meta_data('fecha_del_evento', $value);

        return $this;
    }

    public function get_wpml_language($context = 'view')
    {
        return $this->get_meta('wpml_language', true, $context);
    }

    public function set_wpml_language($value): self
    {
        $this->update_meta_data('wpml_language', $value);

        return $this;
    }
}
