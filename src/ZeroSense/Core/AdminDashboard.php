<?php
namespace ZeroSense\Core;

/**
 * Admin Dashboard
 * 
 * Auto-generates admin interface based on discovered features.
 * No manual configuration needed - features auto-register themselves.
 */
class AdminDashboard
{
    /**
     * Feature Manager instance
     */
    private FeatureManager $featureManager;

    /**
     * Constructor
     */
    public function __construct(FeatureManager $featureManager)
    {
        $this->featureManager = $featureManager;
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        // Global admin styles (load on all admin pages)
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminGlobalStyles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
        add_action('wp_ajax_zs_toggle_feature', [$this, 'handleAjaxToggle']);
        add_action('wp_ajax_zs_save_config', [$this, 'handleAjaxSaveConfig']);
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu(): void
    {
        add_options_page(
            __('Zerø Sense', 'zero-sense'),
            __('Zerø Sense', 'zero-sense'),
            'manage_options',
            'zero-sense-settings',
            [$this, 'renderDashboard']
        );

        // Add debug submenu (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'options-general.php',
                __('Zerø Sense Debug', 'zero-sense'),
                __('Zerø Sense Debug', 'zero-sense'),
                'manage_options',
                'zero-sense-debug',
                [$this, 'renderDebugPage']
            );
        }
    }

    /**
     * Register settings for all toggleable features
     */
    public function registerSettings(): void
    {
        foreach ($this->featureManager->getFeatures() as $feature) {
            if ($feature->isToggleable()) {
                $optionName = $this->getFeatureOptionName($feature);
                register_setting('zero_sense_settings', $optionName, [
                    'type' => 'boolean',
                    'default' => true,
                    'sanitize_callback' => 'rest_sanitize_boolean'
                ]);
            }
        }
    }
    /**
     * Enqueue admin global styles (for all admin pages)
     */
    public function enqueueAdminGlobalStyles(): void
    {
        $global_css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-global.css';
        if (!file_exists($global_css_path)) {
            return;
        }

        $global_css_ver = (string) filemtime($global_css_path);
        wp_enqueue_style(
            'zero-sense-admin-global',
            plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-global.css',
            [],
            $global_css_ver
        );
        
        // Enqueue metaboxes styles for order edit pages (Classic + HPOS)
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            $metaboxes_css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-metaboxes.css';
            if (file_exists($metaboxes_css_path)) {
                $metaboxes_css_ver = (string) filemtime($metaboxes_css_path);
                wp_enqueue_style(
                    'zero-sense-admin-metaboxes',
                    plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-metaboxes.css',
                    [],
                    $metaboxes_css_ver
                );
            }
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets($hook): void
    {
        // Load assets on both main dashboard and debug page
        if ($hook !== 'settings_page_zero-sense-settings' && $hook !== 'settings_page_zero-sense-debug') {
            return;
        }

        $reset_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/reset.css';
        $reset_ver  = file_exists($reset_path) ? (string) filemtime($reset_path) : ZERO_SENSE_VERSION;
        wp_enqueue_style(
            'zero-sense-admin-reset',
            plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/reset.css',
            [],
            $reset_ver
        );

        $admin_css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin.css';
        $admin_css_ver  = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : ZERO_SENSE_VERSION;
        wp_enqueue_style(
            'zero-sense-admin',
            plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin.css',
            ['zero-sense-admin-reset'],
            $admin_css_ver
        );

        // Load modular JavaScript (tabs, toggles, config, flowmattic)
        $base_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/js/';
        $base_url = plugin_dir_url(ZERO_SENSE_FILE) . 'assets/js/';
        
        // Helper function for versioning
        $get_version = function($filename) use ($base_path) {
            $path = $base_path . $filename;
            return file_exists($path) ? (string) filemtime($path) : ZERO_SENSE_VERSION;
        };
        
        // Load modules in order
        wp_enqueue_script(
            'zs-admin-tabs',
            $base_url . 'modules/admin-tabs.js',
            [],
            $get_version('modules/admin-tabs.js'),
            true
        );
        
        wp_enqueue_script(
            'zs-admin-toggles',
            $base_url . 'modules/admin-toggles.js',
            [],
            $get_version('modules/admin-toggles.js'),
            true
        );
        
        wp_enqueue_script(
            'zs-admin-config',
            $base_url . 'modules/admin-config.js',
            [],
            $get_version('modules/admin-config.js'),
            true
        );
        
        wp_enqueue_script(
            'zs-admin-flowmattic',
            $base_url . 'modules/admin-flowmattic.js',
            [],
            $get_version('modules/admin-flowmattic.js'),
            true
        );
        
        // Main coordinator (depends on all modules)
        wp_enqueue_script(
            'zero-sense-admin',
            $base_url . 'admin-modular.js',
            ['zs-admin-tabs', 'zs-admin-toggles', 'zs-admin-config', 'zs-admin-flowmattic'],
            $get_version('admin-modular.js'),
            true
        );

        // Localize script for AJAX
        wp_localize_script('zero-sense-admin', 'zsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zs_admin_nonce')
        ]);
    }

    /**
     * Render main dashboard
     */
    public function renderDashboard(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'zero-sense-settings')) {
            echo '<div class="updated notice is-dismissible"><p>' . 
                 __('Settings saved successfully!', 'zero-sense') . 
                 '</p></div>';
        }

        $categorizedFeatures = $this->featureManager->getFeaturesByCategory();
        
        // Handle force reload (for development)
        $reload = isset($_GET['zs_reload']) ? sanitize_text_field(wp_unslash($_GET['zs_reload'])) : '';
        if ($reload === '1' && defined('WP_DEBUG') && WP_DEBUG) {
            $this->featureManager->reloadFeatures();
            echo '<div class="updated notice is-dismissible"><p>Features reloaded successfully!</p></div>';
            $categorizedFeatures = $this->featureManager->getFeaturesByCategory();
        }

        
        ?>
        <div class="zs-dashboard">
            <div class="zs-header">
                <div class="zs-header-content">
                    <div class="zs-header-left">
                        <h1>Zer<span class="zero-o">ø</span> Sense</h1>
                        <p class="zs-description">
                            <?php _e('No tiene sentido. Tampoco lo necesita.', 'zero-sense'); ?>
                        </p>
                    </div>
                    <div class="zs-header-right">
                        <p class="zs-version-info">
                            <?php echo '<code>v:' . ZERO_SENSE_VERSION . '</code>'; ?>
                        </p>
                    </div>
                </div>
                
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('zero-sense-settings'); ?>
                
                <div class="zs-categories-nav">
                    <?php foreach ($categorizedFeatures as $category => $categoryFeatures): ?>
                        <a href="#zs-category-<?php echo esc_attr(sanitize_title($category)); ?>" 
                           class="zs-tab" 
                           data-category="<?php echo esc_attr(sanitize_title($category)); ?>">
                            <?php echo esc_html($category); ?>
                            <span class="zs-count"><?php echo count($categoryFeatures); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($categorizedFeatures as $category => $categoryFeatures): ?>
                    <div class="zs-category-content" 
                         id="zs-category-<?php echo esc_attr(sanitize_title($category)); ?>">
                        <h2 class="zs-category-title">
                            <?php echo esc_html($category); ?>
                            <span class="zs-category-count"><?php echo count($categoryFeatures); ?></span>
                        </h2>
                        <div class="zs-features-grid">
                            <?php foreach ($categoryFeatures as $feature): ?>
                                <?php if (method_exists($this, 'renderFeatureCard')): ?>
                                    <?php $this->renderFeatureCard($feature); ?>
                                <?php else: ?>
                                    <div class="zs-feature-card">
                                        <div class="zs-feature-header">
                                            <div class="zs-feature-title">
                                                <h3 class="zs-feature-name"><?php echo esc_html($feature->getName()); ?></h3>
                                                <div class="zs-feature-description"><?php echo esc_html($feature->getDescription()); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            </form>
        </div>
        <?php
        }

    /**
     * Render individual feature card
     */
    private function renderFeatureCard(FeatureInterface $feature): void
    {
        $optionName = $this->getFeatureOptionName($feature);
        $isEnabled = $feature->isEnabled();
        $cardClass = 'zs-feature-card';
        
        if ($feature->isToggleable()) {
            $cardClass .= $isEnabled ? ' active' : ' inactive';
        } else {
            $cardClass .= ' always-on';
        }

        // Use simple default design
        ?>
        <div class="<?php echo esc_attr($cardClass); ?>"
             data-feature-option="<?php echo esc_attr($optionName); ?>"
             data-feature-label="<?php echo esc_attr($feature->getName()); ?>">
            <div class="zs-feature-header">
                <div class="zs-feature-title">
                    <h3 class="zs-feature-name">
                        <?php echo esc_html($feature->getName()); ?>
                        <?php
                        // Calculate configuration and information availability once
                        $configFields = method_exists($feature, 'hasConfiguration') && $feature->hasConfiguration() 
                            ? $feature->getConfigurationFields() 
                            : [];
                        $infoBlocks = method_exists($feature, 'getInformationBlocks') 
                            ? (array) $feature->getInformationBlocks() 
                            : [];
                        
                        $hasConfig = !empty($configFields);
                        $hasInfo = (method_exists($feature, 'hasInformation') && $feature->hasInformation()) && !empty($infoBlocks);
                        // Precompute IDs so header icons can reference panels
                        $configId = 'zs-config-' . md5($optionName . '|' . $feature->getName());
                        $infoId   = 'zs-info-' . md5($optionName . '|' . $feature->getName());
                        ?>
                        <?php // Removed inline settings glyph next to title to reduce visual noise ?>
                    </h3>
                    <div class="zs-feature-description">
                        <?php echo esc_html($feature->getDescription()); ?>
                    </div>
                </div>
                
                <?php if ($feature->isToggleable()): ?>
                    <div class="zs-feature-controls">
                        <div class="zs-toggle-row">
                            <span class="zs-feature-feedback zs-toggle-feedback" role="status" aria-live="polite"></span>
                            <label class="zs-toggle-switch">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($optionName); ?>" 
                                       value="1" 
                                       <?php checked($isEnabled); ?>>
                                <span class="zs-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="zs-feature-actions">
                            <span class="zs-feature-feedback zs-settings-feedback" role="status" aria-live="polite"></span>
                            <?php if ($hasConfig): ?>
                                <button type="button"
                                        class="zs-card-icon zs-card-settings"
                                        title="<?php esc_attr_e('Settings', 'zero-sense'); ?>"
                                        aria-label="<?php esc_attr_e('Settings', 'zero-sense'); ?>"
                                        aria-controls="<?php echo esc_attr($configId); ?>"
                                        aria-expanded="false"
                                        data-target="#<?php echo esc_attr($configId); ?>">
                                    ⓢ
                                </button>
                            <?php endif; ?>
                            <?php if ($hasInfo): ?>
                                <button type="button"
                                        class="zs-card-icon zs-card-info"
                                        title="<?php esc_attr_e('Information', 'zero-sense'); ?>"
                                        aria-label="<?php esc_attr_e('Information', 'zero-sense'); ?>"
                                        aria-controls="<?php echo esc_attr($infoId); ?>"
                                        aria-expanded="false"
                                        data-target="#<?php echo esc_attr($infoId); ?>">
                                    ⓘ
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="zs-feature-controls">
                        <span class="zs-feature-status always-on">
                            <?php _e('Always Active', 'zero-sense'); ?>
                        </span>
                        <div class="zs-feature-actions">
                            <span class="zs-feature-feedback zs-settings-feedback" role="status" aria-live="polite"></span>
                            <?php if ($hasConfig): ?>
                                <button type="button"
                                        class="zs-card-icon zs-card-settings"
                                        title="<?php esc_attr_e('Settings', 'zero-sense'); ?>"
                                        aria-label="<?php esc_attr_e('Settings', 'zero-sense'); ?>"
                                        aria-controls="<?php echo esc_attr($configId); ?>"
                                        aria-expanded="false"
                                        data-target="#<?php echo esc_attr($configId); ?>">
                                    ⓢ
                                </button>
                            <?php endif; ?>
                            <?php if ($hasInfo): ?>
                                <button type="button"
                                        class="zs-card-icon zs-card-info"
                                        title="<?php esc_attr_e('Information', 'zero-sense'); ?>"
                                        aria-label="<?php esc_attr_e('Information', 'zero-sense'); ?>"
                                        aria-controls="<?php echo esc_attr($infoId); ?>"
                                        aria-expanded="false"
                                        data-target="#<?php echo esc_attr($infoId); ?>">
                                    ⓘ
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            
            <?php if ($hasConfig): ?>
                <?php 
                    // Unique ID for config fields to link aria-controls (precomputed above as $configId)
                    $isEnabled = $feature->isEnabled();
                ?>
                <div class="zs-feature-config zs-config-collapsed">
                    <div class="zs-config-header zs-config-toggle-header" role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr($configId); ?>" aria-label="Toggle settings section">
                        <h4>Settings</h4>
                        <button type="button" class="zs-config-toggle" aria-hidden="true"></button>
                    </div>
                    <div id="<?php echo esc_attr($configId); ?>" class="zs-config-fields">
                        <?php 
                        $requiresSaveButton = false;
                        foreach ($configFields as $field): 
                            $fieldType = $field['type'] ?? '';
                            if ($fieldType !== 'html') {
                                $requiresSaveButton = true;
                            }
                        ?>
                            <div class="zs-config-field">
                                <?php if (($field['type'] ?? '') === 'html'): ?>
                                    <div class="zs-config-static">
                                        <?php echo $field['html'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                <?php else: ?>
                                    <label for="<?php echo esc_attr($field['name']); ?>" class="zs-config-label">
                                        <?php echo esc_html($field['label'] ?? ''); ?>
                                    </label>
                                    <?php if (($field['type'] ?? '') === 'text'): ?>
                                        <input type="text" 
                                               id="<?php echo esc_attr($field['name']); ?>"
                                               name="<?php echo esc_attr($field['name']); ?>"
                                               value="<?php echo esc_attr($field['value'] ?? ''); ?>"
                                               placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                               class="zs-config-input">
                                    <?php elseif (($field['type'] ?? '') === 'number'): ?>
                                        <input type="number"
                                               id="<?php echo esc_attr($field['name']); ?>"
                                               name="<?php echo esc_attr($field['name']); ?>"
                                               value="<?php echo esc_attr($field['value'] ?? ''); ?>"
                                               placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                               min="<?php echo isset($field['min']) ? esc_attr($field['min']) : '0'; ?>"
                                               max="<?php echo isset($field['max']) ? esc_attr($field['max']) : ''; ?>"
                                               step="<?php echo isset($field['step']) ? esc_attr($field['step']) : '1'; ?>"
                                               class="zs-config-input">
                                    <?php endif; ?>
                                    <?php if (!empty($field['description'])): ?>
                                        <p class="zs-config-description"><?php echo esc_html($field['description']); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($requiresSaveButton): ?>
                            <div class="zs-config-actions">
                                <button type="button" class="zs-save-config-btn" data-feature="<?php echo esc_attr($feature->getName()); ?>">
                                    💾 Save Configuration
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasInfo): ?>
                <div class="zs-feature-config zs-feature-info zs-config-collapsed">
                    <div class="zs-config-header zs-config-toggle-header" role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr($infoId); ?>" aria-label="Toggle information section">
                        <h4>Information</h4>
                        <button type="button" class="zs-config-toggle" aria-hidden="true"></button>
                    </div>
                    <div id="<?php echo esc_attr($infoId); ?>" class="zs-config-fields">
                        <?php foreach ($infoBlocks as $block):
                            $type = $block['type'] ?? 'text';
                            $title = $block['title'] ?? '';
                            $content = $block['content'] ?? '';
                        ?>
                            <div class="zs-config-field zs-info-field">
                                <?php if ($title): ?>
                                    <h5 class="zs-info-title"><?php echo esc_html($title); ?></h5>
                                <?php endif; ?>

                                <?php if ($type === 'html'): ?>
                                    <div class="zs-info-static"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                <?php elseif ($type === 'list' && !empty($block['items']) && is_array($block['items'])): ?>
                                    <ul class="zs-info-list">
                                        <?php foreach ($block['items'] as $item): ?>
                                            <li><?php echo esc_html($item); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="zs-info-text"><?php echo esc_html($content); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle form submission
     */
    public function handleFormSubmission(): void
    {
        // Check if this is a form submission (not AJAX)
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'zero-sense-settings')) {
            foreach ($this->featureManager->getFeatures() as $feature) {
                // Handle toggleable features
                if ($feature->isToggleable()) {
                    $optionName = $this->getFeatureOptionName($feature);
                    $value = isset($_POST[$optionName]) ? 1 : 0;
                    update_option($optionName, $value);
                }
                
                // Handle configuration fields
                if (method_exists($feature, 'hasConfiguration') && $feature->hasConfiguration()) {
                    $configFields = $feature->getConfigurationFields();
                    foreach ($configFields as $field) {
                        if (isset($_POST[$field['name']])) {
                            $value = $_POST[$field['name']];
                            if (($field['type'] ?? '') === 'textarea' && function_exists('br_tag_accepted')) {
                                $value = br_tag_accepted($value);
                            } else {
                                $value = sanitize_text_field($value);
                            }
                            update_option($field['name'], $value);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get option name for feature
     */
    private function getFeatureOptionName(FeatureInterface $feature): string
    {
        // Check if feature defines its own option name
        if (method_exists($feature, 'getOptionName')) {
            return $feature->getOptionName();
        }
        
        // Fallback to auto-generated name
        $className = get_class($feature);
        $shortName = str_replace('ZeroSense\\Features\\', '', $className);
        $shortName = str_replace('\\', '_', $shortName);
        return 'zs_' . strtolower($shortName);
    }

    /**
     * Get category icon
     */
    private function getCategoryIcon(string $category): string
    {
        // Map categories to icon slugs stored in assets/icons/*.svg
        $map = [
            'WordPress' => 'wordpress',
            'WooCommerce' => 'woocommerce',
            'Security' => 'shield',
            'Utilities' => 'wrench',
            'Bricks Builder' => 'bricks',
            'Flowmattic' => 'flow',
            'WPML' => 'globe',
            'Integrations' => 'link',
        ];

        $slug = $map[$category] ?? 'settings';
        $path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/icons/' . $slug . '.svg';

        if (file_exists($path)) {
            // Return inline SVG to inherit currentColor
            $svg = file_get_contents($path);
            if ($svg !== false) {
                return $svg;
            }
        }

        // Fallback: minimal settings glyph
        $fallback = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/icons/settings.svg';
        if (file_exists($fallback)) {
            $svg = file_get_contents($fallback);
            if ($svg !== false) {
                return $svg;
            }
        }

        return '';
    }

    /**
     * Get feature icon based on category
     */
    private function getFeatureIcon(string $category): string
    {
        return $this->getCategoryIcon($category);
    }

    /**
     * Handle AJAX toggle requests
     */
    public function handleAjaxToggle(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'zs_admin_nonce')) {
            wp_die(__('Security check failed', 'zero-sense'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zero-sense'));
        }

        $optionName = sanitize_text_field(wp_unslash($_POST['feature'] ?? ''));
        $enabled = (sanitize_text_field(wp_unslash($_POST['enabled'] ?? '0')) === '1');

        if (empty($optionName)) {
            wp_send_json_error('Invalid option name');
            return;
        }

        // Validate that this is a valid feature option
        $validOption = false;
        $featureName = 'Unknown';
        foreach ($this->featureManager->getFeatures() as $feature) {
            if ($this->getFeatureOptionName($feature) === $optionName) {
                $validOption = true;
                $featureName = $feature->getName();
                break;
            }
        }

        if (!$validOption) {
            wp_send_json_error('Invalid option name: ' . $optionName);
            return;
        }

        // Update the option using delete + add approach (more reliable)
        delete_option($optionName);
        $updateResult = add_option($optionName, $enabled, '', false);
        
        // Re-initialize the specific feature to apply the change immediately
        foreach ($this->featureManager->getFeatures() as $feature) {
            if ($this->getFeatureOptionName($feature) === $optionName) {
                try {
                    $feature->init();
                } catch (\Exception $e) {
                    error_log("Zero Sense: Error re-initializing feature {$feature->getName()}: " . $e->getMessage());
                }
                break;
            }
        }
        
        wp_send_json_success([
            'feature' => $featureName,
            'enabled' => $enabled,
            'option' => $optionName
        ]);
    }

    /**
     * Handle AJAX save configuration
     */
    public function handleAjaxSaveConfig(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'zs_admin_nonce')) {
            wp_die(__('Security check failed', 'zero-sense'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zero-sense'));
        }

        $featureName = sanitize_text_field(wp_unslash($_POST['feature'] ?? ''));
        $configRaw = isset($_POST['config']) ? wp_unslash($_POST['config']) : [];

        if (empty($featureName)) {
            wp_send_json_error('Invalid feature name');
            return;
        }

        // Find the feature
        $targetFeature = null;
        foreach ($this->featureManager->getFeatures() as $feature) {
            if ($feature->getName() === $featureName) {
                $targetFeature = $feature;
                break;
            }
        }

        if (!$targetFeature || !method_exists($targetFeature, 'hasConfiguration') || !$targetFeature->hasConfiguration()) {
            wp_send_json_error('Feature not found or has no configuration');
            return;
        }

        // Get valid configuration fields
        $configFields = $targetFeature->getConfigurationFields();
        $savedFields = [];

        foreach ($configFields as $field) {
            if (isset($configRaw[$field['name']])) {
                $value = $configRaw[$field['name']];
                if (($field['type'] ?? '') === 'textarea' && function_exists('br_tag_accepted')) {
                    $value = br_tag_accepted($value);
                } else {
                    $value = sanitize_text_field($value);
                }
                update_option($field['name'], $value);
                $savedFields[$field['name']] = $value;
            }
        }

        // Re-initialize the feature to apply changes
        try {
            $targetFeature->init();
        } catch (\Exception $e) {
            error_log("Zero Sense: Error re-initializing feature {$targetFeature->getName()}: " . $e->getMessage());
        }

        wp_send_json_success([
            'feature' => $featureName,
            'saved_fields' => $savedFields
        ]);
    }

    /**
     * Render debug page
     */
    public function renderDebugPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle manual toggle actions
        if (isset($_GET['debug_action']) && isset($_GET['feature_option'])) {
            $action = sanitize_text_field(wp_unslash($_GET['debug_action']));
            $featureOption = sanitize_text_field(wp_unslash($_GET['feature_option']));
            
            if ($action === 'enable') {
                update_option($featureOption, true);
                echo '<div class="updated notice is-dismissible"><p>✅ Enabled: ' . esc_html($featureOption) . '</p></div>';
            } elseif ($action === 'disable') {
                update_option($featureOption, false);
                echo '<div class="updated notice is-dismissible"><p>❌ Disabled: ' . esc_html($featureOption) . '</p></div>';
            }
        }

        $features = $this->featureManager->getFeatures();
        ?>
        <div class="wrap">
            <h1>🧪 Zerø Sense Debug Tool</h1>
            
            <details class="zs-collapsible" id="zs-feature-status">
                <summary><h2 style="display:inline">📊 Feature Status Overview</h2></summary>
                <div class="zs-collapsible-content">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th>Category</th>
                                <th>Toggleable</th>
                                <th>Enabled</th>
                                <th>Option Name</th>
                                <th>Option Value</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($features as $feature): ?>
                                <?php
                                $optionName = $this->getFeatureOptionName($feature);
                                $optionValue = get_option($optionName, 'not set');
                                $isEnabled = $feature->isEnabled();
                                $isToggleable = $feature->isToggleable();
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($feature->getName()); ?></strong></td>
                                    <td><?php echo esc_html($feature->getCategory()); ?></td>
                                    <td><?php echo $isToggleable ? '✅ Yes' : '❌ No'; ?></td>
                                    <td><?php echo $isEnabled ? '🟢 Enabled' : '🔴 Disabled'; ?></td>
                                    <td><code><?php echo esc_html($optionName); ?></code></td>
                                    <td><code><?php echo esc_html(var_export($optionValue, true)); ?></code></td>
                                    <td>
                                        <?php if ($isToggleable): ?>
                                            <em>Use main dashboard toggles</em>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            
 <h2>🔧 AJAX Test</h2>
            <div class="card">
                <p>Test the AJAX toggle functionality:</p>
                <button id="test-ajax" class="button button-primary">Test AJAX Toggle</button>
                <div id="ajax-result" style="margin-top: 10px;"></div>
            </div>

            <h2>🔍 Live Toggle Debug</h2>
            <div class="card">
                <p>Real-time monitoring of toggle events and state changes:</p>
                <div id="toggle-debug-log" style="background: #f1f1f1; padding: 10px; height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; border: 1px solid #ddd;"></div>
                <button id="clear-debug" class="button button-secondary" style="margin-top: 10px;">Clear Log</button>
                <button id="refresh-states" class="button button-secondary" style="margin-top: 10px;">Refresh States</button>
            </div>

            <h2>🎣 WordPress Hooks Status</h2>
            <div class="card">
                <p><strong>Current Filter:</strong> <?php echo current_filter(); ?></p>
                <p><strong>Current Hook:</strong> <?php echo esc_html(isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'unknown'); ?></p>
                <p><strong>Is Admin:</strong> <?php echo is_admin() ? 'Yes' : 'No'; ?></p>
                <p><strong>AJAX Action Registered:</strong> <?php echo has_action('wp_ajax_zs_toggle_feature') ? 'Yes' : 'No'; ?></p>
                <p><strong>Admin CSS Enqueued:</strong> <?php echo wp_style_is('zero-sense-admin', 'enqueued') ? 'Yes' : 'No'; ?></p>
                <p><strong>Admin JS Enqueued:</strong> <?php echo wp_script_is('zero-sense-admin', 'enqueued') ? 'Yes' : 'No'; ?></p>
                <p><strong>zsAdmin Object Available:</strong> <span id="zsadmin-status">Checking...</span></p>
            </div>
            <script>
            // Create zsAdmin object for debug page
            window.zsAdmin = {
                ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce: '<?php echo wp_create_nonce('zs_admin_nonce'); ?>'
            };
            // Simple debug logger for this page
            function debugLog(msg, level) {
                try {
                    var box = document.getElementById('toggle-debug-log');
                    if (!box) { return; }
                    var color = '#333';
                    if (level === 'success') color = 'green';
                    else if (level === 'warning') color = '#b58900';
                    else if (level === 'error') color = 'red';
                    var line = document.createElement('div');
                    line.style.color = color;
                    line.textContent = new Date().toISOString() + ' ' + String(msg);
                    box.appendChild(line);
                    box.scrollTop = box.scrollHeight;
                } catch (e) { /* no-op */ }
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                // Update zsAdmin status
                var statusEl = document.getElementById('zsadmin-status');
                if (statusEl) {
                    statusEl.textContent = typeof zsAdmin !== 'undefined' ? 'Yes' : 'No';
                    statusEl.style.color = typeof zsAdmin !== 'undefined' ? 'green' : 'red';
                }

                // AJAX test functionality
                document.getElementById('test-ajax').addEventListener('click', function() {
                    var resultDiv = document.getElementById('ajax-result');
                    resultDiv.innerHTML = '<p>Testing AJAX...</p>';
                    
                    if (typeof zsAdmin === 'undefined') {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ zsAdmin object not found!</p></div>';
                        return;
                    }
                    
                    // Find first toggleable feature option name dynamically
                    var firstToggle = document.querySelector('.zs-toggle-switch input');
                    if (!firstToggle) {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ No toggleable features found on this page to test.</p></div>';
                        return;
                    }

                    var optionName = firstToggle.getAttribute('name');
                    var formData = new FormData();
                    formData.append('action', 'zs_toggle_feature');
                    formData.append('feature', optionName);
                    formData.append('enabled', firstToggle.checked ? '0' : '1');
                    formData.append('nonce', zsAdmin.nonce);
                    
                    fetch(zsAdmin.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            resultDiv.innerHTML = '<div class="notice notice-success"><p>✅ AJAX Success: ' + JSON.stringify(data.data) + '</p></div>';
                        } else {
                            resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ AJAX Error: ' + JSON.stringify(data.data) + '</p></div>';
                        }
                    })
                    .catch(error => {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Network Error: ' + error.message + '</p></div>';
                    });
                });

                // Clear debug log button
                document.getElementById('clear-debug').addEventListener('click', function() {
                    document.getElementById('toggle-debug-log').innerHTML = '';
                    debugLog('Debug log cleared', 'info');
                });

                // Refresh states button
                document.getElementById('refresh-states').addEventListener('click', function() {
                    logCurrentStates();
                });

                // Monitor ALL toggle changes
                var toggles = document.querySelectorAll('.zs-toggle-switch input');
                toggles.forEach(function(toggle, index) {
                    debugLog('Found toggle ' + (index + 1) + ': ' + toggle.name + ' (initially ' + (toggle.checked ? 'checked' : 'unchecked') + ')', 'info');
                    
                    toggle.addEventListener('change', function(event) {
                        debugLog('=== TOGGLE CHANGE EVENT ===', 'warning');
                        debugLog('Toggle: ' + this.name, 'info');
                        debugLog('New state: ' + (this.checked ? 'CHECKED' : 'UNCHECKED'), this.checked ? 'success' : 'warning');
                        debugLog('Event triggered by: ' + event.type, 'info');
                        
                        // Log states of ALL toggles after this change
                        setTimeout(function() {
                            debugLog('States after change:', 'info');
                            logCurrentStates();
                        }, 100);
                    });

                    // Also monitor for programmatic changes
                    var observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'attributes' && mutation.attributeName === 'checked') {
                                debugLog('PROGRAMMATIC change detected on ' + toggle.name + ': ' + (toggle.checked ? 'CHECKED' : 'UNCHECKED'), 'error');
                            }
                        });
                    });
                    observer.observe(toggle, { attributes: true });
                });

                // Monitor for any AJAX requests
                var originalFetch = window.fetch;
                window.fetch = function() {
                    debugLog('AJAX request initiated: ' + arguments[0], 'warning');
                    return originalFetch.apply(this, arguments)
                        .then(function(response) {
                            debugLog('AJAX response received: ' + response.status, response.ok ? 'success' : 'error');
                            return response;
                        });
                };
            });
            </script>
            
        </div>
        <?php
    }
}
