<?php
namespace ZeroSense\Features\Integrations\WPML;

use WC_Order;

/**
 * Admin helpers to manage WPML order language tools.
 */
class OrderLanguageAdmin
{
    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'renderOrderLanguageField'], 20);
        
        // Classic WooCommerce hooks
        add_filter('manage_edit-shop_order_columns', [$this, 'injectLanguageColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderLanguageColumn'], 10, 2);
        add_filter('manage_edit-shop_order_sortable_columns', [$this, 'makeLanguageColumnSortable']);
        add_action('restrict_manage_posts', [$this, 'renderLanguageFilter'], 20);
        add_filter('request', [$this, 'handleLanguageFilter']);
        
        // HPOS hooks
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'injectLanguageColumn']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderLanguageColumnHpos'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_sortable_columns', [$this, 'makeLanguageColumnSortable']);
        add_action('woocommerce_shop_order_list_table_restrict_manage_orders', [$this, 'renderLanguageFilterHpos'], 20);
        add_filter('woocommerce_shop_order_list_table_query_args', [$this, 'handleLanguageFilterHpos']);
        
        add_action('woocommerce_process_shop_order_meta', [$this, 'persistLanguageMeta'], 10, 2);

        add_action('wp_ajax_zs_wpml_update_order_language', [$this, 'handleAjaxLanguageUpdate']);
        add_action('wp_ajax_zs_update_order_language', [$this, 'handleAjaxLanguageUpdate']);
    }

    public function renderOrderLanguageField($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $currentLanguage = $order->get_meta('wpml_language', true);
        $languages = $this->getAvailableLanguages();

        if (empty($currentLanguage)) {
            $currentLanguage = apply_filters('wpml_current_language', null);
        }

        wp_nonce_field('wpml_language_save', 'wpml_language_nonce');

        $currentLanguageName = $languages[$currentLanguage] ?? $currentLanguage;
        ?>
        <div class="form-field form-field-wide zs-wpml-language">
            <h4 style="margin-bottom: 0.5em;">
                <span><?php esc_html_e('Order Language', 'zero-sense'); ?></span>
                <?php if (defined('ICL_SITEPRESS_VERSION')) : ?>
                    <span class="dashicons dashicons-translation" style="color: #007cba; vertical-align: middle;" title="<?php esc_attr_e('WPML is active', 'zero-sense'); ?>"></span>
                <?php endif; ?>
            </h4>

            <div class="wpml-language-display zs-mb-field-inline">
                <span class="wpml-language-current">
                    <?php if ($currentLanguage) : ?>
                        <span class="zs-badge zs-badge-auto wpml-language-tag">
                            <?php echo esc_html(strtoupper($currentLanguage)); ?>
                        </span>
                        <?php echo esc_html($currentLanguageName); ?>
                    <?php else : ?>
                        <em><?php esc_html_e('Not set', 'zero-sense'); ?></em>
                    <?php endif; ?>
                </span>
                <a href="#" class="zs-mb-link wpml-language-edit">
                    <?php esc_html_e('Change', 'zero-sense'); ?>
                </a>
            </div>

            <div class="wpml-language-selector" style="display: none;">
                <select name="wpml_language" id="wpml_language" class="wc-enhanced-select" style="width: 100%;">
                    <?php foreach ($languages as $code => $name) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($currentLanguage, $code); ?>>
                            <?php echo esc_html($name); ?> (<?php echo esc_html(strtoupper($code)); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="wpml-language-actions zs-mb-row-actions">
                    <button type="button" class="zs-btn is-neutral wpml-language-cancel"><?php esc_html_e('Cancel', 'zero-sense'); ?></button>
                    <button type="button" class="zs-btn is-primary wpml-language-save"><?php esc_html_e('Update Language', 'zero-sense'); ?></button>
                </div>
                <p class="description" style="margin-top: 0.5em;">
                    <?php esc_html_e('The language affects payment URLs and customer communications.', 'zero-sense'); ?>
                </p>
            </div>
        </div>
        
        <?php
        // Event Information Sheet Button
        $orderId = $order->get_id();
        if ($orderId > 0) {
            // Get base URL dynamically based on current site
            $siteUrl = home_url();
            $baseUrl = '';
            
            if (strpos($siteUrl, 'dev.paellasencasa.com') !== false) {
                $baseUrl = 'https://dev.paellasencasa.com/fdr';
            } elseif (strpos($siteUrl, 'localhost') !== false || strpos($siteUrl, '127.0.0.1') !== false) {
                $baseUrl = home_url('/fdr');
            } else {
                $baseUrl = 'https://paellasencasa.com/fdr';
            }
            
            $eventLink = do_shortcode('[zs_event_public_link order="' . $orderId . '" base_url="' . $baseUrl . '"]');
            if (!empty($eventLink)) {
                ?>
                <div class="form-field form-field-wide" style="margin-top: 1em;">
                    <h4 style="margin-bottom: 0.5em;">
                        <?php esc_html_e('Event Information Sheet', 'zero-sense'); ?>
                    </h4>
                    <a 
                        href="<?php echo esc_url($eventLink); ?>" 
                        target="_blank"
                        class="zs-btn is-neutral"
                    >
                        <?php esc_html_e('Open Event Sheet', 'zero-sense'); ?>
                    </a>
                </div>
                <?php
            }
        }
        ?>
        
        <script type="text/javascript">
            jQuery(function($) {
                const $container = $('.zs-wpml-language');
                $container.on('click', '.wpml-language-edit', function(e) {
                    e.preventDefault();
                    $container.find('.wpml-language-display').hide();
                    $container.find('.wpml-language-selector').show();
                });

                $container.on('click', '.wpml-language-cancel', function() {
                    $container.find('.wpml-language-selector').hide();
                    $container.find('.wpml-language-display').show();
                });

                $container.on('click', '.wpml-language-save', function() {
                    var selectedCode = $('#wpml_language').val();
                    var $hidden = $('#zs_wpml_language_field');

                    if (!$hidden.length) {
                        $hidden = $('<input>', { type: 'hidden', id: 'zs_wpml_language_field', name: 'wpml_language' });
                        $('form#post').append($hidden);
                    }

                    $hidden.val(selectedCode);

                    var notice = $(
                        '<div class="notice notice-info"><p>' +
                        '<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' +
                        '<?php echo esc_js(__('Updating order language and saving changes...', 'zero-sense')); ?>' +
                        '</p></div>'
                    );
                    $container.prepend(notice);

                    $('form#post').submit();
                });
            });
        </script>
        <?php
    }

    public function injectLanguageColumn(array $columns): array
    {
        $newColumns = [];

        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            if ($key === 'order_number') {
                $newColumns['order_language'] = __('Language', 'zero-sense');
            }
        }

        return $newColumns;
    }

    public function renderLanguageColumn(string $column, int $postId): void
    {
        if ($column !== 'order_language') {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $language = $order->get_meta('wpml_language', true);
        if (empty($language)) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $languages = $this->getAvailableLanguages();
        $languageName = $languages[$language] ?? $language;
        echo '<span class="wpml-language-tag" style="background: #f0f0f1; padding: 3px 5px; border-radius: 3px; display: inline-block; margin-right: 0.3em;">' . esc_html(strtoupper($language)) . '</span>';
        echo esc_html($languageName);
    }

    public function renderLanguageColumnHpos(string $column, $order): void
    {
        if ($column !== 'order_language') {
            return;
        }

        if (!$order instanceof WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $language = $order->get_meta('wpml_language', true);
        if (empty($language)) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $languages = $this->getAvailableLanguages();
        $languageName = $languages[$language] ?? $language;
        echo '<span class="wpml-language-tag" style="background: #f0f0f1; padding: 3px 5px; border-radius: 3px; display: inline-block; margin-right: 0.3em;">' . esc_html(strtoupper($language)) . '</span>';
        echo esc_html($languageName);
    }

    public function makeLanguageColumnSortable(array $columns): array
    {
        $columns['order_language'] = 'wpml_language';
        return $columns;
    }

    public function renderLanguageFilter(): void
    {
        global $typenow;

        if ($typenow !== 'shop_order') {
            return;
        }

        $languages = $this->getAvailableLanguages();
        $currentLanguage = isset($_GET['wpml_language']) ? sanitize_text_field(wp_unslash($_GET['wpml_language'])) : '';
        ?>
        <select name="wpml_language" class="wpml-language-filter">
            <option value=""><?php esc_html_e('All languages', 'zero-sense'); ?></option>
            <?php foreach ($languages as $code => $name) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($currentLanguage, $code); ?>>
                    <?php echo esc_html($name); ?> (<?php echo esc_html(strtoupper($code)); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function renderLanguageFilterHpos(): void
    {
        $languages = $this->getAvailableLanguages();
        $currentLanguage = isset($_GET['wpml_language']) ? sanitize_text_field(wp_unslash($_GET['wpml_language'])) : '';
        ?>
        <select name="wpml_language" class="wpml-language-filter">
            <option value=""><?php esc_html_e('All languages', 'zero-sense'); ?></option>
            <?php foreach ($languages as $code => $name) : ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($currentLanguage, $code); ?>>
                    <?php echo esc_html($name); ?> (<?php echo esc_html(strtoupper($code)); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function handleLanguageFilter(array $vars): array
    {
        global $typenow;

        if ($typenow === 'shop_order' && !empty($_GET['wpml_language'])) {
            $vars['meta_key'] = 'wpml_language';
            $vars['meta_value'] = sanitize_text_field(wp_unslash($_GET['wpml_language']));
        }

        return $vars;
    }

    public function handleLanguageFilterHpos(array $args): array
    {
        if (!empty($_GET['wpml_language'])) {
            $language = sanitize_text_field(wp_unslash($_GET['wpml_language']));
            $meta_query = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
            $meta_query[] = [
                'key' => 'wpml_language',
                'value' => $language,
                'compare' => '='
            ];
            $args['meta_query'] = $meta_query;
        }

        return $args;
    }

    public function handleAjaxLanguageUpdate(): void
    {
        check_ajax_referer('wpml_language_save', 'security');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(__('You do not have permission to edit orders', 'zero-sense'));
        }

        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $language = isset($_POST['language']) ? sanitize_text_field(wp_unslash($_POST['language'])) : '';

        if (!$orderId || $language === '') {
            wp_send_json_error(__('Invalid order ID or language', 'zero-sense'));
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(__('Order not found', 'zero-sense'));
        }

        global $wpdb;
        $languages = $this->getAvailableLanguages();
        $languageName = $languages[$language] ?? $language;
        $previousLanguage = $order->get_meta('wpml_language', true);
        $previousLanguageName = $previousLanguage ? ($languages[$previousLanguage] ?? $previousLanguage) : __('Not set', 'zero-sense');

        $order->update_meta_data('wpml_language', $language);

        $metaId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                $orderId,
                'wpml_language'
            )
        );

        if ($metaId) {
            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => $language],
                ['meta_id' => $metaId],
                ['%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $wpdb->postmeta,
                [
                    'post_id' => $orderId,
                    'meta_key' => 'wpml_language',
                    'meta_value' => $language,
                ],
                ['%d', '%s', '%s']
            );
        }

        if ($previousLanguage !== $language) {
            $note = sprintf(
                __('Order language changed from %1$s to %2$s', 'zero-sense'),
                $previousLanguageName,
                $languageName
            );
            $order->add_order_note($note, false);
        }

        $order->save();

        $savedLanguage = get_post_meta($orderId, 'wpml_language', true);
        if ($savedLanguage !== $language) {
            update_post_meta($orderId, 'wpml_language', $language);
        }

        $this->recalculateLanguageDependentData($order, $language);

        wp_send_json_success(sprintf(__('Order language updated to %s', 'zero-sense'), $languageName));
    }

    public function persistLanguageMeta(int $orderId, $post): void
    {
        if (!isset($_POST['wpml_language_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpml_language_nonce'])), 'wpml_language_save')) {
            return;
        }

        if (!current_user_can('edit_post', $orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['wpml_language'])) {
            $language = sanitize_text_field(wp_unslash($_POST['wpml_language']));
            $order->update_meta_data('wpml_language', $language);
            $order->save();
        }
    }

    private function recalculateLanguageDependentData(WC_Order $order, string $language): void
    {
        if (!function_exists('apply_filters') || !function_exists('do_action')) {
            return;
        }

        $currentLanguage = apply_filters('wpml_current_language', null);
        do_action('wpml_switch_language', $language);

        $flowmatticExtension = 'Zero_Sense\\Integrations\\Flowmattic\\WC_API_Extension';
        if (class_exists($flowmatticExtension)) {
            $orderData = [];
            $orderData = $flowmatticExtension::add_multilingual_payment_urls($orderData, $order);
            if (isset($orderData['payment_url_' . $language])) {
                $order->update_meta_data('_payment_url', $orderData['payment_url_' . $language]);
                $order->save();
            }
        }

        do_action('zero_sense_order_language_updated', $order, $language);

        do_action('wpml_switch_language', $currentLanguage);
    }

    private function getAvailableLanguages(): array
    {
        $languages = [];

        if (function_exists('icl_get_languages')) {
            $wpmlLanguages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

            if (!empty($wpmlLanguages)) {
                foreach ($wpmlLanguages as $code => $language) {
                    $languages[$code] = $language['translated_name'] ?? $language['native_name'] ?? $code;
                }
            }
        }

        if (empty($languages)) {
            $languages = [
                'en' => __('English', 'zero-sense'),
                'es' => __('Spanish', 'zero-sense'),
                'ca' => __('Catalan', 'zero-sense'),
            ];
        }

        return $languages;
    }
}
