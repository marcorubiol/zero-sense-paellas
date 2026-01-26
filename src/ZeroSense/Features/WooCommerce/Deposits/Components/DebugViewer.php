<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

/**
 * Debug Viewer for Deposits - Adds admin page to view logs
 */
class DebugViewer
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'addDebugPage']);
        add_action('wp_ajax_zs_deposits_clear_logs', [$this, 'clearLogs']);
    }

    public function addDebugPage(): void
    {
        add_submenu_page(
            'woocommerce',
            'Deposits Debug',
            'Deposits Debug',
            'manage_options',
            'zs-deposits-debug',
            [$this, 'renderDebugPage']
        );
    }

    public function renderDebugPage(): void
    {
        ?>
        <div class="wrap">
            <h1>🔍 ZS Deposits Debug Logs</h1>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
                <h3>Últimos Logs de Depósitos</h3>
                
                <button type="button" onclick="location.reload()" class="button">
                    🔄 Recargar Logs
                </button>
                
                <div style="margin-top: 20px; font-family: monospace; font-size: 12px; background: #f9f9f9; padding: 15px; border: 1px solid #ddd; max-height: 500px; overflow-y: auto;">
                    <?php $this->displayLogs(); ?>
                </div>
                
                <p><strong>Instrucciones:</strong></p>
                <ol>
                    <li>Abre esta página en una pestaña</li>
                    <li>En otra pestaña, edita un pedido con depósitos</li>
                    <li>Cambia el monto del depósito manualmente</li>
                    <li>Haz clic en "Update"</li>
                    <li>Recarga esta página para ver los logs</li>
                </ol>
            </div>
        </div>
        <?php
    }

    private function displayLogs(): void
    {
        $log_locations = [
            '/var/log/apache2/error.log',
            '/var/log/nginx/error.log',
            '/var/log/php_errors.log',
            ini_get('error_log'),
        ];

        $found_logs = false;
        foreach ($log_locations as $log_file) {
            if (file_exists($log_file)) {
                $found_logs = true;
                $this->readAndDisplayLogs($log_file);
            }
        }

        if (!$found_logs) {
            echo '<p style="color: red;">❌ No se encontraron archivos de log en las ubicaciones comunes.</p>';
            echo '<p>Revisa la configuración de tu servidor o contacta a tu hosting.</p>';
        }
    }

    private function readAndDisplayLogs(string $log_file): void
    {
        echo "<h4>📄 $log_file</h4>";
        
        try {
            $lines = file($log_file);
            $recent_lines = array_slice($lines, -50); // Últimas 50 líneas
            
            $deposit_logs = [];
            foreach ($recent_lines as $line) {
                if (strpos($line, '[ZS DEPOSITS DEBUG]') !== false) {
                    $deposit_logs[] = htmlspecialchars(trim($line));
                }
            }
            
            if (empty($deposit_logs)) {
                echo '<p style="color: #666;">No hay logs de depósitos recientes.</p>';
            } else {
                foreach ($deposit_logs as $log) {
                    echo '<div style="border-bottom: 1px solid #eee; padding: 3px 0;">' . $log . '</div>';
                }
            }
        } catch (Exception $e) {
            echo '<p style="color: red;">Error leyendo el archivo: ' . $e->getMessage() . '</p>';
        }
    }
}
