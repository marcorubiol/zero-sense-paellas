<?php
namespace ZeroSense\Features\Security;

use ZeroSense\Core\FeatureInterface;

/**
 * Security Headers
 *
 * Adds strong security headers to protect the website from various attacks.
 * Includes HSTS, X-Frame-Options, X-Content-Type-Options, CSP and more.
 */
class SecurityHeaders implements FeatureInterface
{
    /**
     * Get feature name
     */
    public function getName(): string
    {
        return __('Security Headers', 'zero-sense');
    }

    /**
     * Get feature description
     */
    public function getDescription(): string
    {
        return __('Automatically adds essential security headers to protect your website from XSS, clickjacking, MIME-type confusion and other common web attacks.', 'zero-sense');
    }

    /**
     * Get feature category
     */
    public function getCategory(): string
    {
        return 'Security';
    }

    /**
     * Check if feature is toggleable
     */
    public function isToggleable(): bool
    {
        return false; // Always on for security
    }

    /**
     * Check if feature is enabled
     */
    public function isEnabled(): bool
    {
        return true; // Always enabled
    }

    /**
     * Get feature priority
     */
    public function getPriority(): int
    {
        return 5; // High priority for security
    }

    /**
     * Get conditions for loading this feature
     */
    public function getConditions(): array
    {
        return []; // Load everywhere
    }

    /**
     * Initialize the feature
     */
    public function init(): void
    {
        add_filter('wp_headers', [$this, 'applySecurityHeaders'], 10, 1);
    }

    public function applySecurityHeaders(array $headers): array
    {
        $baseHeaders = [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), fullscreen=(), payment=() ',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Expect-CT' => 'max-age=86400, enforce',
        ];

        $cspDirectives = $this->buildCspDirectives();
        $cspSegments = [];

        foreach ($cspDirectives as $directive => $sources) {
            $sources = array_values(array_unique(array_filter($sources, 'strlen')));

            if (empty($sources)) {
                continue;
            }

            $cspSegments[] = sprintf('%s %s', $directive, implode(' ', $sources));
        }

        if (!empty($cspSegments)) {
            $baseHeaders['Content-Security-Policy'] = implode('; ', $cspSegments) . ';';
        }

        /**
         * Allow overriding the base security headers before they are merged.
         *
         * @param array<string,string>   $baseHeaders
         * @param array<string,string[]> $cspDirectives
         */
        $baseHeaders = apply_filters('zero_sense_security_headers', $baseHeaders, $cspDirectives);

        foreach ($baseHeaders as $key => $value) {
            $headers[$key] = $value;
        }

        return $headers;
    }

    /**
     * Build Content-Security-Policy directives.
     *
     * @return array<string,string[]>
     */
    private function buildCspDirectives(): array
    {
        $googleDomains = [
            'https://*.google-analytics.com',
            'https://*.analytics.google.com',
            'https://*.googletagmanager.com',
            'https://*.google.com',
            'https://*.g.doubleclick.net',
            'https://stats.g.doubleclick.net',
        ];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_merge([
                "'self'",
                "'unsafe-inline'",
                "'unsafe-eval'",
                'https://cdn.paellasencasa.com',
                'https://cdn.jsdelivr.net',
                'https://optimizerwpc.b-cdn.net',
                'https://stats.wp.com',
            ], $googleDomains, ['data:', 'blob:']),
            'worker-src' => array_merge(["'self'", 'data:', 'blob:'], $googleDomains),
            'style-src' => ["'self'", "'unsafe-inline'", 'https://cdn.paellasencasa.com', 'https://cdn.jsdelivr.net', 'https://ams.wpml.org'],
            'img-src' => array_merge(["'self'", 'data:', 'https:', 'http:'], $googleDomains),
            'font-src' => ["'self'", 'data:', 'https://cdn.paellasencasa.com'],
            'connect-src' => array_merge(["'self'", 'wss:', 'https:', 'https://stats.wp.com', 'https://ams.wpml.org'], $googleDomains),
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'", 'https://sis-t.redsys.es:25443', 'https://sis.redsys.es'],
            'frame-ancestors' => ["'self'"],
        ];

        /**
         * Allow modifying CSP directives at runtime.
         *
         * @param array<string,string[]> $directives
         * @param array<int,string>      $googleDomains
         */
        return apply_filters('zero_sense_security_csp_directives', $directives, $googleDomains);
    }

    /**
     * Check if feature has information
     */
    public function hasInformation(): bool
    {
        return true;
    }

    /**
     * Get information blocks for dashboard
     */
    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Security headers included', 'zero-sense'),
                'items' => [
                    __('Strict-Transport-Security (HSTS) - Forces HTTPS connections', 'zero-sense'),
                    __('X-Frame-Options - Prevents clickjacking attacks', 'zero-sense'),
                    __('X-Content-Type-Options - Blocks MIME-type sniffing', 'zero-sense'),
                    __('Content-Security-Policy - Controls resource loading', 'zero-sense'),
                    __('Permissions-Policy - Restricts browser features', 'zero-sense'),
                    __('Referrer-Policy - Controls referrer information', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Pre-configured domains', 'zero-sense'),
                'content' => __('Includes trusted domains for Google Analytics, CDNs, and payment gateways (Redsys) to ensure functionality while maintaining security.', 'zero-sense'),
            ],
            [
                'type' => 'text',
                'title' => __('Developer customization', 'zero-sense'),
                'content' => __('Headers can be customized using WordPress filters: zero_sense_security_headers and zero_sense_security_csp_directives.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Security/SecurityHeaders.php', 'zero-sense'),
                    __('applySecurityHeaders() → composes final headers and CSP', 'zero-sense'),
                    __('buildCspDirectives() → returns directives (filterable)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('wp_headers (injection point for headers)', 'zero-sense'),
                    __('zero_sense_security_headers (filter override)', 'zero-sense'),
                    __('zero_sense_security_csp_directives (filter directives)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Use browser DevTools → Network → Response Headers to verify all keys.', 'zero-sense'),
                    __('Confirm CSP allows GA/CDN/scripts used on site; watch console for CSP violations.', 'zero-sense'),
                    __('If a service is blocked, extend allowlists via the provided filters.', 'zero-sense'),
                ],
            ],
        ];
    }
}
