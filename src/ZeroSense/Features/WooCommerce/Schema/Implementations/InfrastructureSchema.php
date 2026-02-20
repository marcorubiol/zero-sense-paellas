<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;

/**
 * Complementary Infrastructure Schema
 * 
 * Manages Complementary Infrastructure fields for WooCommerce orders.
 */
class InfrastructureSchema extends AbstractSchemaAdminPage
{
    public function getSchemaKey(): string
    {
        return 'infrastructure';
    }
    
    public function getSchemaTitle(): string
    {
        return __('Complementary Infrastructure Schema', 'zero-sense');
    }
    
    public function getSchemaDescription(): string
    {
        return __('Configure the Complementary Infrastructure fields available on WooCommerce orders. You can reorder fields by dragging them, hide fields you no longer need, or permanently delete hidden fields that have no data.', 'zero-sense');
    }
    
    public function getOptionName(): string
    {
        return 'zs_ops_infrastructure_schema';
    }
    
    public function getMetaKey(): string
    {
        return 'zs_ops_infrastructure';
    }
    
    public function getMenuSlug(): string
    {
        return 'zs_ops_infrastructure_schema';
    }
    
    public function getMenuTitle(): string
    {
        return __('Complementary Infrastructure', 'zero-sense');
    }
    
    public function getPriority(): int
    {
        return 20;
    }
}
