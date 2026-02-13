<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Support;

class MetaKeys
{
    /** Product-level flag: does this product offer the rabbit choice? */
    public const PRODUCT_HAS_RABBIT_OPTION = '_zs_has_rabbit_option';

    /** Order-item-level: customer's choice ('with' or 'without') */
    public const RABBIT_CHOICE = '_zs_rabbit_choice';

    /** Cart item data key */
    public const CART_KEY = 'zs_rabbit_choice';
}
