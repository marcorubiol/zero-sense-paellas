<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

class ServiceAreaAdminColumns
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('manage_edit-service-area_columns', [$this, 'addColumns']);
        add_filter('manage_service-area_custom_column', [$this, 'renderColumn'], 10, 3);
    }

    public function addColumns(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'name') {
                $out['zs_canonical_id'] = __('Canonical ID', 'zero-sense');
            }
        }

        if (!isset($out['zs_canonical_id'])) {
            $out['zs_canonical_id'] = __('Canonical ID', 'zero-sense');
        }

        return $out;
    }

    public function renderColumn($content, string $columnName, int $termId)
    {
        if ($columnName !== 'zs_canonical_id') {
            return $content;
        }

        $canonicalId = $termId;

        if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
            $defaultLang = apply_filters('wpml_default_language', null);
            if (is_string($defaultLang) && $defaultLang !== '') {
                $translated = apply_filters('wpml_object_id', $termId, 'service-area', true, $defaultLang);
                if ($translated) {
                    $canonicalId = (int) $translated;
                }
            }
        }

        return (string) $canonicalId;
    }
}
