<?php
namespace ZeroSense\Core;

use ZeroSense\Core\Logger;

/**
 * Feature Manager
 * 
 * Auto-discovers and manages all features in the plugin.
 * Eliminates the need for manual feature configuration.
 */
class FeatureManager
{
    /**
     * Discovered features
     */
    private array $features = [];

    /**
     * Feature directories to scan
     */
    private array $featureDirectories = [
        'WordPress',
        'WooCommerce',
        'Operations',
        'Security', 
        'Utilities',
        'Integrations'
    ];

    /**
     * Auto-discover all features by scanning directories
     * Uses transient cache to avoid filesystem scans on every request
     */
    public function discoverFeatures(): void
    {
        // Try to get cached feature class names
        $cacheKey = 'zs_feature_classes_v' . ZERO_SENSE_VERSION;
        $cachedClasses = get_transient($cacheKey);
        
        if ($cachedClasses !== false && is_array($cachedClasses)) {
            // Load features from cache
            foreach ($cachedClasses as $className) {
                if (class_exists($className)) {
                    try {
                        $this->features[] = new $className();
                    } catch (\Exception $e) {
                        Logger::debug("Failed to instantiate cached feature {$className}", $e->getMessage());
                    }
                }
            }
            
            // Sort features by category and name
            $this->sortFeatures();
            return;
        }
        
        // Cache miss - scan directories
        $featuresPath = ZERO_SENSE_PATH . 'src/ZeroSense/Features/';
        
        foreach ($this->featureDirectories as $directory) {
            $fullPath = $featuresPath . $directory;
            $this->scanDirectory($fullPath, 'ZeroSense\\Features\\' . $directory);
        }

        // Sort features by category and name
        $this->sortFeatures();
        
        // Cache the feature class names for 24 hours
        $featureClasses = array_map(function($feature) {
            return get_class($feature);
        }, $this->features);
        
        set_transient($cacheKey, $featureClasses, DAY_IN_SECONDS);
    }
    
    /**
     * Sort features by category and name
     */
    private function sortFeatures(): void
    {
        usort($this->features, function($a, $b) {
            $categoryCompare = strcmp($a->getCategory(), $b->getCategory());
            if ($categoryCompare !== 0) {
                return $categoryCompare;
            }
            return strcmp($a->getName(), $b->getName());
        });
    }

    /**
     * Recursively scan directory for feature classes
     */
    private function scanDirectory(string $path, string $namespace): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->loadFeatureFromFile($file, $namespace);
            }
        }
    }

    /**
     * Load feature class from file
     */
    private function loadFeatureFromFile(\SplFileInfo $file, string $baseNamespace): void
    {
        $relativePath = str_replace(ZERO_SENSE_PATH . 'src/ZeroSense/Features/', '', $file->getPathname());
        $relativePath = str_replace('.php', '', $relativePath);
        $className = str_replace('/', '\\', $relativePath);
        $fullClassName = 'ZeroSense\\Features\\' . $className;

        if (class_exists($fullClassName)) {
            $reflection = new \ReflectionClass($fullClassName);
            
            if ($reflection->implementsInterface(FeatureInterface::class) && !$reflection->isAbstract()) {
                try {
                    $feature = new $fullClassName();
                    $this->features[] = $feature;
                } catch (\Exception $e) {
                    Logger::debug("Failed to instantiate feature {$fullClassName}", $e->getMessage());
                }
            }
        }
    }

    /**
     * Initialize all discovered features
     */
    public function initializeFeatures(): void
    {
        foreach ($this->features as $feature) {
            if ($this->shouldLoadFeature($feature)) {
                try {
                    $feature->init();
                } catch (\Exception $e) {
                    Logger::error("Failed to initialize feature {$feature->getName()}", $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if feature should be loaded based on conditions and settings
     */
    private function shouldLoadFeature(FeatureInterface $feature): bool
    {
        // Check if feature is enabled (for toggleable features)
        if ($feature->isToggleable() && !$feature->isEnabled()) {
            return false;
        }

        // Check conditions
        foreach ($feature->getConditions() as $condition) {
            if (!$this->evaluateCondition($condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a condition string
     */
    private function evaluateCondition(string $condition): bool
    {
        if (strpos($condition, 'class_exists:') === 0) {
            $className = substr($condition, strlen('class_exists:'));
            return class_exists($className);
        }

        if (strpos($condition, 'function_exists:') === 0) {
            $functionName = substr($condition, strlen('function_exists:'));
            return function_exists($functionName);
        }

        if (strpos($condition, 'defined:') === 0) {
            $constantName = substr($condition, strlen('defined:'));
            return defined($constantName);
        }

        if ($condition === 'is_admin') {
            return is_admin();
        }

        if ($condition === '!is_admin') {
            return !is_admin();
        }

        if ($condition === 'wp_doing_ajax') {
            return wp_doing_ajax();
        }

        if ($condition === '!wp_doing_ajax') {
            return !wp_doing_ajax();
        }

        // Unknown condition, log warning and return false for safety
        Logger::warning("Unknown condition '{$condition}' in feature evaluation");
        return false;
    }

    /**
     * Get all discovered features
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * Get features grouped by category
     */
    public function getFeaturesByCategory(): array
    {
        $categorized = [];
        foreach ($this->features as $feature) {
            $category = $feature->getCategory();
            if (!isset($categorized[$category])) {
                $categorized[$category] = [];
            }
            $categorized[$category][] = $feature;
        }
        return $categorized;
    }

    /**
     * Force reload all features (for debugging)
     * Clears the cache to force a fresh directory scan
     */
    public function reloadFeatures(): void
    {
        // Clear the cache
        $cacheKey = 'zs_feature_classes_v' . ZERO_SENSE_VERSION;
        delete_transient($cacheKey);
        
        $this->features = [];
        $this->discoverFeatures();
    }

    /**
     * Get feature by class name
     */
    public function getFeature(string $className): ?FeatureInterface
    {
        foreach ($this->features as $feature) {
            if (get_class($feature) === $className) {
                return $feature;
            }
        }
        return null;
    }
}
