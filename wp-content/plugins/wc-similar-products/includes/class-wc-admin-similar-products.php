<?php

class WC_Admin_Similar_Products {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_recalculate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_recalculate_similarities_batch', array($this, 'handle_ajax_recalculate_batch'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Похожие товары',
            'Похожие товары',
            'manage_woocommerce',
            'wc-similar-products',
            array($this, 'render_admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        // Подключаем скрипты только на нашей странице
        if ($hook !== 'woocommerce_page_wc-similar-products') {
            return;
        }
        
        wp_enqueue_style(
            'wc-similar-products-admin',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'wc-similar-products-admin',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
            array('jquery'),
            '1.0.1',
            true
        );
        
        wp_localize_script('wc-similar-products-admin', 'wcSimilarProducts', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_recalculate_similarities'),
            'processing_text' => 'Обработка... %s%',
            'success_text' => 'Пересчет завершен успешно!',
            'error_text' => 'Произошла ошибка при пересчете'
        ));
    }
    
    public function handle_ajax_recalculate_batch() {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_recalculate_similarities')) {
            wp_die('Security check failed');
        }
        
        // Проверяем права доступа
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Access denied');
        }
        
        $batch_number = intval($_POST['batch']);
        $batch_size = 5; // Уменьшаем размер батча для избежания таймаутов
        
        try {
            // Увеличиваем лимиты
            if (!ini_get('safe_mode')) {
                set_time_limit(120); // 2 минуты на батч
            }
            
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('admin');
            }
            
            global $wpdb;
            
            // При первом батче очищаем таблицу
            if ($batch_number === 0) {
                $table_name = $wpdb->prefix . 'product_similarities';
                $wpdb->query("TRUNCATE TABLE {$table_name}");
            }
            
            // Получаем общее количество товаров
            $total_products = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish'
            ");
            
            // Получаем товары для текущего батча
            $offset = $batch_number * $batch_size;
            $products = $wpdb->get_results($wpdb->prepare("
                SELECT ID, post_title 
                FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish'
                ORDER BY ID
                LIMIT %d OFFSET %d
            ", $batch_size, $offset));
            
            $processed_in_batch = 0;
            $last_product = null;
            $similarity = WC_Product_Similarity::get_instance();
            
            foreach ($products as $product_row) {
                $product_id = $product_row->ID;
                $product = wc_get_product($product_id);
                
                if ($product) {
                    $similarity->update_product_similarities($product_id);
                    $processed_in_batch++;
                    
                    // Сохраняем информацию о последнем обработанном товаре
                    $last_product = array(
                        'id' => $product_id,
                        'title' => $product_row->post_title,
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'thumbnail' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
                        'edit_link' => get_edit_post_link($product_id),
                        'view_link' => get_permalink($product_id)
                    );
                }
                
                // Очищаем память
                unset($product);
            }
            
            $total_processed = $offset + $processed_in_batch;
            $percentage = $total_products > 0 ? round(($total_processed / $total_products) * 100, 1) : 100;
            $complete = $total_processed >= $total_products;
            
            wp_send_json_success(array(
                'processed' => $total_processed,
                'total' => $total_products,
                'percentage' => $percentage,
                'complete' => $complete,
                'product' => $last_product
            ));
            
        } catch (Exception $e) {
            error_log("Error in AJAX batch processing: " . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function handle_recalculate() {
        // Оставляем старый метод для совместимости, но теперь он не используется
        // Вся обработка происходит через AJAX
    }
    
    public function render_admin_page() {
        global $wpdb;
        
        // Получаем статистику
        $table_name = $wpdb->prefix . 'product_similarities';
        $total_products = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$table_name}");
        $total_relations = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $avg_similar = $total_products ? round($total_relations / $total_products, 1) : 0;
        
        // Получаем последние обновленные товары
        $recent_products = $wpdb->get_results("
            SELECT DISTINCT p.ID, p.post_title, 
                   (SELECT COUNT(*) FROM {$table_name} WHERE product_id = p.ID) as similar_count
            FROM {$wpdb->posts} p
            JOIN {$table_name} ps ON p.ID = ps.product_id
            WHERE p.post_type = 'product'
            GROUP BY p.ID
            ORDER BY p.ID DESC
            LIMIT 10
        ");
        
        ?>
        <div class="wrap wc-similar-products-admin">
            <h1>Похожие товары</h1>
            
            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Статистика</h2>
                <table class="wp-list-table widefat fixed striped wc-similar-stats-table">
                    <tr>
                        <td><strong>Всего товаров с похожими:</strong></td>
                        <td align="right"><?php echo number_format($total_products, 0, ',', ' '); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Всего связей между товарами:</strong></td>
                        <td align="right"><?php echo number_format($total_relations, 0, ',', ' '); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Среднее количество похожих на товар:</strong></td>
                        <td align="right"><?php echo $avg_similar; ?></td>
                    </tr>
                </table>
                
                <?php if (!empty($recent_products)): ?>
                    <div class="wc-similar-recent-products">
                        <h3>Последние обработанные товары</h3>
                        <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название товара</th>
                                <th style="text-align: center;">Количество похожих</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_products as $product): ?>
                                <tr>
                                    <td><?php echo esc_html($product->ID); ?></td>
                                    <td><?php echo esc_html($product->post_title); ?></td>
                                    <td align="center"><?php echo esc_html($product->similar_count); ?></td>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($product->ID); ?>" target="_blank">
                                            Редактировать
                                        </a>
                                        &nbsp;|&nbsp;
                                        <a href="<?php echo get_permalink($product->ID); ?>" target="_blank">
                                            Просмотреть
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Пересчет похожих товаров</h2>
                <p>Нажмите кнопку ниже, чтобы пересчитать похожие товары для всех товаров в вашем магазине.</p>
                <p>Новый алгоритм будет:</p>
                <ul style="list-style-type: disc; margin-left: 2em;">
                    <li>Находить до 12 похожих товаров для каждого товара</li>
                    <li>Сначала искать товары из той же категории</li>
                    <li>Если товаров недостаточно, искать в родительских категориях</li>
                    <li>Если все еще недостаточно, добавлять случайные товары из каталога</li>
                </ul>
                <p><strong>Внимание:</strong> Обработка выполняется небольшими пакетами, чтобы избежать таймаутов. Процесс может занять несколько минут в зависимости от количества товаров.</p>
                
                <p>
                    <button type="button" id="recalculate-similarities" class="button button-primary">
                        Пересчитать похожие товары
                    </button>
                </p>
                
                <div class="progress-wrapper" style="display: none; margin-top: 20px;">
                    <div class="progress-container">
                        <div class="progress"></div>
                    </div>
                    <div class="progress-status"></div>
                </div>
            </div>
        </div>
        <?php
    }
} 