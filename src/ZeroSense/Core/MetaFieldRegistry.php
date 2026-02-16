<?php
declare(strict_types=1);

namespace ZeroSense\Core;

class MetaFieldRegistry
{
    private static ?self $instance = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $fields = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a meta field with metadata
     *
     * @param string $key The meta key (e.g., 'zs_event_total_guests')
     * @param array<string, mixed> $metadata Field metadata
     */
    public function register(string $key, array $metadata): void
    {
        if ($key === '') {
            return;
        }

        $this->fields[$key] = array_merge([
            'key' => $key,
            'label' => '',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => '',
            'computed' => false,
        ], $metadata);
    }

    /**
     * Get all registered meta keys
     *
     * @return array<int, string>
     */
    public function getAllKeys(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Get all translatable meta keys
     *
     * @return array<int, string>
     */
    public function getTranslatableKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $key => $metadata) {
            if (!empty($metadata['translatable'])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get metadata for a specific field
     *
     * @return array<string, mixed>|null
     */
    public function getFieldMetadata(string $key): ?array
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * Get legacy aliases for a field
     *
     * @return array<int, string>
     */
    public function getLegacyAliases(string $key): array
    {
        $metadata = $this->getFieldMetadata($key);
        if ($metadata === null) {
            return [];
        }

        $aliases = $metadata['legacy_keys'] ?? [];
        return is_array($aliases) ? $aliases : [];
    }

    /**
     * Get all fields grouped by feature
     *
     * @return array<string, array<int, string>>
     */
    public function getFieldsByFeature(): array
    {
        $grouped = [];
        foreach ($this->fields as $key => $metadata) {
            $feature = $metadata['feature'] ?? 'Unknown';
            if (!isset($grouped[$feature])) {
                $grouped[$feature] = [];
            }
            $grouped[$feature][] = $key;
        }

        return $grouped;
    }

    /**
     * Get field key by legacy alias
     */
    public function getKeyByLegacyAlias(string $legacyKey): ?string
    {
        foreach ($this->fields as $key => $metadata) {
            $aliases = $metadata['legacy_keys'] ?? [];
            if (is_array($aliases) && in_array($legacyKey, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Get all fields with their metadata
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllFields(): array
    {
        return $this->fields;
    }

    /**
     * Check if a key is registered
     */
    public function isRegistered(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * Clear all registered fields (for testing)
     */
    public function clear(): void
    {
        $this->fields = [];
    }
}
