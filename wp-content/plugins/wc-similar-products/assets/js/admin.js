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
    var isProcessing = false;
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
                            info = 'Будут обработаны все товары (' + data.total_products + ' шт.)';
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
    $productCategories.on('change', updateSelectedInfo);
    
    // Инициализация
    updateProcessingMode();
    
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
                    updateStatus(
                        wcSimilarProducts.processing_text.replace('%s', data.percentage) + 
                        '<br><small>Processed: ' + data.processed + ' of ' + data.total + '</small>'
                    );
                    
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
                        updateStatus(wcSimilarProducts.success_text);
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
                confirmMessage += 'Будут обработаны ВСЕ товары в каталоге.';
                break;
            case 'categories':
                confirmMessage += 'Будут обработаны товары из ' + selectedCategories.length + ' выбранных категорий.';
                break;
            case 'new':
                confirmMessage += 'Будут обработаны только товары без похожих товаров.';
                break;
            case 'categories_new':
                confirmMessage += 'Будут обработаны новые товары из ' + selectedCategories.length + ' выбранных категорий.';
                break;
        }
        confirmMessage += '\n\nПроцесс может занять некоторое время.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        isProcessing = true;
        retryCount = 0;
        processedProducts = [];
        $button.prop('disabled', true);
        $progressWrapper.show();
        $progress.css('width', '0%');
        $processedList.empty();
        updateStatus(wcSimilarProducts.processing_text.replace('%s', '0'));
        
        processBatch(0);
    });
}); 