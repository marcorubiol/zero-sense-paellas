<?php
namespace ZeroSense\Features\WooCommerce\Schema\Implementations;

use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaMetabox;
use ZeroSense\Features\WooCommerce\Schema\AbstractSchemaAdminPage;
use ZeroSense\Features\WooCommerce\Schema\SchemaRegistry;

/**
 * Complementary Infrastructure Metabox
 * 
 * Displays Complementary Infrastructure fields on order edit screen.
 */
class InfrastructureMetabox extends AbstractSchemaMetabox
{
    private ?AbstractSchemaAdminPage $schema = null;
    
    protected function getSchemaAdminPage(): AbstractSchemaAdminPage
    {
        if ($this->schema === null) {
            $this->schema = SchemaRegistry::getInstance()->get('infrastructure');
            
            if ($this->schema === null) {
                // If still not registered, create it directly
                $this->schema = new InfrastructureSchema();
                $this->schema->init();
            }
        }
        
        return $this->schema;
    }
}
