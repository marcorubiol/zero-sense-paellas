<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaMetabox;
use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;
use ZeroSense\Features\WooCommerce\Schema\SchemaRegistry;

/**
 * Workspace & Access Metabox
 * 
 * Displays Workspace & Access fields on order edit screen.
 */
class WorkspaceMetabox extends AbstractSchemaMetabox
{
    protected function getSchemaAdminPage(): AbstractSchemaAdminPage
    {
        $schema = SchemaRegistry::getInstance()->get('workspace');
        
        if ($schema === null) {
            throw new \RuntimeException('Workspace schema not registered');
        }
        
        return $schema;
    }
}
