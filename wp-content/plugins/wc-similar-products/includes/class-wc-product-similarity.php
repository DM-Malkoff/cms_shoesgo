<?php

class WC_Product_Similarity {
    
    private static $instance = null;
    private $batch_size = 20; // Увеличиваем размер пакета
    private $max_similar_products = 12; // Количество похожих товаров из каждой категории
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Обновляем похожие товары при сохранении товара
        add_action('woocommerce_update_product', array($this, 'update_product_similarities'), 10, 1);
        add_action('woocommerce_create_product', array($this, 'update_product_similarities'), 10, 1);
    }
    
    private function ensure_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'product_similarities';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                product_id bigint(20) UNSIGNED NOT NULL,
                similar_product_id bigint(20) UNSIGNED NOT NULL,
                similarity_score float NOT NULL,
                PRIMARY KEY  (product_id, similar_product_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            if ($wpdb->last_error) {
                error_log("Error creating similarities table: " . $wpdb->last_error);
                throw new Exception("Failed to create similarities table: " . $wpdb->last_error);
            }
        }
    }
    
    public function update_product_similarities($product_id) {
        try {
            global $wpdb;
            
            $this->ensure_table_exists();
            
            error_log("Starting update_product_similarities for product ID: " . $product_id);
            
            $current_product = wc_get_product($product_id);
            if (!$current_product) {
                error_log("Product not found: " . $product_id);
                return;
            }
            
                    // Получаем категории текущего товара
        $current_categories = $current_product->get_category_ids();
        if (empty($current_categories)) {
            error_log("No categories found for product: " . $product_id . ". Will find random similar products.");
            // Не возвращаемся, а продолжаем - найдем случайные товары
        }
            
            // Получаем родительские категории
            $parent_categories = array();
            foreach ($current_categories as $cat_id) {
                $category = get_term($cat_id, 'product_cat');
                if ($category && $category->parent) {
                    $parent_categories[] = $category->parent;
                }
            }
            
            // Удаляем старые записи
            $table_name = $wpdb->prefix . 'product_similarities';
            $wpdb->delete($table_name, array('product_id' => $product_id));
            
                    $similar_products = array();
        
        // Получаем товары из текущих категорий (если они есть)
        if (!empty($current_categories)) {
            foreach ($current_categories as $category_id) {
                $products_in_category = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT p.ID
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.term_id = %d
                    AND p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND p.ID != %d
                    ORDER BY RAND()
                    LIMIT %d
                ", $category_id, $product_id, $this->max_similar_products));
                
                if ($products_in_category) {
                    foreach ($products_in_category as $similar_id) {
                        $similar_products[$similar_id] = array(
                            'product_id' => $similar_id,
                            'score' => 1.0, // Максимальный score для товаров из той же категории
                            'added' => false
                        );
                    }
                }
            }
        }
            
            // Если товаров недостаточно, добавляем из родительских категорий
            if (count($similar_products) < $this->max_similar_products && !empty($parent_categories)) {
                foreach ($parent_categories as $parent_id) {
                    $needed_products = $this->max_similar_products - count($similar_products);
                    if ($needed_products <= 0) break;
                    
                    $exclude_ids = array_keys($similar_products);
                    $exclude_ids[] = $product_id;
                    
                    $products_in_parent = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT p.ID
                        FROM {$wpdb->posts} p
                        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        WHERE tt.term_id = %d
                        AND p.post_type = 'product'
                        AND p.post_status = 'publish'
                        AND p.ID NOT IN (" . implode(',', array_map('intval', $exclude_ids)) . ")
                        ORDER BY RAND()
                        LIMIT %d",
                        $parent_id,
                        $needed_products
                    ));
                    
                    if ($products_in_parent) {
                        foreach ($products_in_parent as $similar_id) {
                            $similar_products[$similar_id] = array(
                                'product_id' => $similar_id,
                                'score' => 0.8, // Меньший score для товаров из родительской категории
                                'added' => false
                            );
                        }
                    }
                }
            }
            
            // Если все еще недостаточно товаров, берем случайные товары из всего каталога
            if (count($similar_products) < $this->max_similar_products) {
                $needed_products = $this->max_similar_products - count($similar_products);
                
                $exclude_ids = array_keys($similar_products);
                $exclude_ids[] = $product_id;
                
                $random_products = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID
                    FROM {$wpdb->posts}
                    WHERE post_type = 'product'
                    AND post_status = 'publish'
                    AND ID NOT IN (" . implode(',', array_map('intval', $exclude_ids)) . ")
                    ORDER BY RAND()
                    LIMIT %d",
                    $needed_products
                ));
                
                if ($random_products) {
                    foreach ($random_products as $similar_id) {
                        $similar_products[$similar_id] = array(
                            'product_id' => $similar_id,
                            'score' => 0.5, // Минимальный score для случайных товаров
                            'added' => false
                        );
                    }
                }
            }
            
                    // Сохраняем результаты
        if (!empty($similar_products)) {
            $saved_count = 0;
            foreach ($similar_products as $similar) {
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'product_id' => $product_id,
                        'similar_product_id' => $similar['product_id'],
                        'similarity_score' => $similar['score']
                    ),
                    array('%d', '%d', '%f')
                );
                
                if ($result === false) {
                    error_log("Error inserting similarity record: " . $wpdb->last_error);
                } else {
                    $saved_count++;
                }
            }
            error_log("Saved {$saved_count} similar products for product ID: " . $product_id);
        } else {
            error_log("WARNING: No similar products found for product ID: " . $product_id . ". This product will appear in 'products without similar' list.");
            // Создаем хотя бы одну запись с низким score, чтобы товар не оставался полностью без связей
            $fallback_products = $wpdb->get_col($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'product'
                AND post_status = 'publish'
                AND ID != %d
                ORDER BY RAND()
                LIMIT 1
            ", $product_id));
            
            if (!empty($fallback_products)) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'product_id' => $product_id,
                        'similar_product_id' => $fallback_products[0],
                        'similarity_score' => 0.1
                    ),
                    array('%d', '%d', '%f')
                );
                error_log("Created fallback similarity for product ID: " . $product_id);
            }
        }
        
        error_log("Completed update_product_similarities for product ID: " . $product_id);
        return true;
            
        } catch (Exception $e) {
            error_log("Error in update_product_similarities: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    public function get_similar_products($product_id, $limit = 5) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'product_similarities';
        
        $similar_products = $wpdb->get_results($wpdb->prepare(
            "SELECT similar_product_id, similarity_score 
            FROM $table_name 
            WHERE product_id = %d 
            ORDER BY similarity_score DESC 
            LIMIT %d",
            $product_id,
            $limit
        ));
        
        $products = array();
        foreach ($similar_products as $similar) {
            $product = wc_get_product($similar->similar_product_id);
            if ($product && $product->is_visible()) {
                // Получаем ID изображения товара
                $image_id = $product->get_image_id();
                
                // Получаем URL изображения в нужном размере
                $image_url = '';
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
                }
                
                // Если изображения нет, используем заглушку
                if (!$image_url) {
                    $image_url = wc_placeholder_img_src('woocommerce_thumbnail');
                }
                
                $products[] = array(
                    'product' => $product,
                    'similarity_score' => $similar->similarity_score,
                    'images' => array(
                        array(
                            'src' => $image_url
                        )
                    )
                );
            }
        }
        
        return $products;
    }
    
    public function recalculate_all_similarities() {
        try {
            global $wpdb;
            
            error_log("Starting recalculate_all_similarities");
            
            // Проверяем существование таблицы
            $this->ensure_table_exists();
            
            $table_name = $wpdb->prefix . 'product_similarities';
            
            // Очищаем таблицу перед пересчетом
            error_log("Truncating similarities table");
            $truncate_result = $wpdb->query("TRUNCATE TABLE {$table_name}");
            if ($truncate_result === false) {
                throw new Exception("Failed to truncate table: " . $wpdb->last_error);
            }
            
            // Получаем все ID товаров
            error_log("Getting all product IDs");
            $product_ids = $wpdb->get_col("
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish'
            ");
            
            if ($wpdb->last_error) {
                throw new Exception("Error getting product IDs: " . $wpdb->last_error);
            }
            
            if (empty($product_ids)) {
                error_log("No products found to process");
                return;
            }
            
            error_log("Found " . count($product_ids) . " products to process");
            
            // Обрабатываем товары пакетами
            $total_products = count($product_ids);
            $processed = 0;
            $errors = array();
            
            for ($i = 0; $i < $total_products; $i += $this->batch_size) {
                $batch = array_slice($product_ids, $i, $this->batch_size);
                error_log("Processing batch " . ($i / $this->batch_size + 1) . " of " . ceil($total_products / $this->batch_size));
                
                foreach ($batch as $product_id) {
                    try {
                        error_log("Processing product ID: " . $product_id);
                        
                        // Проверяем существование товара
                        $product = wc_get_product($product_id);
                        if (!$product) {
                            error_log("Product not found: " . $product_id);
                            continue;
                        }
                        
                        // Проверяем категории товара
                        $categories = $product->get_category_ids();
                        if (empty($categories)) {
                            error_log("No categories found for product: " . $product_id);
                            continue;
                        }
                        
                        $this->update_product_similarities($product_id);
                        $processed++;
                        
                        // Очищаем кэш WooCommerce и WordPress
                        if ($processed % 10 === 0) {
                            error_log("Processed {$processed} of {$total_products} products");
                            WC_Cache_Helper::get_transient_version('product', true);
                            wp_cache_flush();
                        }
                        
                        // Освобождаем память
                        unset($product);
                        
                    } catch (Exception $e) {
                        $error_message = "Error processing product {$product_id}: " . $e->getMessage();
                        error_log($error_message);
                        $errors[] = $error_message;
                        continue;
                    }
                }
                
                // Принудительно очищаем память после каждого пакета
                $this->clean_up_memory();
                error_log("Memory usage after batch: " . memory_get_usage(true) / 1024 / 1024 . "MB");
            }
            
            error_log("Recalculation completed. Processed {$processed} products with " . count($errors) . " errors");
            
            if (!empty($errors)) {
                throw new Exception("Completed with errors: " . implode("; ", array_slice($errors, 0, 3)) . 
                    (count($errors) > 3 ? "... and " . (count($errors) - 3) . " more errors" : ""));
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Critical error in recalculate_all_similarities: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    private function process_single_product($product_id, $all_product_ids) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'product_similarities';
        
        $current_product = wc_get_product($product_id);
        if (!$current_product) {
            return;
        }
        
        // Получаем данные текущего товара
        $current_data = $this->get_product_comparison_data($current_product);
        
        $similarities = array();
        
        // Обрабатываем другие товары пакетами
        $batch_size = 50;
        for ($i = 0; $i < count($all_product_ids); $i += $batch_size) {
            $batch_ids = array_slice($all_product_ids, $i, $batch_size);
            
            foreach ($batch_ids as $compare_id) {
                if ($compare_id === $product_id) {
                    continue;
                }
                
                $compare_product = wc_get_product($compare_id);
                if (!$compare_product) {
                    continue;
                }
                
                $compare_data = $this->get_product_comparison_data($compare_product);
                $score = $this->calculate_similarity_score($current_data, $compare_data);
                
                if ($score > 0) {
                    $wpdb->insert(
                        $table_name,
                        array(
                            'product_id' => $product_id,
                            'similar_product_id' => $compare_id,
                            'similarity_score' => $score
                        ),
                        array('%d', '%d', '%f')
                    );
                }
                
                // Очищаем объект товара из памяти
                unset($compare_product);
            }
            
            // Очищаем память после каждого пакета сравнений
            $this->clean_up_memory();
        }
        
        // Очищаем объект текущего товара из памяти
        unset($current_product);
    }
    
    private function get_product_comparison_data($product) {
        return array(
            'categories' => $product->get_category_ids(),
            'price' => (float)$product->get_price(),
            'attributes' => $product->get_attributes()
        );
    }
    
    private function calculate_similarity_score($data1, $data2) {
        $score = 0;
        
        // Сравнение категорий (40%)
        if (!empty($data1['categories']) && !empty($data2['categories'])) {
            $common_categories = array_intersect($data1['categories'], $data2['categories']);
            $category_score = count($common_categories) / 
                max(count($data1['categories']), count($data2['categories']));
            $score += $category_score * 0.4;
        }
        
        // Сравнение цен (25%)
        if ($data1['price'] > 0 && $data2['price'] > 0) {
            $price_diff = abs($data1['price'] - $data2['price']) / max($data1['price'], $data2['price']);
            $price_score = 1 - min($price_diff, 1);
            $score += $price_score * 0.25;
        }
        
        // Сравнение атрибутов (35%)
        if (!empty($data1['attributes']) && !empty($data2['attributes'])) {
            $common_attributes = array_intersect($data1['attributes'], $data2['attributes']);
            $attribute_score = count($common_attributes) / 
                max(count($data1['attributes']), count($data2['attributes']));
            $score += $attribute_score * 0.35;
        }
        
        return $score;
    }
    
    private function clean_up_memory() {
        global $wpdb;
        
        // Очищаем кэш запросов WordPress
        $wpdb->queries = array();
        
        // Очищаем внутренний кэш WooCommerce
        WC_Cache_Helper::get_transient_version('product', true);
        
        // Очищаем кэш объектов WordPress
        wp_cache_flush();
        
        // Принудительно очищаем неиспользуемую память
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
} 