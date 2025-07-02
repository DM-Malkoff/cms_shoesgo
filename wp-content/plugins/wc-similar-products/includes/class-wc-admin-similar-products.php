<?php

class WC_Admin_Similar_Products {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_recalculate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_recalculate_similarities_batch', array($this, 'handle_ajax_recalculate_batch'));
        add_action('wp_ajax_get_category_stats', array($this, 'handle_ajax_category_stats'));
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
            'error_text' => 'Произошла ошибка при пересчете',
            'stats_nonce' => wp_create_nonce('wc_category_stats')
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
        
        // Получаем параметры фильтрации
        $processing_mode = isset($_POST['processing_mode']) ? sanitize_text_field($_POST['processing_mode']) : 'all';
        $selected_categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        
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
            
            // Строим SQL запросы в зависимости от режима обработки
            $where_conditions = array("p.post_type = 'product'", "p.post_status = 'publish'");
            $join_clauses = array();
            
            // Добавляем условия для категорий
            if (($processing_mode === 'categories' || $processing_mode === 'categories_new') && !empty($selected_categories)) {
                $join_clauses[] = "JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
                $join_clauses[] = "JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
                $where_conditions[] = "tt.term_id IN (" . implode(',', $selected_categories) . ")";
            }
            
            // Добавляем условие для товаров без похожих
            if ($processing_mode === 'new' || $processing_mode === 'categories_new') {
                $table_name = $wpdb->prefix . 'product_similarities';
                $join_clauses[] = "LEFT JOIN {$table_name} ps ON p.ID = ps.product_id";
                $where_conditions[] = "ps.product_id IS NULL";
            }
            
            $join_sql = implode(' ', array_unique($join_clauses));
            $where_sql = implode(' AND ', $where_conditions);
            
            // Получаем общее количество товаров
            $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql}";
            $total_products = $wpdb->get_var($count_sql);
            
            // Получаем товары для текущего батча
            $offset = $batch_number * $batch_size;
            $products_sql = $wpdb->prepare("
                SELECT DISTINCT p.ID, p.post_title 
                FROM {$wpdb->posts} p {$join_sql} 
                WHERE {$where_sql}
                ORDER BY p.ID
                LIMIT %d OFFSET %d
            ", $batch_size, $offset);
            $products = $wpdb->get_results($products_sql);
            
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
    
    public function handle_ajax_category_stats() {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_category_stats')) {
            wp_die('Security check failed');
        }
        
        // Проверяем права доступа
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Access denied');
        }
        
        $processing_mode = isset($_POST['processing_mode']) ? sanitize_text_field($_POST['processing_mode']) : 'all';
        $selected_categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        
        global $wpdb;
        
        // Строим SQL запрос аналогично основному методу
        $where_conditions = array("p.post_type = 'product'", "p.post_status = 'publish'");
        $join_clauses = array();
        
        // Добавляем условия для категорий
        if (($processing_mode === 'categories' || $processing_mode === 'categories_new') && !empty($selected_categories)) {
            $join_clauses[] = "JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
            $join_clauses[] = "JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where_conditions[] = "tt.term_id IN (" . implode(',', $selected_categories) . ")";
        }
        
        // Добавляем условие для товаров без похожих
        if ($processing_mode === 'new' || $processing_mode === 'categories_new') {
            $table_name = $wpdb->prefix . 'product_similarities';
            $join_clauses[] = "LEFT JOIN {$table_name} ps ON p.ID = ps.product_id";
            $where_conditions[] = "ps.product_id IS NULL";
        }
        
        $join_sql = implode(' ', array_unique($join_clauses));
        $where_sql = implode(' AND ', $where_conditions);
        
        // Получаем количество товаров
        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql}";
        $total_products = $wpdb->get_var($count_sql);
        
        wp_send_json_success(array(
            'total_products' => intval($total_products),
            'processing_mode' => $processing_mode,
            'selected_categories' => count($selected_categories)
        ));
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
                
                <div style="margin: 20px 0;">
                    <h3>Настройки обработки</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="processing-mode">Режим обработки:</label></th>
                            <td>
                                <select id="processing-mode" style="min-width: 200px;">
                                    <option value="all">Все товары</option>
                                    <option value="categories">Только выбранные категории</option>
                                    <option value="new">Только товары без похожих товаров</option>
                                    <option value="categories_new">Выбранные категории + только новые</option>
                                </select>
                                <p class="description">Выберите какие товары обрабатывать</p>
                            </td>
                        </tr>
                        <tr id="categories-row" style="display: none;">
                            <th scope="row"><label for="product-categories">Категории товаров:</label></th>
                            <td>
                                <select id="product-categories" multiple style="width: 100%; height: 120px;">
                                    <?php
                                    $categories = get_terms(array(
                                        'taxonomy' => 'product_cat',
                                        'hide_empty' => false,
                                        'orderby' => 'name',
                                        'order' => 'ASC'
                                    ));
                                    
                                    if (!empty($categories) && !is_wp_error($categories)) {
                                        foreach ($categories as $category) {
                                            $product_count = $wpdb->get_var($wpdb->prepare("
                                                SELECT COUNT(DISTINCT p.ID)
                                                FROM {$wpdb->posts} p
                                                JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                                                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                                                WHERE tt.term_id = %d
                                                AND p.post_type = 'product'
                                                AND p.post_status = 'publish'
                                            ", $category->term_id));
                                            
                                            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $this->get_category_level($category->term_id));
                                            echo '<option value="' . esc_attr($category->term_id) . '">' . 
                                                 $indent . esc_html($category->name) . ' (' . $product_count . ' товаров)</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description">Выберите категории для обработки (можно выбрать несколько, удерживая Ctrl/Cmd)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p>
                    <button type="button" id="recalculate-similarities" class="button button-primary">
                        Пересчитать похожие товары
                    </button>
                    <span id="selected-info" style="margin-left: 15px; color: #666; font-style: italic;"></span>
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
    
    private function get_category_level($term_id, $level = 0) {
        $term = get_term($term_id, 'product_cat');
        if ($term && $term->parent) {
            return $this->get_category_level($term->parent, $level + 1);
        }
        return $level;
    }
} 