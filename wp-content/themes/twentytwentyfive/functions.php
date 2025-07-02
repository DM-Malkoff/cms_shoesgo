<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

// Adds theme support for post formats.
if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

// Enqueues editor-style.css in the editors.
if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( get_parent_theme_file_uri( 'assets/css/editor-style.css' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

// Enqueues style.css on the front.
if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues style.css on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( 'style.css' ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

// Registers custom block styles.
if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

// Registers pattern categories.
if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

// Registers block binding sources.
if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_register_block_bindings' );

// Registers block binding callback function for the post format name.
if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;

/**  Регистрация REST API endpoint для получения атрибутов товаров категории */
add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/category-attributes/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'get_category_attributes',
    ]);
});

/** Получение атрибутов товаров категории */
function get_category_attributes($request) {
    $category_id = $request['id'];
    
    // Получаем категорию
    $term = get_term($category_id, 'product_cat');
    if (!$term || is_wp_error($term)) {
        return [];
    }

    // Получаем все товары категории
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_id
            )
        )
    );
    
    $query = new WP_Query($args);
    $products = $query->posts;

    $attributes = [];
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        if (!$product) continue;

        foreach ($product->get_attributes() as $attr) {
            $attr_id = $attr->is_taxonomy() ? sanitize_title($attr->get_name()) : $attr->get_id();
            
            // Получаем опции атрибута
            $options = [];
            if ($attr->is_taxonomy()) {
                $terms = $attr->get_terms();
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $options[] = [
                            'name' => $term->name,
                            'slug' => $term->slug
                        ];
                    }
                }
            } else {
                foreach ($attr->get_options() as $option) {
                    $options[] = [
                        'name' => $option,
                        'slug' => sanitize_title($option)
                    ];
                }
            }

            // Если атрибут уже есть в массиве, объединяем опции
            if (isset($attributes[$attr_id])) {
                $existing_options = $attributes[$attr_id]['options'];
                $merged_options = array_merge($existing_options, $options);
                $unique_options = array_values(array_unique($merged_options, SORT_REGULAR));
                $attributes[$attr_id]['options'] = $unique_options;
            } else {
                $attributes[$attr_id] = [
                    'id'      => $attr->get_id(),
                    'name'    => $attr->get_name(),
                    'title'   => wc_attribute_label($attr->get_name()),
                    'slug'    => $attr->is_taxonomy() ? $attr->get_name() : sanitize_title($attr->get_name()),
                    'options' => array_values(array_unique($options, SORT_REGULAR))
                ];
            }
        }
    }

    // Сортируем атрибуты по названию (title)
    usort($attributes, function($a, $b) {
        return strcmp($a['title'], $b['title']);
    });

    // Сортируем опции каждого атрибута по названию (name)
    foreach ($attributes as &$attribute) {
        usort($attribute['options'], function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    }

    return $attributes;
}

/**
 * Поиск товаров по нескольким фильтрам и атрибутам одного типа.
 *
 * @param array $filters Массив фильтров (например, категория, цена и т.д.).
 * @param array $attributes Массив атрибутов для фильтрации.
 * @return WP_Query Результаты поиска товаров.
 */
function search_products_by_filters_and_attributes($filters = array(), $attributes = array()) {
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'tax_query'      => array(), // Инициализируем tax_query
        'meta_query'     => array(), // Инициализируем meta_query
    );

    // Добавляем фильтр по категории
    if (!empty($filters) && isset($filters['category_id'])) {
        $args['tax_query'][] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => (int) $filters['category_id'], // Убедимся, что term_id является числом
            'operator' => 'IN', // Используем 'IN' для совместимости
        );
    }

    // Добавляем фильтрацию по цене
    if (!empty($filters) && (isset($filters['min_price']) || isset($filters['max_price']))) {
        $min_price = isset($filters['min_price']) ? (float) $filters['min_price'] : 0;
        $max_price = isset($filters['max_price']) ? (float) $filters['max_price'] : PHP_FLOAT_MAX;

        $args['meta_query'][] = array(
            'key'     => '_price',
            'value'   => array($min_price, $max_price),
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        );
    }

    // Добавляем фильтрацию по атрибутам (логическое ИЛИ)
    if (!empty($attributes)) {
        foreach ($attributes as $attr_name => $attr_values) {
            $args['tax_query'][] = array(
                'taxonomy' => $attr_name,
                'field'    => 'slug',
                'terms'    => $attr_values,
                'operator' => 'IN',
            );
        }
    }

    // Устанавливаем отношение 'AND' для tax_query, если есть несколько условий
    if (count($args['tax_query']) > 1) {
        $args['tax_query']['relation'] = 'AND';
    }

    // Логирование для отладки (можно удалить после проверки)
    error_log('WP_Query args: ' . print_r($args, true));

    $query = new WP_Query($args);

    // Логирование результатов запроса
    error_log('Found posts: ' . $query->found_posts);

    return $query;
}

