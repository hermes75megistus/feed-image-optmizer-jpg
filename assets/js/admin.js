jQuery(document).ready(function($) {
    // AJAX URL'lerini tanımla - WordPress global'ından al
    const ajaxUrl = ajaxurl;
    const nonces = {
        optimize: fioAdmin.optimizeNonce,
        batch: fioAdmin.batchNonce,
        progress: fioAdmin.progressNonce,
        clear: fioAdmin.clearNonce
    };
    
    // Test AJAX butonu
    $('#test-ajax').click(function() {
        $('#ajax-test-result').html('Test ediliyor...');
        
        $.post(ajaxUrl, {
            action: 'fio_test_ajax',
            test: 'ping'
        }).done(function(response) {
            $('#ajax-test-result').html('<span style="color: green;">✓ AJAX çalışıyor: ' + response + '</span>');
        }).fail(function(xhr, status, error) {
            $('#ajax-test-result').html('<span style="color: red;">✗ AJAX Hatası: ' + error + '</span>');
        });
    });
    
    // Test optimize butonu
    $('#test-optimize').click(function() {
        const url = $('#test-url').val().trim();
        const device = $('#test-device').val();
        
        if (!url) {
            alert('Lütfen bir URL girin');
            return;
        }
        
        $(this).prop('disabled', true).text('İşleniyor...');
        $('#test-result').html('<div class="notice notice-info"><p>İşleniyor... Lütfen bekleyin.</p></div>');
        
        $.post(ajaxUrl, {
            action: 'optimize_feed_image',
            url: url,
            device: device,
            nonce: nonces.optimize
        }).done(function(response) {
            try {
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                
                if (data.success && data.data) {
                    let html = '<div class="notice notice-success"><p><strong>✓ Optimizasyon Başarılı!</strong></p>';
                    if (data.data.optimized_url) {
                        html += '<p><strong>Optimized URL:</strong><br><a href="' + data.data.optimized_url + '" target="_blank">' + data.data.optimized_url + '</a></p>';
                    }
                    if (data.data.savings !== undefined && data.data.savings !== 'N/A') {
                        html += '<p><strong>Tasarruf:</strong> ' + data.data.savings + '%</p>';
                    }
                    if (data.data.original_size) {
                        html += '<p><strong>Orijinal Boyut:</strong> ' + data.data.original_size + '</p>';
                    }
                    if (data.data.optimized_size) {
                        html += '<p><strong>Optimize Boyut:</strong> ' + data.data.optimized_size + '</p>';
                    }
                    html += '</div>';
                    $('#test-result').html(html);
                } else {
                    $('#test-result').html('<div class="notice notice-error"><p>Hata: ' + (data.data || data.message || 'Bilinmeyen hata') + '</p></div>');
                }
            } catch (e) {
                $('#test-result').html('<div class="notice notice-error"><p>Yanıt parse edilemedi: ' + e.message + '</p></div>');
            }
        }).fail(function(xhr, status, error) {
            $('#test-result').html('<div class="notice notice-error"><p>AJAX hatası: ' + error + '</p></div>');
        }).always(function() {
            $('#test-optimize').prop('disabled', false).text('Test Et');
        });
    });
    
    // Debug butonları
    $('#clear-status').click(function() {
        if (confirm('Dönüştürme durumunu temizlemek istediğinizden emin misiniz?')) {
            $.post(ajaxUrl, {
                action: 'fio_clear_status',
                nonce: nonces.clear
            }).done(function() {
                location.reload();
            });
        }
    });
    
    $('#clear-cron').click(function() {
        $.post(ajaxUrl, {
            action: 'fio_clear_cron',
            nonce: nonces.clear
        }).done(function() {
            location.reload();
        });
    });
    
    $('#clear-cache').click(function() {
        if (confirm('Cache temizlemek istediğinizden emin misiniz?')) {
            $.post(ajaxUrl, {
                action: 'fio_clear_cache',
                nonce: nonces.clear
            }).done(function() {
                location.reload();
            });
        }
    });
    
    // Batch conversion functionality - DÜZELTİLMİŞ
    let conversionInProgress = false;
    let conversionStopped = false;
    let progressCheckInterval;
    let progressCheckCount = 0;
    const maxProgressChecks = 200; // Maksimum 200 kontrol (10 dakika)
    
    $('#start-conversion').click(function() {
        if (conversionInProgress) return;
        
        conversionInProgress = true;
        conversionStopped = false;
        progressCheckCount = 0;
        
        $('#start-conversion').hide();
        $('#stop-conversion').show();
        $('#conversion-progress').show();
        $('#conversion-stats').hide();
        $('.progress-fill').css('width', '0%');
        $('#progress-text').text('Başlatılıyor...');
        $('#conversion-log').empty();
        
        const batchSize = $('#batch-size').val();
        const ashxOnly = $('#ashx-only').is(':checked');
        
        startBatchConversion(batchSize, ashxOnly);
    });
    
    $('#stop-conversion').click(function() {
        conversionStopped = true;
        $(this).prop('disabled', true).text('Durduruluyor...');
        
        if (progressCheckInterval) {
            clearInterval(progressCheckInterval);
        }
        
        // İşlemi durdur
        $.post(ajaxUrl, {
            action: 'fio_clear_status',
            nonce: nonces.clear
        }).done(function() {
            resetConversionUI();
        });
    });
    
    function startBatchConversion(batchSize, ashxOnly) {
        console.log('Starting batch conversion...', {batchSize, ashxOnly});
        
        $.post(ajaxUrl, {
            action: 'batch_convert_featured_images',
            batch_size: batchSize,
            ashx_only: ashxOnly ? 1 : 0,
            nonce: nonces.batch
        }).done(function(response) {
            console.log('Batch start response:', response);
            
            try {
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                
                if (data && data.success) {
                    $('#progress-text').text('İşlem başlatıldı, veriler yükleniyor...');
                    // Progress kontrolünü 3 saniye sonra başlat
                    setTimeout(function() {
                        if (!conversionStopped) {
                            startProgressChecking();
                        }
                    }, 3000);
                } else {
                    alert('Dönüştürme başlatılamadı: ' + (data.data || data.message || 'Bilinmeyen hata'));
                    resetConversionUI();
                }
            } catch (e) {
                console.error('Parse error:', e);
                alert('Yanıt parse edilemedi: ' + e.message);
                resetConversionUI();
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX error:', error);
            alert('AJAX hatası oluştu: ' + error);
            resetConversionUI();
        });
    }
    
    function startProgressChecking() {
        if (conversionStopped) {
            resetConversionUI();
            return;
        }
        
        console.log('Starting progress checking...');
        
        // İlk kontrolü hemen yap
        checkConversionProgress();
        
        // Sonra 3 saniyede bir kontrol et
        progressCheckInterval = setInterval(function() {
            if (!conversionStopped && progressCheckCount < maxProgressChecks) {
                checkConversionProgress();
            } else {
                clearInterval(progressCheckInterval);
                if (progressCheckCount >= maxProgressChecks) {
                    $('#progress-text').text('Maksimum kontrol sayısına ulaşıldı, işlem durduruluyor...');
                    resetConversionUI();
                }
            }
        }, 3000);
    }
    
    function checkConversionProgress() {
        if (conversionStopped) {
            if (progressCheckInterval) {
                clearInterval(progressCheckInterval);
            }
            resetConversionUI();
            return;
        }
        
        progressCheckCount++;
        
        $.post(ajaxUrl, {
            action: 'get_conversion_progress',
            nonce: nonces.progress
        }).done(function(response) {
            try {
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                console.log('Progress response:', data);
                
                if (data && data.success && data.data) {
                    updateProgressUI(data.data);
                    
                    // İşlem tamamlandı mı kontrol et
                    if (data.data.completed === true || data.data.active === false) {
                        console.log('Conversion completed');
                        if (progressCheckInterval) {
                            clearInterval(progressCheckInterval);
                        }
                        showFinalStats(data.data);
                        resetConversionUI();
                    }
                } else {
                    console.warn('Invalid progress response:', data);
                    $('#progress-text').text('İşlem durumu kontrol edilemiyor... (' + progressCheckCount + '/' + maxProgressChecks + ')');
                    
                    // Çok fazla başarısız deneme varsa durdur
                    if (progressCheckCount > 20 && (!data || !data.success)) {
                        console.log('Too many failed checks, stopping...');
                        resetConversionUI();
                    }
                }
            } catch (e) {
                console.error('Progress parse error:', e);
                $('#progress-text').text('Yanıt parse edilemedi, yeniden deneniyor... (' + progressCheckCount + ')');
            }
        }).fail(function(xhr, status, error) {
            console.error('Progress check failed:', error);
            $('#progress-text').text('Bağlantı hatası: ' + error + ' (' + progressCheckCount + ')');
        });
    }
    
    function updateProgressUI(data) {
        console.log('Updating UI with:', data);
        
        // Progress bar güncelle
        const percentage = data.percentage || 0;
        $('.progress-fill').css('width', percentage + '%');
        
        // Text güncelle
        const processed = data.processed || 0;
        const total = data.total || 0;
        $('#progress-text').text(processed + ' / ' + total + ' yazı işlendi (' + percentage + '%)');
        
        // Mevcut post bilgisini göster
        if (data.current_post && data.current_post.id) {
            const post = data.current_post;
            const logEntry = '<div style="margin-bottom: 5px; padding: 8px; background: #fff; border-left: 3px solid ' + 
                (post.success ? '#00a32a' : '#dc3545') + '; border-radius: 3px;">' +
                '<strong>ID: ' + post.id + '</strong> - ' + (post.title || 'Başlıksız') + '<br>' +
                (post.success ? 
                    '<span style="color: #00a32a;">✓ Başarılı</span>' + (post.savings ? ' (Tasarruf: ' + post.savings + '%)' : '') :
                    '<span style="color: #dc3545;">✗ Başarısız</span>' + (post.error ? ' - ' + post.error : '')
                ) + 
                '<small style="color: #666; display: block; margin-top: 3px;">' + new Date().toLocaleTimeString() + '</small>' +
                '</div>';
            
            $('#conversion-log').prepend(logEntry);
            
            // Log'da çok fazla entry varsa eski olanları sil
            $('#conversion-log > div').slice(20).remove();
        }
        
        // Stats güncelle
        if (processed > 0) {
            $('#stats-total').text(processed);
            $('#stats-success').text(data.successful || 0);
            $('#stats-failed').text(data.failed || 0);
            
            const totalSavingsMB = ((data.total_savings || 0) / (1024 * 1024)).toFixed(2);
            $('#stats-savings').text(totalSavingsMB + ' MB');
        }
    }
    
    function showFinalStats(data) {
        console.log('Showing final stats:', data);
        
        $('#stats-total').text(data.processed || 0);
        $('#stats-success').text(data.successful || 0);
        $('#stats-failed').text(data.failed || 0);
        
        const totalSavingsMB = ((data.total_savings || 0) / (1024 * 1024)).toFixed(2);
        $('#stats-savings').text(totalSavingsMB + ' MB');
        
        $('#conversion-stats').show();
        
        // Final message
        const successRate = data.processed > 0 ? Math.round((data.successful / data.processed) * 100) : 0;
        $('#progress-text').text('İşlem tamamlandı! ' + successRate + '% başarı oranı');
        $('.progress-fill').css('width', '100%');
    }
    
    function resetConversionUI() {
        console.log('Resetting conversion UI');
        
        conversionInProgress = false;
        conversionStopped = false;
        progressCheckCount = 0;
        
        if (progressCheckInterval) {
            clearInterval(progressCheckInterval);
            progressCheckInterval = null;
        }
        
        $('#start-conversion').show();
        $('#stop-conversion').hide().prop('disabled', false).text('Durdur');
    }
    
    // Sayfa yüklendiğinde mevcut işlemi kontrol et
    $(document).ready(function() {
        // 2 saniye bekleyip kontrol et
        setTimeout(function() {
            $.post(ajaxUrl, {
                action: 'get_conversion_progress',
                nonce: nonces.progress
            }).done(function(response) {
                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    
                    if (data && data.success && data.data && data.data.active === true) {
                        console.log('Found active conversion on page load');
                        conversionInProgress = true;
                        $('#start-conversion').hide();
                        $('#stop-conversion').show();
                        $('#conversion-progress').show();
                        updateProgressUI(data.data);
                        startProgressChecking();
                    }
                } catch (e) {
                    console.log('No active conversion found');
                }
            }).fail(function() {
                console.log('Could not check for active conversion');
            });
        }, 2000);
    });
});