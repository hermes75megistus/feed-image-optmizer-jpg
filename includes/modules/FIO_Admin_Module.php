<?php
/**
 * Admin Modülü - Tam ve Eksiksiz Versiyon
 * 
 * Tüm admin panel işlemlerini yönetir
 * Single Responsibility: Sadece admin işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Admin_Module implements FIO_Module_Interface {
    
    use FIO_Logger_Trait;
    
    private $settings;
    
    public function __construct(FIO_Settings $settings) {
        $this->settings = $settings;
    }
    
    public function init() {
        // Admin init işlemleri
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function register_hooks() {
        // Sadece admin sayfalarında çalışacak hook'lar
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            
            // Reset optimization flags AJAX handler
            add_action('wp_ajax_fio_reset_optimization_flags', array($this, 'handle_reset_optimization_flags'));
        }
    }
    
    /**
     * Admin menüsünü ekler
     */
    public function add_admin_menu() {
        add_options_page(
            'Feed Image Optimizer',
            'Image Optimizer',
            'manage_options',
            'feed-image-optimizer',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Settings API'yi kaydet
     */
    public function register_settings() {
        $this->settings->register_settings();
    }
    
    /**
     * Admin scripts yükler
     */
    public function enqueue_admin_scripts($hook) {
        // Sadece plugin sayfasında script yükle
        if ($hook !== 'settings_page_feed-image-optimizer') {
            return;
        }
        
        wp_enqueue_script(
            'fio-admin',
            FIO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            FIO_VERSION,
            true
        );
        
        wp_enqueue_style(
            'fio-admin',
            FIO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            FIO_VERSION
        );
        
        // JavaScript değişkenlerini localize et
        wp_localize_script('fio-admin', 'fioAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'optimizeNonce' => wp_create_nonce('optimize_image_nonce'),
            'batchNonce' => wp_create_nonce('batch_convert_nonce'),
            'progressNonce' => wp_create_nonce('progress_nonce'),
            'clearNonce' => wp_create_nonce('fio_clear_nonce')
        ));
    }
    
    /**
     * Optimizasyon işaretlerini sıfırla
     */
    public function handle_reset_optimization_flags() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'fio_reset_flags') || !current_user_can('manage_options')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        try {
            $plugin = FeedImageOptimizerMain::get_instance();
            $auto_module = $plugin->get_module('auto');
            
            if ($auto_module) {
                $auto_module->reset_optimization_flags();
                wp_die(json_encode(array('success' => true, 'message' => 'Optimizasyon işaretleri sıfırlandı')));
            } else {
                wp_die(json_encode(array('success' => false, 'data' => 'Auto converter modülü bulunamadı')));
            }
        } catch (Exception $e) {
            wp_die(json_encode(array('success' => false, 'data' => $e->getMessage())));
        }
    }
    
    /**
     * Admin sayfasını render eder
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Feed Image Optimizer</h1>
            
            <?php settings_errors(); ?>
            
            <div class="fio-admin-container">
                <!-- Test Bölümü -->
                <div class="fio-card">
                    <h2>Sistem Testi</h2>
                    
                    <h3>AJAX Testi</h3>
                    <button id="test-ajax" class="button">AJAX'ı Test Et</button>
                    <div id="ajax-test-result"></div>
                    
                    <h3>Görsel Optimizasyonu Testi</h3>
                    <table class="form-table">
                        <tr>
                            <th>Test URL:</th>
                            <td>
                                <input type="url" id="test-url" style="width: 400px;" 
                                       placeholder="https://example.com/image.ashx" />
                            </td>
                        </tr>
                        <tr>
                            <th>Cihaz Tipi:</th>
                            <td>
                                <select id="test-device">
                                    <option value="auto">Otomatik</option>
                                    <option value="mobile">Mobil</option>
                                    <option value="tablet">Tablet</option>
                                    <option value="desktop">Desktop</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <button id="test-optimize" class="button button-primary">Test Et</button>
                    <div id="test-result"></div>
                </div>
                
                <!-- Ayarlar Bölümü -->
                <div class="fio-card">
                    <h2>Ayarlar</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('feed_image_optimizer_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">WebP Kalitesi</th>
                                <td>
                                    <input type="number" name="fio_webp_quality" 
                                           value="<?php echo esc_attr($this->settings->get_webp_quality()); ?>" 
                                           min="1" max="100" />
                                    <p class="description">1-100 arası değer (80 önerilen)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Mobil Maksimum Genişlik</th>
                                <td>
                                    <input type="number" name="fio_mobile_width" 
                                           value="<?php echo esc_attr($this->settings->get_mobile_width()); ?>" />
                                    <p class="description">Piksel cinsinden (480 önerilen)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Tablet Maksimum Genişlik</th>
                                <td>
                                    <input type="number" name="fio_tablet_width" 
                                           value="<?php echo esc_attr($this->settings->get_tablet_width()); ?>" />
                                    <p class="description">Piksel cinsinden (768 önerilen)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Süresi</th>
                                <td>
                                    <input type="number" name="fio_cache_days" 
                                           value="<?php echo esc_attr($this->settings->get_cache_days()); ?>" />
                                    <p class="description">Gün cinsinden (30 önerilen)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Lazy Loading</th>
                                <td>
                                    <input type="checkbox" name="fio_lazy_loading" value="1" 
                                           <?php checked($this->settings->is_lazy_loading_enabled()); ?> />
                                    <label>Lazy loading aktif</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Otomatik Dönüştürme</th>
                                <td>
                                    <input type="checkbox" name="fio_auto_convert" value="1" 
                                           <?php checked($this->settings->is_auto_convert_enabled()); ?> />
                                    <label>Yeni yüklenen görseller otomatik optimize edilsin</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Orijinal Dosyalar</th>
                                <td>
                                    <input type="checkbox" name="fio_delete_original" value="1" 
                                           <?php checked($this->settings->should_delete_original()); ?> />
                                    <label>Optimize edildikten sonra orijinal dosyalar silinsin</label>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <!-- Toplu Dönüştürme Bölümü -->
                <div class="fio-card">
                    <h2>Toplu Dönüştürme</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th>Batch Boyutu:</th>
                            <td>
                                <select id="batch-size">
                                    <option value="10">10 yazı</option>
                                    <option value="25">25 yazı</option>
                                    <option value="50">50 yazı</option>
                                    <option value="100">100 yazı</option>
                                    <option value="-1">Tümü</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Sadece ASHX:</th>
                            <td>
                                <input type="checkbox" id="ashx-only" checked />
                                <label>Sadece ASHX görsellerini dönüştür</label>
                            </td>
                        </tr>
                    </table>
                    
                    <button id="start-conversion" class="button button-primary">Dönüştürmeyi Başlat</button>
                    <button id="stop-conversion" class="button button-secondary" style="display: none;">Durdur</button>
                    
                    <!-- Progress -->
                    <div id="conversion-progress" style="display: none; margin-top: 20px;">
                        <div class="progress-container" style="background: #f1f1f1; border-radius: 5px; overflow: hidden;">
                            <div class="progress-fill" style="height: 20px; background: #0073aa; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="progress-text">Hazırlanıyor...</p>
                        
                        <!-- Log -->
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; margin-top: 10px;">
                            <div id="conversion-log"></div>
                        </div>
                    </div>
                    
                    <!-- Stats -->
                    <div id="conversion-stats" style="display: none; margin-top: 20px;">
                        <h3>İstatistikler</h3>
                        <ul>
                            <li>Toplam İşlenen: <span id="stats-total">0</span></li>
                            <li>Başarılı: <span id="stats-success">0</span></li>
                            <li>Başarısız: <span id="stats-failed">0</span></li>
                            <li>Toplam Tasarruf: <span id="stats-savings">0 MB</span></li>
                        </ul>
                    </div>
                </div>
                
                <!-- Debug Bölümü -->
                <div class="fio-card">
                    <h2>Debug & İstatistikler</h2>
                    
                    <?php $this->render_system_info(); ?>
                    
                    <!-- Auto Converter İstatistikleri -->
                    <?php if ($this->settings->is_auto_convert_enabled()): ?>
                        <h3>Otomatik Dönüştürme İstatistikleri</h3>
                        <?php
                        try {
                            $plugin = FeedImageOptimizerMain::get_instance();
                            $auto_module = $plugin->get_module('auto');
                            if ($auto_module) {
                                $stats = $auto_module->get_stats();
                                $pending_jobs = $auto_module->get_pending_jobs();
                                ?>
                                <table class="widefat">
                                    <tr>
                                        <td>Optimize Edilmiş Postlar</td>
                                        <td><?php echo esc_html($stats['optimized_posts']); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Optimize Edilmiş Attachmentlar</td>
                                        <td><?php echo esc_html($stats['optimized_attachments']); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Bekleyen İşler</td>
                                        <td><?php echo esc_html($stats['pending_jobs']); ?></td>
                                    </tr>
                                    <tr>
                                        <td>İşlenmekte Olan Postlar</td>
                                        <td><?php echo esc_html($stats['processing_posts']); ?></td>
                                    </tr>
                                </table>
                                
                                <?php if (!empty($pending_jobs)): ?>
                                    <h4>Bekleyen İşler Detayı:</h4>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                        <?php foreach ($pending_jobs as $job): ?>
                                            <div style="margin-bottom: 5px; font-size: 12px;">
                                                <strong><?php echo esc_html($job['hook']); ?></strong> - 
                                                <?php echo esc_html($job['schedule']); ?> - 
                                                Args: <?php echo esc_html(json_encode($job['args'])); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <h4>Debug İşlemleri:</h4>
                                <button type="button" class="button" onclick="resetOptimizationFlags()">Optimizasyon İşaretlerini Sıfırla</button>
                                <script>
                                function resetOptimizationFlags() {
                                    if (confirm('Tüm optimizasyon işaretlerini sıfırlamak istediğinizden emin misiniz?\nBu işlem geri alınamaz ve tüm postlar tekrar otomatik optimize edilebilir hale gelir.')) {
                                        jQuery.post(ajaxurl, {
                                            action: 'fio_reset_optimization_flags',
                                            nonce: '<?php echo wp_create_nonce('fio_reset_flags'); ?>'
                                        }).done(function(response) {
                                            alert('Optimizasyon işaretleri sıfırlandı!');
                                            location.reload();
                                        }).fail(function() {
                                            alert('Hata oluştu!');
                                        });
                                    }
                                }
                                </script>
                                <?php
                            }
                        } catch (Exception $e) {
                            echo '<p>Auto converter istatistikleri alınamadı: ' . esc_html($e->getMessage()) . '</p>';
                        }
                        ?>
                    <?php endif; ?>
                    
                    <!-- Batch Conversion Status -->
                    <?php
                    $conversion_status = get_option('fio_conversion_status', array());
                    if (!empty($conversion_status)):
                    ?>
                        <h3>Toplu Dönüştürme Durumu</h3>
                        <table class="widefat">
                            <tr>
                                <td>Durum</td>
                                <td><?php echo $conversion_status['active'] ? '<span style="color: green;">Aktif</span>' : '<span style="color: red;">Pasif</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>İşlenen / Toplam</td>
                                <td><?php echo esc_html($conversion_status['processed'] ?? 0); ?> / <?php echo esc_html($conversion_status['total'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td>Başarılı</td>
                                <td><?php echo esc_html($conversion_status['successful'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td>Başarısız</td>
                                <td><?php echo esc_html($conversion_status['failed'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td>Toplam Tasarruf</td>
                                <td><?php echo esc_html(round(($conversion_status['total_savings'] ?? 0) / (1024 * 1024), 2)); ?> MB</td>
                            </tr>
                            <tr>
                                <td>Başlangıç Zamanı</td>
                                <td><?php echo isset($conversion_status['start_time']) ? date('Y-m-d H:i:s', $conversion_status['start_time']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td>Offset</td>
                                <td><?php echo esc_html($conversion_status['offset'] ?? 0); ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                    
                    <!-- Cron Jobs Status -->
                    <?php $this->render_cron_status(); ?>
                    
                    <h3>İşlemler</h3>
                    <button id="clear-status" class="button">Dönüştürme Durumunu Temizle</button>
                    <button id="clear-cron" class="button">Cron Job'ları Temizle</button>
                    <button id="clear-cache" class="button">Cache'i Temizle</button>
                    
                    <!-- Log Viewer -->
                    <h3>Son Log Kayıtları</h3>
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; font-family: monospace; font-size: 12px;">
                        <?php $this->render_recent_logs(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sistem bilgilerini gösterir
     */
    private function render_system_info() {
        // Sistem gereksinimlerini kontrol et
        try {
            $plugin = FeedImageOptimizerMain::get_instance();
            $ajax_module = $plugin->get_module('ajax');
            
            if ($ajax_module) {
                $image_processor = $ajax_module->get_image_processor();
                $requirements = $image_processor->check_system_requirements();
            } else {
                $requirements = array(
                    'gd_extension' => extension_loaded('gd'),
                    'webp_support' => function_exists('imagewebp'),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time')
                );
            }
        } catch (Exception $e) {
            $requirements = array();
            echo '<div class="notice notice-error"><p>Sistem bilgileri alınamadı: ' . esc_html($e->getMessage()) . '</p></div>';
        }
        
        if (!empty($requirements)) {
            ?>
            <h3>Sistem Durumu</h3>
            <table class="widefat">
                <tr>
                    <td>GD Extension</td>
                    <td><?php echo $requirements['gd_extension'] ? '✓ Aktif' : '✗ Aktif değil'; ?></td>
                </tr>
                <tr>
                    <td>WebP Desteği</td>
                    <td><?php echo $requirements['webp_support'] ? '✓ Destekleniyor' : '✗ Desteklenmiyor'; ?></td>
                </tr>
                <tr>
                    <td>Memory Limit</td>
                    <td><?php echo esc_html($requirements['memory_limit']); ?></td>
                </tr>
                <tr>
                    <td>Current Memory Usage</td>
                    <td><?php echo $this->format_bytes(memory_get_usage(true)); ?></td>
                </tr>
                <tr>
                    <td>Peak Memory Usage</td>
                    <td><?php echo $this->format_bytes(memory_get_peak_usage(true)); ?></td>
                </tr>
                <tr>
                    <td>Max Execution Time</td>
                    <td><?php echo esc_html($requirements['max_execution_time']); ?> saniye</td>
                </tr>
                <tr>
                    <td>PHP Version</td>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <td>WordPress Version</td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
            </table>
            <?php
        }
        
        // Cache istatistikleri
        try {
            $cache_manager = new FIO_Cache_Manager($this->settings);
            $cache_stats = $cache_manager->get_cache_stats();
            ?>
            <h3>Cache İstatistikleri</h3>
            <table class="widefat">
                <tr>
                    <td>Dosya Sayısı</td>
                    <td><?php echo esc_html($cache_stats['file_count']); ?></td>
                </tr>
                <tr>
                    <td>Toplam Boyut</td>
                    <td><?php echo esc_html($cache_stats['total_size_formatted']); ?></td>
                </tr>
                <tr>
                    <td>Cache Dizini</td>
                    <td><?php echo esc_html($cache_manager->get_cache_dir()); ?></td>
                </tr>
            </table>
            <?php
        } catch (Exception $e) {
            echo '<p>Cache bilgileri alınamadı: ' . esc_html($e->getMessage()) . '</p>';
        }
    }
    
    /**
     * Cron job durumunu gösterir
     */
    private function render_cron_status() {
        $cron_array = _get_cron_array();
        $fio_jobs = array();
        
        if ($cron_array) {
            foreach ($cron_array as $timestamp => $cron) {
                foreach ($cron as $hook => $dings) {
                    if (strpos($hook, 'fio_') === 0) {
                        foreach ($dings as $sig => $data) {
                            $fio_jobs[] = array(
                                'hook' => $hook,
                                'timestamp' => $timestamp,
                                'schedule' => date('Y-m-d H:i:s', $timestamp),
                                'args' => $data['args'] ?? array()
                            );
                        }
                    }
                }
            }
        }
        
        ?>
        <h3>Cron Jobs Durumu</h3>
        <?php if (!empty($fio_jobs)): ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Hook</th>
                        <th>Zamanlanma</th>
                        <th>Parametreler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fio_jobs as $job): ?>
                        <tr>
                            <td><?php echo esc_html($job['hook']); ?></td>
                            <td><?php echo esc_html($job['schedule']); ?></td>
                            <td><?php echo esc_html(json_encode($job['args'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Aktif cron job bulunamadı.</p>
        <?php endif;
    }
    
    /**
     * Son log kayıtlarını göster
     */
    private function render_recent_logs() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/feed-image-logs/conversion.log';
        
        if (!file_exists($log_file)) {
            echo '<div style="color: #666;">Henüz log kaydı yok.</div>';
            return;
        }
        
        // Son 50 satırı al
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            echo '<div style="color: #666;">Log dosyası boş.</div>';
            return;
        }
        
        $recent_lines = array_slice($lines, -50);
        
        foreach (array_reverse($recent_lines) as $line) {
            $line = esc_html($line);
            
            // Log seviyesine göre renklendirme
            if (strpos($line, '[error]') !== false) {
                echo '<div style="color: #d63384; margin-bottom: 2px;">' . $line . '</div>';
            } elseif (strpos($line, '[warning]') !== false) {
                echo '<div style="color: #fd7e14; margin-bottom: 2px;">' . $line . '</div>';
            } elseif (strpos($line, '[info]') !== false) {
                echo '<div style="color: #0d6efd; margin-bottom: 2px;">' . $line . '</div>';
            } else {
                echo '<div style="margin-bottom: 2px;">' . $line . '</div>';
            }
        }
        
        // Log dosyası boyutunu göster
        $log_size = filesize($log_file);
        echo '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; color: #666; font-size: 11px;">';
        echo 'Log dosyası boyutu: ' . $this->format_bytes($log_size);
        echo ' | Toplam satır: ' . count($lines);
        echo '</div>';
    }
    
    /**
     * Byte'ları okunabilir formata çevirir
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}