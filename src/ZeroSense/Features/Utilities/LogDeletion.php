<?php
declare(strict_types=1);

namespace ZeroSense\Features\Utilities;

use ZeroSense\Core\FeatureInterface;

class LogDeletion implements FeatureInterface
{
    public function getName(): string
    {
        return __('Log Deletion', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Enables individual log entry deletion across all log metaboxes (Deposits, Calendar, Status, Email, Holded). Shows a delete button on hover for each log item.', 'zero-sense');
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
        return 'zs_utilities_logdeletion';
    }

    public function isEnabled(): bool
    {
        return (bool) get_option('zs_utilities_logdeletion', true);
    }

    public function init(): void
    {
        // This feature is implemented via LogDeletionTrait
        // The trait is used by individual metabox classes
        // No initialization needed here - the trait handles everything
    }
}
