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
    protected function getSchemaAdminPage(): AbstractSchemaAdminPage
    {
        $schema = SchemaRegistry::getInstance()->get('material');
        
        if ($schema === null) {
            throw new \RuntimeException('Material schema not registered');
        }
        
        return $schema;
    }
}
