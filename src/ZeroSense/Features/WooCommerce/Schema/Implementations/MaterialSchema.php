<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;

/**
 * Material & Logistics Schema
 * 
 * Manages Material & Logistics fields for WooCommerce orders.
 */
class MaterialSchema extends AbstractSchemaAdminPage
{
    public function getSchemaKey(): string
    {
        return 'material';
    }
    
    public function getSchemaTitle(): string
    {
        return __('Material & Logistics Schema', 'zero-sense');
    }
    
    public function getSchemaDescription(): string
    {
        return __('Configure the Material & Logistics fields available on WooCommerce orders. You can reorder fields by dragging them, hide fields you no longer need, or permanently delete hidden fields that have no data.', 'zero-sense');
    }
    
    public function getOptionName(): string
    {
        return 'zs_ops_material_schema';
    }
    
    public function getMetaKey(): string
    {
        return 'zs_ops_material';
    }
    
    public function getMenuSlug(): string
    {
        return 'zs_ops_material_schema';
    }
    
    public function getMenuTitle(): string
    {
        return __('Material & Logistics', 'zero-sense');
    }
}
