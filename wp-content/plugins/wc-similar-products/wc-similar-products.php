<?php
/**
 * Plugin Name: WooCommerce Similar Products
 * Plugin URI: 
 * Description: Adds similar products functionality to WooCommerce
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: 
 * Text Domain: wc-similar-products
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Проверяем, что WooCommerce активирован
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Создание таблицы при активации
function wc_similar_products_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'product_similarities';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        product_id bigint(20) UNSIGNED NOT NULL,
        similar_product_id bigint(20) UNSIGNED NOT NULL,
        similarity_score float NOT NULL,
        PRIMARY KEY  (product_id, similar_product_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'wc_similar_products_activate');

// Инициализация плагина
function wc_similar_products_init() {
    // Проверяем наличие WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>WC Similar Products: WooCommerce не найден или не активирован.</p></div>';
        });
        return;
    }
    
    // Подключаем основные классы
    $includes_path = plugin_dir_path(__FILE__) . 'includes/';
    
    if (!file_exists($includes_path . 'class-wc-product-similarity.php')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>WC Similar Products: Файл class-wc-product-similarity.php не найден.</p></div>';
        });
        return;
    }
    
    require_once $includes_path . 'class-wc-product-similarity.php';
    require_once $includes_path . 'class-wc-rest-product-similar-controller.php';
    require_once $includes_path . 'class-wc-admin-similar-products.php';

    // Инициализируем классы
    WC_Product_Similarity::get_instance();
    new WC_REST_Product_Similar_Controller();
    new WC_Admin_Similar_Products();
}

// Используем более поздний приоритет, чтобы убедиться, что WooCommerce уже загружен
add_action('plugins_loaded', 'wc_similar_products_init', 20); 

// Функция для отладки (можно временно активировать)
function wc_similar_products_debug_info() {
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-info"><p>';
        echo 'WC Similar Products Debug: ';
        echo 'WooCommerce класс существует: ' . (class_exists('WooCommerce') ? 'Да' : 'Нет') . ', ';
        echo 'Плагин активен: ' . (is_plugin_active(plugin_basename(__FILE__)) ? 'Да' : 'Нет');
        echo '</p></div>';
    }
}
// Раскомментируйте следующую строку для отладки:
// add_action('admin_notices', 'wc_similar_products_debug_info'); 