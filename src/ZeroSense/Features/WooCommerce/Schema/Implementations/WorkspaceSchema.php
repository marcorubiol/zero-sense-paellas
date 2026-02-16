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
    protected function getSchemaKey(): string
    {
        return 'workspace';
    }
    
    protected function getSchemaTitle(): string
    {
        return __('Workspace & Access Schema', 'zero-sense');
    }
    
    protected function getSchemaDescription(): string
    {
        return __('Configure the Workspace & Access fields available on WooCommerce orders. You can reorder fields by dragging them, hide fields you no longer need, or permanently delete hidden fields that have no data.', 'zero-sense');
    }
    
    protected function getOptionName(): string
    {
        return 'zs_ops_workspace_schema';
    }
    
    protected function getMetaKey(): string
    {
        return 'zs_ops_workspace';
    }
    
    protected function getMenuSlug(): string
    {
        return 'zs_ops_workspace_schema';
    }
    
    protected function getMenuTitle(): string
    {
        return __('Workspace & Access', 'zero-sense');
    }
}
