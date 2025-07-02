jQuery(document).ready(function($) {
    var $button = $('#recalculate-similarities');
    var $progressWrapper = $('.progress-wrapper');
    var $progress = $('.progress');
    var $status = $('.progress-status');
    var $processedList = $('<div class="processed-products"></div>').insertAfter($progress);
    var $processingMode = $('#processing-mode');
    var $categoriesRow = $('#categories-row');
    var $productCategories = $('#product-categories');
    var $selectedInfo = $('#selected-info');
    var $fixButton = $('#fix-missing-similarities');
    var $debugButton = $('#debug-missing-similarities');
    var $categoriesSearch = $('#categories-search');
    var $selectFoundBtn = $('#select-found-categories');
    var $clearCategoriesBtn = $('#clear-categories-selection');
    var $toggleViewBtn = $('#toggle-categories-view');
    var $foundCount = $('#found-count');
    var $selectedCount = $('#selected-categories-count');
    var $visibleCount = $('#visible-categories-count');
    var $totalProducts = $('#categories-total-products');
    var isProcessing = false;
    var showOnlySelected = false;
    var retryCount = 0;
    var maxRetries = 3;
    var delayBetweenBatches = 2000; // 2 секунды между пакетами (уменьшили, так как батчи меньше)
    var ajaxTimeout = 180000; // 3 минуты таймаут
    var processedProducts = [];
    var statsTimeout;
    
    // Обработка изменения режима обработки
    function updateProcessingMode() {
        var mode = $processingMode.val();
        var showCategories = (mode === 'categories' || mode === 'categories_new');
        
        if (showCategories) {
            $categoriesRow.show();
        } else {
            $categoriesRow.hide();
        }
        
        updateSelectedInfo();
    }
    
    // Обновление информации о выбранных параметрах
    function updateSelectedInfo() {
        var mode = $processingMode.val();
        var selectedCategories = $productCategories.val() || [];
        
        // Блокируем кнопку если нужно выбрать категории
        var needCategories = (mode === 'categories' || mode === 'categories_new') && selectedCategories.length === 0;
        $button.prop('disabled', needCategories && !isProcessing);
        
        // Получаем статистику с сервера с задержкой (debounce)
        clearTimeout(statsTimeout);
        statsTimeout = setTimeout(function() {
            getProductStats(mode, selectedCategories);
        }, 500);
    }
    
    // Получение статистики товаров
    function getProductStats(mode, selectedCategories) {
        $selectedInfo.html('<i>Подсчитываем товары...</i>');
        
        $.ajax({
            url: wcSimilarProducts.ajax_url,
            type: 'POST',
            data: {
                action: 'get_category_stats',
                nonce: wcSimilarProducts.stats_nonce,
                processing_mode: mode,
                categories: selectedCategories
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var info = '';
                    
                                         switch(mode) {
                        case 'all':
                            info = '⚠️ Будут обработаны все товары (' + data.total_products + ' шт.) - ВСЕ ДАННЫЕ БУДУТ ОЧИЩЕНЫ!';
                            break;
                        case 'categories':
                            if (selectedCategories.length > 0) {
                                info = 'Будут обработаны товары из ' + selectedCategories.length + ' категорий (' + data.total_products + ' шт.)';
                            } else {
                                info = 'Выберите категории для обработки';
                            }
                            break;
                        case 'new':
                            info = 'Будут обработаны только товары без похожих товаров (' + data.total_products + ' шт.)';
                            break;
                        case 'categories_new':
                            if (selectedCategories.length > 0) {
                                info = 'Будут обработаны новые товары из ' + selectedCategories.length + ' категорий (' + data.total_products + ' шт.)';
                            } else {
                                info = 'Выберите категории для обработки';
                            }
                            break;
                    }
                    
                    $selectedInfo.text(info);
                } else {
                    $selectedInfo.text('Ошибка при получении статистики');
                }
            },
            error: function() {
                $selectedInfo.text('Ошибка при получении статистики');
            }
        });
    }
    
    // Событие изменения режима обработки
    $processingMode.on('change', updateProcessingMode);
    
    // Функции для работы с категориями
    function searchCategories(searchTerm) {
        var $options = $productCategories.find('option');
        var foundCount = 0;
        
        // Убираем предыдущую подсветку
        $options.removeClass('search-highlight');
        
        searchTerm = searchTerm.toLowerCase().trim();
        
        if (searchTerm === '') {
            // Показываем все опции
            $options.show();
            foundCount = $options.length;
        } else {
            $options.each(function() {
                var $option = $(this);
                var categoryName = $option.data('name') || '';
                var categoryText = $option.text().toLowerCase();
                
                if (categoryName.includes(searchTerm) || categoryText.includes(searchTerm)) {
                    $option.show().addClass('search-highlight');
                    foundCount++;
                } else if (!showOnlySelected) {
                    $option.hide().removeClass('search-highlight');
                } else if (!$option.is(':selected')) {
                    $option.hide().removeClass('search-highlight');
                }
            });
        }
        
        // Обновляем счетчики
        $foundCount.text(foundCount);
        $selectFoundBtn.prop('disabled', foundCount === 0);
        updateCategoriesInfo();
    }
    
    function selectFoundCategories() {
        var $highlighted = $productCategories.find('option.search-highlight:visible');
        $highlighted.prop('selected', true);
        updateCategoriesInfo();
        updateSelectedInfo();
    }
    
    function clearCategoriesSelection() {
        $productCategories.find('option').prop('selected', false);
        updateCategoriesInfo();
        updateSelectedInfo();
    }
    
    function toggleCategoriesView() {
        showOnlySelected = !showOnlySelected;
        var $options = $productCategories.find('option');
        
        if (showOnlySelected) {
            $options.each(function() {
                var $option = $(this);
                if ($option.is(':selected')) {
                    $option.show();
                } else {
                    $option.hide();
                }
            });
            $toggleViewBtn.text('📋 Показать все категории');
        } else {
            // Применяем текущий поиск
            searchCategories($categoriesSearch.val());
            $toggleViewBtn.text('👁️ Показать только выбранные');
        }
        
        updateCategoriesInfo();
    }
    
    function updateCategoriesInfo() {
        var selectedCategories = $productCategories.val() || [];
        var visibleOptions = $productCategories.find('option:visible').length;
        var totalProducts = 0;
        
        // Подсчитываем общее количество товаров в выбранных категориях
        if (selectedCategories.length > 0) {
            $productCategories.find('option:selected').each(function() {
                var count = parseInt($(this).data('count')) || 0;
                totalProducts += count;
            });
            $totalProducts.text('(~' + totalProducts.toLocaleString() + ' товаров)');
        } else {
            $totalProducts.text('');
        }
        
        $selectedCount.text('Выбрано: ' + selectedCategories.length);
        $visibleCount.text('Показано: ' + visibleOptions);
    }
    
    // Обработчики событий для категорий
    $categoriesSearch.on('input', function() {
        var searchTerm = $(this).val();
        
        // Добавляем класс анимации при поиске
        if (searchTerm.length > 0) {
            $(this).addClass('searching');
        } else {
            $(this).removeClass('searching');
        }
        
        searchCategories(searchTerm);
    });
    
    // Поиск по Enter
    $categoriesSearch.on('keydown', function(e) {
        if (e.keyCode === 13) { // Enter
            e.preventDefault();
            if (!$selectFoundBtn.prop('disabled')) {
                selectFoundCategories();
            }
        } else if (e.keyCode === 27) { // Escape
            $(this).val('');
            searchCategories('');
        }
    });
    
    $selectFoundBtn.on('click', selectFoundCategories);
    $clearCategoriesBtn.on('click', clearCategoriesSelection);
    $toggleViewBtn.on('click', toggleCategoriesView);
    
    $productCategories.on('change', function() {
        updateCategoriesInfo();
        updateSelectedInfo();
    });
    
    // Двойной клик для быстрого поиска популярных терминов
    $categoriesSearch.on('dblclick', function() {
        var popularSearches = ['обувь', 'одежда', 'аксессуары', 'сумки', 'часы'];
        var randomSearch = popularSearches[Math.floor(Math.random() * popularSearches.length)];
        $(this).val(randomSearch);
        searchCategories(randomSearch);
    });
    
    // Инициализация
    updateProcessingMode();
    updateCategoriesInfo();
    refreshStatistics();
    
    function formatPrice(price) {
        return price ? new Intl.NumberFormat('ru-RU', { 
            style: 'currency', 
            currency: 'RUB'
        }).format(price) : '';
    }
    
    function updateProcessedList(product) {
        if (!product) return;
        
        // Добавляем товар в массив
        processedProducts.push(product);
        
        // Создаем HTML для товара
        var productHtml = '<div class="product-item">';
        
        // Добавляем миниатюру
        if (product.thumbnail) {
            productHtml += '<img src="' + product.thumbnail + '" class="product-thumbnail" alt="' + product.title + '" />';
        } else {
            productHtml += '<div class="product-placeholder">Нет фото</div>';
        }
        
        // Добавляем информацию о товаре
        productHtml += '<div class="product-info">';
        productHtml += '<div class="product-title">' + product.title + '</div>';
        if (product.sku) {
            productHtml += '<div class="product-meta">SKU: ' + product.sku + '</div>';
        }
        if (product.price) {
            productHtml += '<div class="product-meta product-price">' + formatPrice(product.price) + '</div>';
        }
        productHtml += '</div>';
        
        // Добавляем ссылки
        productHtml += '<div class="product-actions">';
        if (product.view_link) {
            productHtml += '<a href="' + product.view_link + '" target="_blank" class="button">Просмотр</a>';
        }
        if (product.edit_link) {
            productHtml += '<a href="' + product.edit_link + '" target="_blank" class="button">Редактировать</a>';
        }
        productHtml += '</div>';
        
        productHtml += '</div>';
        
        // Добавляем товар в начало списка
        $processedList.prepend(productHtml);
        
        // Ограничиваем количество показываемых товаров для производительности
        var items = $processedList.find('.product-item');
        if (items.length > 50) {
            items.slice(50).remove();
        }
    }
    
    function updateStatus(message, isError) {
        $status.html(message);
        if (isError) {
            $status.css('color', '#dc3232');
        } else {
            $status.css('color', '');
        }
    }
    
    function processBatch(batch) {
        if (!isProcessing) return;
        
        updateStatus(wcSimilarProducts.processing_text.replace('%s', '0') + '<br><small>Processing batch ' + batch + '</small>');
        
        $.ajax({
            url: wcSimilarProducts.ajax_url,
            type: 'POST',
            data: {
                action: 'recalculate_similarities_batch',
                nonce: wcSimilarProducts.nonce,
                batch: batch,
                processing_mode: $processingMode.val(),
                categories: $productCategories.val() || []
            },
            timeout: ajaxTimeout,
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    retryCount = 0; // Сбрасываем счетчик повторов при успехе
                    
                    // Обновляем прогресс
                    $progress.css('width', data.percentage + '%');
                    
                    var statusText = wcSimilarProducts.processing_text.replace('%s', data.percentage) + 
                        '<br><small>Processed: ' + data.processed + ' of ' + data.total + '</small>';
                    
                    // Добавляем debug информацию если она есть
                    if (data.debug_info) {
                        statusText += '<br><small style="color: #666;">Batch: ' + data.debug_info.retrieved_products + '/' + data.debug_info.batch_size + ' products retrieved</small>';
                    }
                    
                    updateStatus(statusText);
                    
                    // Обновляем список обработанных товаров
                    if (data.product) {
                        updateProcessedList(data.product);
                    }
                    
                    if (!data.complete) {
                        // Продолжаем с следующим пакетом
                        setTimeout(function() {
                            processBatch(batch + 1);
                        }, delayBetweenBatches);
                    } else {
                        // Завершаем процесс
                        isProcessing = false;
                        $button.prop('disabled', false);
                        updateStatus(wcSimilarProducts.success_text + '<br><small>Обработано товаров: ' + data.processed + '</small>');
                        
                        // Скрываем предупреждение о недостающих товарах если оно есть
                        $('.missing-similarities-warning').fadeOut();
                        
                        // Обновляем статистику после завершения
                        refreshStatistics();
                        
                        setTimeout(function() {
                            $progressWrapper.fadeOut();
                        }, 2000);
                    }
                } else {
                    handleError(response.data || 'Unknown error occurred');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
                
                var errorMessage = 'Error occurred: ';
                if (textStatus === 'timeout') {
                    errorMessage += 'Request timed out. The operation is taking too long.';
                } else if (textStatus === 'error' && jqXHR.status === 500) {
                    errorMessage += 'Server error occurred.';
                } else {
                    errorMessage += textStatus || 'Unknown error';
                }
                
                // Пробуем повторить запрос при ошибке
                if (retryCount < maxRetries) {
                    retryCount++;
                    updateStatus('Retrying... Attempt ' + retryCount + ' of ' + maxRetries + '<br><small>' + errorMessage + '</small>', true);
                    setTimeout(function() {
                        processBatch(batch);
                    }, delayBetweenBatches * 2); // Увеличиваем задержку при повторе
                } else {
                    handleError(errorMessage);
                }
            }
        });
    }
    
    function handleError(error) {
        isProcessing = false;
        $button.prop('disabled', false);
        updateStatus(wcSimilarProducts.error_text + '<br><small>' + error + '</small>', true);
        console.error('Error:', error);
    }
    
    $button.on('click', function() {
        if (isProcessing) return;
        
        // Проверяем, нужно ли выбрать категории
        var mode = $processingMode.val();
        var selectedCategories = $productCategories.val() || [];
        var needCategories = (mode === 'categories' || mode === 'categories_new') && selectedCategories.length === 0;
        
        if (needCategories) {
            alert('Пожалуйста, выберите категории для обработки.');
            return;
        }
        
        // Формируем сообщение подтверждения
        var confirmMessage = 'Вы уверены, что хотите пересчитать похожие товары?\n\n';
        switch(mode) {
            case 'all':
                confirmMessage += '⚠️ ВНИМАНИЕ! Будут обработаны ВСЕ товары в каталоге.\n';
                confirmMessage += '⚠️ ВСЕ СУЩЕСТВУЮЩИЕ СВЯЗИ ПОХОЖИХ ТОВАРОВ БУДУТ УДАЛЕНЫ!\n';
                confirmMessage += 'Это действие НЕЛЬЗЯ отменить!';
                break;
            case 'categories':
                confirmMessage += 'Будут обработаны товары из ' + selectedCategories.length + ' выбранных категорий.\n';
                confirmMessage += 'Существующие связи для этих товаров будут заменены новыми.';
                break;
            case 'new':
                confirmMessage += 'Будут обработаны только товары без похожих товаров.\n';
                confirmMessage += 'Существующие связи НЕ будут затронуты.';
                break;
            case 'categories_new':
                confirmMessage += 'Будут обработаны новые товары из ' + selectedCategories.length + ' выбранных категорий.\n';
                confirmMessage += 'Существующие связи для обработанных товаров НЕ будут затронуты.';
                break;
        }
        confirmMessage += '\n\nПроцесс может занять некоторое время.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        startProcessing();
    });
    
    // Обработчик для кнопки "Исправить"
    $fixButton.on('click', function() {
        if (isProcessing) return;
        
        if (!confirm('Запустить обработку товаров без похожих товаров?\n\nЭто безопасная операция - существующие связи НЕ будут затронуты.')) {
            return;
        }
        
        // Автоматически выбираем режим "new"
        $processingMode.val('new');
        updateProcessingMode();
        
        // Запускаем обработку напрямую, минуя confirm основной кнопки
        startProcessing();
    });
    
    // Обработчик для кнопки диагностики
    $debugButton.on('click', function() {
        debugProblematicProducts();
    });
    
    // Вынесем логику запуска в отдельную функцию
    function startProcessing() {
        isProcessing = true;
        retryCount = 0;
        processedProducts = [];
        $button.prop('disabled', true);
        $progressWrapper.show();
        $progress.css('width', '0%');
        $processedList.empty();
        updateStatus(wcSimilarProducts.processing_text.replace('%s', '0'));
        
        processBatch(0);
    }
    
    // Функция для обновления статистики
    function refreshStatistics() {
        $.ajax({
            url: wcSimilarProducts.ajax_url,
            type: 'POST',
            data: {
                action: 'refresh_statistics',
                nonce: wcSimilarProducts.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Обновляем статистику в таблице
                    $('.wc-similar-stats-table tr:nth-child(1) td:nth-child(2)').text(data.total_products);
                    $('.wc-similar-stats-table tr:nth-child(2) td:nth-child(2)').text(data.total_relations);
                    $('.wc-similar-stats-table tr:nth-child(3) td:nth-child(2)').text(data.avg_similar);
                    
                    // Обновляем таблицу последних товаров
                    if (data.recent_products && data.recent_products.length > 0) {
                        var recentTableBody = $('.wc-similar-recent-products tbody');
                        recentTableBody.empty();
                        
                        $.each(data.recent_products, function(index, product) {
                            var editLink = '/wp-admin/post.php?post=' + product.ID + '&action=edit';
                            var viewLink = '/?p=' + product.ID; // Может потребоваться настройка для правильной ссылки
                            
                            var row = '<tr>' +
                                '<td>' + product.ID + '</td>' +
                                '<td>' + product.post_title + '</td>' +
                                '<td align="center">' + product.similar_count + '</td>' +
                                '<td>' +
                                    '<a href="' + editLink + '" target="_blank">Редактировать</a>' +
                                    '&nbsp;|&nbsp;' +
                                    '<a href="' + viewLink + '" target="_blank">Просмотреть</a>' +
                                '</td>' +
                            '</tr>';
                            recentTableBody.append(row);
                        });
                    }
                    
                    // Показываем/скрываем предупреждение о товарах без похожих
                    if (data.products_without_similar > 0) {
                        if ($('.missing-similarities-warning').length === 0) {
                            // Создаем предупреждение если его нет
                            var warningHtml = '<div class="missing-similarities-warning">' +
                                '<h4 style="margin-top: 0; color: #856404;">⚠️ Обнаружены товары без похожих товаров</h4>' +
                                '<p style="margin-bottom: 10px;">' +
                                    'Найдено <strong>' + data.products_without_similar + '</strong> товаров без похожих товаров. ' +
                                    'Это может быть результатом прерванной обработки или ошибки.' +
                                '</p>' +
                                '<p style="margin-bottom: 15px; font-size: 13px; color: #666;">' +
                                    '<strong>Что произошло:</strong> Возможно, процесс обработки был прерван, и некоторые товары остались без похожих товаров. ' +
                                    'Нажмите кнопку ниже, чтобы безопасно обработать только эти товары.' +
                                '</p>' +
                                '<button type="button" id="fix-missing-similarities" class="button button-secondary">' +
                                    '🔧 Исправить - обработать товары без похожих' +
                                '</button>' +
                                '<small style="color: #666; display: block; margin-top: 8px;">' +
                                    '✅ Безопасная операция - существующие связи НЕ будут затронуты' +
                                '</small>' +
                            '</div>';
                            
                            $('#selected-info').parent().after(warningHtml);
                                                         // Переподключаем обработчики
                             $('#fix-missing-similarities').on('click', function() {
                                 if (isProcessing) return;
                                 
                                 if (!confirm('Запустить обработку товаров без похожих товаров?\n\nЭто безопасная операция - существующие связи НЕ будут затронуты.')) {
                                     return;
                                 }
                                 
                                 $processingMode.val('new');
                                 updateProcessingMode();
                                 startProcessing();
                             });
                             
                             $('#debug-missing-similarities').on('click', function() {
                                 debugProblematicProducts();
                             });
                        } else {
                            // Обновляем число в существующем предупреждении
                            $('.missing-similarities-warning p:first strong').text(data.products_without_similar);
                            $('.missing-similarities-warning').show();
                        }
                    } else {
                        $('.missing-similarities-warning').hide();
                    }
                }
            },
            error: function() {
                console.log('Ошибка при обновлении статистики');
                         }
         });
     }
     
     // Функция для диагностики проблемных товаров
     function debugProblematicProducts() {
         $('#debug-results').show();
         $('#debug-content').html('<p>🔄 Анализируем проблемные товары...</p>');
         
         $.ajax({
             url: wcSimilarProducts.ajax_url,
             type: 'POST',
             data: {
                 action: 'debug_products_without_similar',
                 nonce: wcSimilarProducts.nonce
             },
             success: function(response) {
                 if (response.success) {
                     var data = response.data;
                     var html = '<p><strong>Найдено ' + data.total_count + ' проблемных товаров:</strong></p>';
                     
                     if (data.products && data.products.length > 0) {
                         html += '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
                         html += '<thead><tr style="background: #f0f0f1;">';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">ID</th>';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Название</th>';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">WC Товар</th>';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Категории</th>';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Тип</th>';
                         html += '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Действия</th>';
                         html += '</tr></thead><tbody>';
                         
                         $.each(data.products, function(index, product) {
                             var categoriesText = product.categories_count > 0 ? 
                                 product.categories_count + ' (' + product.categories.join(', ') + ')' : 
                                 '❌ Нет';
                             
                             var statusColor = product.has_wc_product === 'YES' ? '#00a32a' : '#d63638';
                             var categoryColor = product.categories_count > 0 ? '#00a32a' : '#d63638';
                             
                             html += '<tr>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd;">' + product.id + '</td>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd;">' + product.title + '</td>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: ' + statusColor + ';">' + product.has_wc_product + '</td>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: ' + categoryColor + ';">' + categoriesText + '</td>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd;">' + product.product_type + '</td>';
                             html += '<td style="padding: 8px; border: 1px solid #ddd;">';
                             html += '<a href="/wp-admin/post.php?post=' + product.id + '&action=edit" target="_blank" class="button button-small">Редактировать</a>';
                             html += '</td>';
                             html += '</tr>';
                         });
                         
                         html += '</tbody></table>';
                         
                         html += '<div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffb900;">';
                         html += '<h6 style="margin: 0 0 8px 0;">🔧 Рекомендации по исправлению:</h6>';
                         html += '<ul style="margin: 0; padding-left: 20px;">';
                         html += '<li><strong>Товары без категорий:</strong> Добавьте категории для корректной работы алгоритма</li>';
                         html += '<li><strong>Неверный тип товара:</strong> Проверьте настройки WooCommerce</li>';
                         html += '<li><strong>Товары без WC объекта:</strong> Возможно повреждены данные товара</li>';
                         html += '</ul>';
                         html += '</div>';
                     } else {
                         html += '<p style="color: #00a32a;">✅ Проблемных товаров не найдено!</p>';
                     }
                     
                     $('#debug-content').html(html);
                 } else {
                     $('#debug-content').html('<p style="color: #d63638;">❌ Ошибка при получении диагностической информации</p>');
                 }
             },
             error: function() {
                 $('#debug-content').html('<p style="color: #d63638;">❌ Ошибка соединения при диагностике</p>');
             }
         });
     }
});  