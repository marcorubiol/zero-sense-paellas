<?php
namespace ZeroSense\Features\WooCommerce\Schema;

/**
 * Schema Registry
 * 
 * Central registry for all dynamic schemas.
 * Allows schemas to be discovered by integrations (Bricks, FlowMattic, etc.)
 */
class SchemaRegistry
{
    private static ?SchemaRegistry $instance = null;
    
    /**
     * @var array<string, AbstractSchemaAdminPage>
     */
    private array $schemas = [];
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): SchemaRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
    }
    
    /**
     * Register a schema
     */
    public function register(AbstractSchemaAdminPage $schema): void
    {
        $key = $schema->getSchemaKey();
        $this->schemas[$key] = $schema;
    }
    
    /**
     * Get a schema by key
     */
    public function get(string $key): ?AbstractSchemaAdminPage
    {
        return $this->schemas[$key] ?? null;
    }
    
    /**
     * Get all registered schemas
     * 
     * @return array<string, AbstractSchemaAdminPage>
     */
    public function getAll(): array
    {
        return $this->schemas;
    }
    
    /**
     * Check if a schema exists
     */
    public function exists(string $key): bool
    {
        return isset($this->schemas[$key]);
    }
    
    /**
     * Get schema keys
     * 
     * @return array<int, string>
     */
    public function getKeys(): array
    {
        return array_keys($this->schemas);
    }
}
