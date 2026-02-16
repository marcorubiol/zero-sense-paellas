<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaMetabox;
use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;
use ZeroSense\Features\WooCommerce\Schema\SchemaRegistry;

/**
 * Material & Logistics Metabox
 * 
 * Displays Material & Logistics fields on order edit screen.
 */
class MaterialMetabox extends AbstractSchemaMetabox
{
    private ?AbstractSchemaAdminPage $schema = null;
    
    protected function getSchemaAdminPage(): AbstractSchemaAdminPage
    {
        if ($this->schema === null) {
            $this->schema = SchemaRegistry::getInstance()->get('material');
            
            if ($this->schema === null) {
                // If still not registered, create it directly
                $this->schema = new MaterialSchema();
                $this->schema->init();
            }
        }
        
        return $this->schema;
    }
}
