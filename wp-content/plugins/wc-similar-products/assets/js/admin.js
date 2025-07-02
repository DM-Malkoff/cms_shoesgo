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
                        
                        // Скрываем предупреждение о недостающих товарах если оно есть
                        $('.missing-similarities-warning').fadeOut();
                        
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
    
    // Обработчик для кнопки "Исправить"
    $fixButton.on('click', function() {
        if (isProcessing) return;
        
        if (!confirm('Запустить обработку товаров без похожих товаров?\n\nЭто безопасная операция - существующие связи НЕ будут затронуты.')) {
            return;
        }
        
        // Автоматически выбираем режим "new"
        $processingMode.val('new');
        updateProcessingMode();
        
        // Запускаем обработку
        setTimeout(function() {
            $button.click();
        }, 500);
    });
}); 