// Регистрация REST API endpoint для поиска товаров
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/search-products', [
        'methods'  => 'POST',
        'callback' => 'handle_product_search_request',
        'permission_callback' => '__return_true', // Разрешить доступ всем (можно настроить ограничения)
    ]);
});

/**
 * Обработчик запроса поиска товаров.
 *
 * @param WP_REST_Request $request Объект запроса.
 * @return WP_REST_Response Ответ с результатами поиска.
 */
function handle_product_search_request(WP_REST_Request $request) {
    $filters = $request->get_param('filters') ?: array();
    $attributes = $request->get_param('attributes') ?: array();

    $query = search_products_by_filters_and_attributes($filters, $attributes);

    $products = array();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);

            // Получаем все изображения товара
            $attachment_ids = $product->get_gallery_image_ids();
            $images = array();
            // Добавляем основное изображение
            if ($product->get_image_id()) {
                $images[] = array(
                    'id' => $product->get_image_id(),
                    'src' => wp_get_attachment_url($product->get_image_id()),
                    'name' => get_the_title($product->get_image_id()),
                    'alt' => get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true),
                );
            }
            // Добавляем галерею изображений
            foreach ($attachment_ids as $attachment_id) {
                $images[] = array(
                    'id' => $attachment_id,
                    'src' => wp_get_attachment_url($attachment_id),
                    'name' => get_the_title($attachment_id),
                    'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                );
            }

            // Получаем все атрибуты товара
            $attributes_data = array();
            foreach ($product->get_attributes() as $attribute) {
                $attribute_data = array(
                    'id' => $attribute->get_id(),
                    'name' => $attribute->get_name(),
                    'position' => $attribute->get_position(),
                    'visible' => $attribute->get_visible(),
                    'variation' => $attribute->get_variation(),
                    'options' => array(),
                );

                // Получаем значения атрибута
                if ($attribute->is_taxonomy()) {
                    $terms = $attribute->get_terms();
                    if (!is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            $attribute_data['options'][] = array(
                                'id' => $term->term_id,
                                'name' => $term->name,
                                'slug' => $term->slug,
                            );
                        }
                    }
                } else {
                    foreach ($attribute->get_options() as $option) {
                        $attribute_data['options'][] = array(
                            'name' => $option,
                            'slug' => sanitize_title($option),
                        );
                    }
                }

                $attributes_data[] = $attribute_data;
            }

            // Формируем полную информацию о товаре
            $products[] = array(
                'id' => $product_id,
                'name' => $product->get_name(),
                'slug' => $product->get_slug(),
                'permalink' => get_permalink($product_id),
                'date_created' => $product->get_date_created()->format('c'),
                'date_modified' => $product->get_date_modified()->format('c'),
                'type' => $product->get_type(),
                'status' => $product->get_status(),
                'featured' => $product->get_featured(),
                'catalog_visibility' => $product->get_catalog_visibility(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'date_on_sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->format('c') : null,
                'date_on_sale_to' => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->format('c') : null,
                'total_sales' => $product->get_total_sales(),
                'virtual' => $product->get_virtual(),
                'downloadable' => $product->get_downloadable(),
                'downloads' => $product->get_downloads(),
                'download_limit' => $product->get_download_limit(),
                'download_expiry' => $product->get_download_expiry(),
                'tax_status' => $product->get_tax_status(),
                'tax_class' => $product->get_tax_class(),
                'manage_stock' => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
                'backorders_allowed' => $product->get_backorders() !== 'no',
                'backordered' => $product->is_on_backorder(),
                'sold_individually' => $product->get_sold_individually(),
                'weight' => $product->get_weight(),
                'dimensions' => array(
                    'length' => $product->get_length(),
                    'width' => $product->get_width(),
                    'height' => $product->get_height(),
                ),
                'shipping_required' => $product->needs_shipping(),
                'shipping_taxable' => $product->is_shipping_taxable(),
                'shipping_class' => $product->get_shipping_class(),
                'shipping_class_id' => $product->get_shipping_class_id(),
                'reviews_allowed' => $product->get_reviews_allowed(),
                'average_rating' => $product->get_average_rating(),
                'rating_count' => $product->get_rating_count(),
                'categories' => array_map(function($term) {
                    return array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }, wp_get_post_terms($product_id, 'product_cat')),
                'tags' => array_map(function($term) {
                    return array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }, wp_get_post_terms($product_id, 'product_tag')),
                'images' => $images,
                'attributes' => $attributes_data,
                'variations' => $product->is_type('variable') ? $product->get_children() : array(),
                'menu_order' => $product->get_menu_order(),
                'meta_data' => $product->get_meta_data(),
            );
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response($products, 200);
}

