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
    private ?AbstractSchemaAdminPage $schema = null;
    
    protected function getSchemaAdminPage(): AbstractSchemaAdminPage
    {
        if ($this->schema === null) {
            $this->schema = SchemaRegistry::getInstance()->get('workspace');
            
            if ($this->schema === null) {
                // If still not registered, create it directly
                $this->schema = new WorkspaceSchema();
                $this->schema->init();
            }
        }
        
        return $this->schema;
    }
}
