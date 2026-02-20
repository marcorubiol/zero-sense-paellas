<?php
declare(strict_types=1);

namespace ZeroSense\Core;

/**
 * Main Plugin Class
 * 
 * Singleton pattern to ensure only one instance of the plugin runs.
 * Handles initialization, feature management, and plugin lifecycle.
 */
class Plugin
{
    /**
     * Plugin instance
     */
    private static ?Plugin $instance = null;

    /**
     * Feature Manager instance
     */
    private FeatureManager $featureManager;

    /**
     * Admin Dashboard instance
     */
    private ?AdminDashboard $adminDashboard = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->init();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * Initialize the plugin
     */
    private function init(): void
    {
        // Initialize feature manager
        $this->featureManager = new FeatureManager();
        $this->featureManager->registerCacheInvalidation();
        $this->featureManager->discoverFeatures();
        $this->featureManager->initializeFeatures();

        // Initialize admin dashboard
        if (is_admin()) {
            $this->adminDashboard = new AdminDashboard($this->featureManager);
        }
    }

    /**
     * Get feature manager instance
     */
    public function getFeatureManager(): FeatureManager
    {
        return $this->featureManager;
    }

    /**
     * Get admin dashboard instance
     */
    public function getAdminDashboard(): ?AdminDashboard
    {
        return $this->adminDashboard;
    }

    /**
     * Plugin activation hook
     */
    public static function activate(): void
    {
        // Clear feature discovery cache on activation
        (new FeatureCache())->clear();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate(): void
    {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall hook
     */
    public static function uninstall(): void
    {
        // Clean up options if needed
        // Note: We keep settings by default, but this can be changed
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