// Добавляем ACF поля в WooCommerce REST API для категорий
add_filter('woocommerce_rest_prepare_product_cat', function($response, $item, $request) {
    if (!function_exists('get_field')) {
        return $response;
    }

    // Получаем ID термина
    $term_id = $item->term_id;
    
    // Получаем все поля ACF для этой категории
    $fields = get_fields('product_cat_' . $term_id);
    
    if (!empty($fields)) {
        $response->data['acf'] = $fields;
        error_log('Added ACF fields for category ' . $term_id . ': ' . print_r($fields, true));
    }

    return $response;
}, 10, 3);

// Добавляем поддержку REST API для ACF
add_filter('acf/settings/rest_api_format', function() {
    return 'standard';
});

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/sitemap', array(
        'methods' => 'GET',
        'callback' => 'custom_sitemap_endpoint',
    ));
});

// Регистрация endpoint для YML файла
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/yml', array(
        'methods' => 'GET',
        'callback' => 'custom_yml_endpoint',
    ));
});

function custom_sitemap_endpoint() {
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
    error_log('Categories before filter: ' . count($categories));
    
    // Исключаем категорию "misc"
    $categories = array_filter($categories, function($cat) {
        return $cat->slug !== 'misc';
    });
    // Переиндексируем массив
    $categories = array_values($categories);
    error_log('Categories after filter: ' . count($categories));
    
    $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
    error_log('Products found: ' . count($products));

    $sitemap = [
        'categories' => array_map(function ($cat) {
            $category_id = $cat->term_id;
            $category_slug = $cat->slug;
            $custom_url = home_url("/catalog/{$category_slug}?id={$category_id}");
            
            return [
                'name' => $cat->name,
                'url' => $custom_url,
            ];
        }, $categories),
        'products' => array_map(function ($product) {
            $product_id = $product->get_id();
            $product_slug = $product->get_slug();
            $custom_url = home_url("/p/{$product_slug}?id={$product_id}");

            return [
                'name' => $product->get_name(),
                'url' => $custom_url,
            ];
        }, $products),
    ];

    error_log('Sitemap result: ' . print_r($sitemap, true));
    return new WP_REST_Response($sitemap, 200);
}

// YML endpoint - возвращает данные товаров и категорий для формирования YML на фронтенде
function custom_yml_endpoint() {
    // Получаем все категории (исключая misc)
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
    $categories = array_filter($categories, function($cat) {
        return $cat->slug !== 'misc';
    });
    $categories = array_values($categories);

    // Получаем все товары
    $products = wc_get_products(['limit' => -1, 'status' => 'publish']);

    $yml_data = [
        'shop_info' => [
            'name' => 'Vimamos.ru',
            'company' => 'Vimamos.ru', 
            'url' => home_url(),
            'platform' => 'WordPress/WooCommerce/Yandex',
            'version' => '1.0.0',
            'date' => current_time('c')
        ],
        'categories' => [],
        'products' => []
    ];

    // Формируем категории
    foreach ($categories as $category) {
        $yml_data['categories'][] = [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug
        ];
    }

    // Формируем товары
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $product_categories = wp_get_post_terms($product_id, 'product_cat');
        $main_category_id = !empty($product_categories) ? $product_categories[0]->term_id : null;

        // Получаем главное изображение
        $image_url = '';
        if ($product->get_image_id()) {
            $image_url = wp_get_attachment_url($product->get_image_id());
        }

        // Получаем атрибуты товара
        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            $attribute_name = wc_attribute_label($attribute->get_name());
            $attribute_values = [];

            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $attribute_values[] = $term->name;
                    }
                }
            } else {
                $attribute_values = $attribute->get_options();
            }

            if (!empty($attribute_values)) {
                $attributes[$attribute_name] = implode(', ', $attribute_values);
            }
        }

        // Добавляем дополнительные параметры
        if (!empty($product_categories)) {
            $attributes['Категория'] = $product_categories[0]->name;
        }
        
        $attributes['Цена'] = $product->get_price() . ' ₽';

        $yml_data['products'][] = [
            'id' => $product_id,
            'sku' => $product->get_sku(),
            'available' => $product->is_in_stock() ? 'true' : 'false',
            'url' => home_url("/p/{$product->get_slug()}?id={$product_id}"),
            'price' => $product->get_sale_price() ? $product->get_sale_price() : $product->get_regular_price(),
            'currencyId' => 'RUR',
            'categoryId' => $main_category_id,
            'picture' => $image_url,
            'name' => $product->get_name(),
            'attributes' => $attributes
        ];
    }

    return new WP_REST_Response($yml_data, 200);
}