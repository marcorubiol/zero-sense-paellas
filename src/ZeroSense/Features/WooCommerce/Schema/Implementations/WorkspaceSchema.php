<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;

/**
 * Workspace & Access Schema
 * 
 * Manages Workspace & Access fields for WooCommerce orders.
 */
class WorkspaceSchema extends AbstractSchemaAdminPage
{
    public function getSchemaKey(): string
    {
        return 'workspace';
    }
    
    public function getSchemaTitle(): string
    {
        return __('Workspace & Access Schema', 'zero-sense');
    }
    
    public function getSchemaDescription(): string
    {
        return __('Configure the Workspace & Access fields available on WooCommerce orders. You can reorder fields by dragging them, hide fields you no longer need, or permanently delete hidden fields that have no data.', 'zero-sense');
    }
    
    public function getOptionName(): string
    {
        return 'zs_ops_workspace_schema';
    }
    
    public function getMetaKey(): string
    {
        return 'zs_ops_workspace';
    }
    
    public function getMenuSlug(): string
    {
        return 'zs_ops_workspace_schema';
    }
    
    public function getMenuTitle(): string
    {
        return __('Workspace & Access', 'zero-sense');
    }
}
