<?php
declare(strict_types=1);

namespace ZeroSense\Features\Utilities;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Components\DataExposureMetabox;

class DataExposureDebug implements FeatureInterface
{
    public function getName(): string
    {
        return __('Data Exposure Inspector', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Shows exposed fields to FlowMattic and Bricks Builder in order admin sidebar for debugging purposes.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Utilities';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_utilities_dataexposuredebug';
    }

    public function isEnabled(): bool
    {
        return self::isEnabledStatic();
    }

    public static function isEnabledStatic(): bool
    {
        // Clear cache before reading to ensure fresh value
        wp_cache_delete('zs_utilities_dataexposuredebug', 'options');
        
        // Debug: Check if option exists in database directly
        global $wpdb;
        $db_value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
            'zs_utilities_dataexposuredebug'
        ));
        
        $value = get_option('zs_utilities_dataexposuredebug', false);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $backtrace[1]['function'] ?? 'unknown';
        $class = $backtrace[1]['class'] ?? 'unknown';
        
        error_log('DataExposureDebug: DB value = ' . var_export($db_value, true));
        error_log('DataExposureDebug: get_option value = ' . var_export($value, true) . ' (called from ' . $class . '::' . $caller . ')');
        
        return (bool) $value;
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        (new DataExposureMetabox())->register();
    }
}
