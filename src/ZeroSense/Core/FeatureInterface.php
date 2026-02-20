<?php
declare(strict_types=1);

namespace ZeroSense\Core;

/**
 * Interface FeatureInterface
 *
 * All features must implement this interface to be auto-discovered
 * and displayed in the admin dashboard.
 */
interface FeatureInterface
{
    /**
     * Human-readable label shown in the dashboard card header.
     */
    public function getName(): string;

    /**
     * Short explanation displayed under the feature title.
     */
    public function getDescription(): string;

    /**
     * Category key (WordPress, WooCommerce, etc.) must match a directory registered in FeatureManager.
     */
    public function getCategory(): string;

    /**
     * Return true to render a toggle. Always-on features should return false.
     */
    public function isToggleable(): bool;

    /**
     * Persisted state of the feature. Use `getOptionName()` when available for consistency.
     */
    public function isEnabled(): bool;

    /**
     * Register hooks and filters. Guard early with `isEnabled()` to avoid unnecessary work.
     */
    public function init(): void;

    /**
     * Loading order: lower numbers run earlier. Default should be 10.
     */
    public function getPriority(): int;

    /**
     * Optional gate checks evaluated before init. Examples: `is_admin`, `!is_admin`, `class_exists:WooCommerce`.
     *
     * @return array Array of condition strings to evaluate before loading the feature.
     */
    public function getConditions(): array;
}
