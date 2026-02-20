<?php
declare(strict_types=1);

namespace ZeroSense\Core;

class Logger
{
    private static string $prefix = 'Zero Sense';
    
    /**
     * Log debug message (only when WP_DEBUG is enabled)
     */
    public static function debug(string $message, ?string $context = null): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        self::log('DEBUG', $message, $context);
    }
    
    /**
     * Log error message
     */
    public static function error(string $message, ?string $context = null): void
    {
        self::log('ERROR', $message, $context);
    }
    
    /**
     * Log info message (only when WP_DEBUG is enabled)
     */
    public static function info(string $message, ?string $context = null): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        self::log('INFO', $message, $context);
    }
    
    /**
     * Log warning message (only when WP_DEBUG is enabled)
     */
    public static function warning(string $message, ?string $context = null): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        self::log('WARNING', $message, $context);
    }
    
    /**
     * Log migration operations (controlled verbosity)
     */
    public static function migration(string $message, bool $verbose = false): void
    {
        // Only log migration details if verbose is true or it's an important message
        if ($verbose || in_array($message, ['Migration completed successfully', 'Migration completed with errors'])) {
            self::log('MIGRATION', $message);
        }
    }
    
    /**
     * Core logging method
     */
    private static function log(string $level, string $message, ?string $context = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? " [{$context}]" : '';
        $logMessage = "[{$timestamp}] [{$level}] [" . self::$prefix . "]{$contextStr} {$message}";
        
        error_log($logMessage);
    }
    
    /**
     * Set custom prefix for specific modules
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }
    
    /**
     * Reset prefix to default
     */
    public static function resetPrefix(): void
    {
        self::$prefix = 'Zero Sense';
    }
}
