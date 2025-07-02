jQuery(document).ready(function($) {
    var $button = $('#recalculate-similarities');
    var $progressWrapper = $('.progress-wrapper');
    var $progress = $('.progress');
    var $status = $('.progress-status');
    var $processedList = $('<div class="processed-products"></div>').insertAfter($progress);
    var isProcessing = false;
    var retryCount = 0;
    var maxRetries = 3;
    var delayBetweenBatches = 5000; // 5 секунд между пакетами
    var ajaxTimeout = 300000; // 5 минут таймаут
    var processedProducts = [];
    
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
        var productHtml = '<div class="product-item" style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #fff; display: flex; align-items: center;">';
        
        // Добавляем миниатюру
        if (product.thumbnail) {
            productHtml += '<img src="' + product.thumbnail + '" style="width: 50px; height: 50px; margin-right: 10px; object-fit: cover;" />';
        } else {
            productHtml += '<div style="width: 50px; height: 50px; margin-right: 10px; background: #f0f0f1;"></div>';
        }
        
        // Добавляем информацию о товаре
        productHtml += '<div style="flex-grow: 1;">';
        productHtml += '<strong>' + product.title + '</strong>';
        if (product.sku) {
            productHtml += ' <span style="color: #666;">(SKU: ' + product.sku + ')</span>';
        }
        if (product.price) {
            productHtml += '<br><span style="color: #666;">' + formatPrice(product.price) + '</span>';
        }
        productHtml += '</div>';
        
        // Добавляем ссылки
        productHtml += '<div style="margin-left: 10px;">';
        if (product.view_link) {
            productHtml += '<a href="' + product.view_link + '" target="_blank" class="button button-small" style="margin-right: 5px;">Просмотр</a>';
        }
        if (product.edit_link) {
            productHtml += '<a href="' + product.edit_link + '" target="_blank" class="button button-small">Редактировать</a>';
        }
        productHtml += '</div>';
        
        productHtml += '</div>';
        
        // Добавляем товар в начало списка
        $processedList.prepend(productHtml);
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
                batch: batch
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
        
        if (!confirm('Are you sure you want to recalculate similar products? This process may take a while.')) {
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