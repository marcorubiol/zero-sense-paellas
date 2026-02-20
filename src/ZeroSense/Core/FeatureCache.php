<?php
declare(strict_types=1);

namespace ZeroSense\Core;

class FeatureCache
{
    private string $cacheKey;

    public function __construct()
    {
        $this->cacheKey = 'zs_feature_classes_v' . ZERO_SENSE_VERSION;
    }

    public function registerInvalidationHooks(): void
    {
        add_action('update_option_zero_sense_settings', [$this, 'clear']);
        add_action('update_option_zero_sense_features', [$this, 'clear']);
    }

    public function clear(): void
    {
        delete_transient($this->cacheKey);
    }

    public function get(): ?array
    {
        $cached = get_transient($this->cacheKey);
        return ($cached !== false && is_array($cached)) ? $cached : null;
    }

    public function set(array $classes): void
    {
        set_transient($this->cacheKey, $classes, DAY_IN_SECONDS);
    }
}
