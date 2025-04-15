# Zero Sense

A minimal WordPress plugin for [Paellas en Casa](https://paellasencasa.com) website.

## Description

Zero Sense is a lightweight WordPress plugin that provides focused functionality for the Paellas en Casa website:

1. **WooCommerce Features**: 
   - Simple cart management with URL parameters
   - AJAX cart functionality for dynamic cart interactions
   - Automatic cart clearing after browser tabs are closed for a specific time
   - Custom text modifications for WooCommerce strings
2. **More to come**: This plugin is designed to be expanded with site-specific functionality

## Installation

1. Upload the `zero-sense` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings at Settings > Zero Sense

## Usage

### WooCommerce Empty Cart Link

Add a link with `?zs_empty_cart=1` to any URL to create an "Empty Cart" link.

Example:
```html
<a href="<?php echo add_query_arg('zs_empty_cart', '1', wc_get_cart_url()); ?>">Vaciar Carrito</a>
```

### AJAX Cart Functionality

The plugin provides AJAX cart functionality that can be used with custom buttons:

```html
<!-- Add to cart button -->
<button class="zs-add-to-cart" data-product-id="123" data-quantity="1">Add to Cart</button>

<!-- Remove from cart button -->
<button class="zs-remove-from-cart" data-cart-item-key="abc123">Remove</button>
```

### Automatic Cart Clearing

Carts are automatically cleared after:
- All browser tabs with the website have been closed
- The configured time period has elapsed (default: 5 minutes)

This helps prevent abandoned carts and keeps product inventory accurate.

You can configure this feature in the WordPress admin under **Settings > Zero Sense**.

### WooCommerce Text Modifications

The plugin modifies various WooCommerce text strings to make them more appropriate for your site:

- Checkout field labels
- Button text
- Various cart and checkout messages

These modifications work in English, Spanish, and Catalan.

## Admin Settings

Go to **Settings > Zero Sense** to configure:

- **Enable Cart Timeout** - Turn the automatic cart clearing on/off
- **Cart Timeout (minutes)** - Set how long after closing all tabs before cart is cleared

## Changelog

### 1.0.0
* Initial release
* Added WooCommerce empty cart functionality
* Added AJAX cart operations
* Added automatic cart clearing after tab close detection
* Added admin settings for cart timeout configuration
* Added WooCommerce text modifications

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Created for [Paellas en Casa](https://paellasencasa.com) by [zero sense](https://zerosense.blue)
