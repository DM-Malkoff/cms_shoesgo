<?php

class WC_Admin_Similar_Products {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_recalculate'));
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
    
    public function handle_recalculate() {
        if (
            isset($_POST['wc_recalculate_similarities']) &&
            isset($_POST['_wpnonce']) &&
            wp_verify_nonce($_POST['_wpnonce'], 'wc_recalculate_similarities')
        ) {
            try {
                error_log("Starting similarity recalculation");
                
                // Увеличиваем лимит времени выполнения
                if (!ini_get('safe_mode')) {
                    set_time_limit(600); // 10 минут
                }
                
                // Увеличиваем лимит памяти
                if (function_exists('wp_raise_memory_limit')) {
                    wp_raise_memory_limit('admin');
                }
                
                $similarity = WC_Product_Similarity::get_instance();
                if (!$similarity) {
                    throw new Exception("Failed to initialize WC_Product_Similarity");
                }
                
                $result = $similarity->recalculate_all_similarities();
                
                if ($result === true) {
                    WC_Admin_Notices::add_notice(
                        'Похожие товары были успешно пересчитаны.',
                        'success'
                    );
                }
                
            } catch (Exception $e) {
                error_log("Error during similarity recalculation: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                
                WC_Admin_Notices::add_notice(
                    'Произошла ошибка при пересчете похожих товаров: ' . esc_html($e->getMessage()),
                    'error'
                );
            }
        }
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
        <div class="wrap">
            <h1>Похожие товары</h1>
            
            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Статистика</h2>
                <table class="wp-list-table widefat fixed striped" style="width: auto; min-width: 500px;">
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
                    <h3 style="margin-top: 20px;">Последние обработанные товары</h3>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
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
                <?php endif; ?>
            </div>
            
            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Пересчет похожих товаров</h2>
                <form method="post">
                    <?php wp_nonce_field('wc_recalculate_similarities'); ?>
                    <p>Нажмите кнопку ниже, чтобы пересчитать похожие товары для всех товаров в вашем магазине.</p>
                    <p>Новый алгоритм будет:</p>
                    <ul style="list-style-type: disc; margin-left: 2em;">
                        <li>Находить до 12 похожих товаров для каждого товара</li>
                        <li>Сначала искать товары из той же категории</li>
                        <li>Если товаров недостаточно, искать в родительских категориях</li>
                        <li>Если все еще недостаточно, добавлять случайные товары из каталога</li>
                    </ul>
                    <p>
                        <button type="submit" name="wc_recalculate_similarities" class="button button-primary">
                            Пересчитать похожие товары
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
} 