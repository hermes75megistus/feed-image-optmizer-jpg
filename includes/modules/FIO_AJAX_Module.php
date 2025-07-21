<?php
/**
 * AJAX Modülü - Düzeltilmiş Versiyon
 * 
 * Tüm AJAX isteklerini yönetir
 * Single Responsibility: Sadece AJAX işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_AJAX_Module implements FIO_Module_Interface {
    
    use FIO_Logger_Trait;
    
    private $image_processor;
    private $settings;
    private $cache_manager;
    
    public function __construct(FIO_Image_Processor $image_processor, FIO_Settings $settings) {
        $this->image_processor = $image_processor;
        $this->settings = $settings;
        
        // Cache manager'ı image processor'dan al
        $reflection = new ReflectionClass($image_processor);
        $cache_property = $reflection->getProperty('cache_manager');
        $cache_property->setAccessible(true);
        $this->cache_manager = $cache_property->getValue($image_processor);
    }
    
    public function init() {
        // Background batch processing hook'u ekle
        add_action('fio_continue_batch_conversion', array($this, 'continue_batch_processing'));
    }
    
    public function register_hooks() {
        // Public AJAX endpoints
        add_action('wp_ajax_optimize_feed_image', array($this, 'handle_optimize_image'));
        add_action('wp_ajax_nopriv_optimize_feed_image', array($this, 'handle_optimize_image'));
        
        // Admin AJAX endpoints
        add_action('wp_ajax_batch_convert_featured_images', array($this, 'handle_batch_convert'));
        add_action('wp_ajax_get_conversion_progress', array($this, 'handle_get_progress'));
        add_action('wp_ajax_fio_clear_status', array($this, 'handle_clear_status'));
        add_action('wp_ajax_fio_clear_cron', array($this, 'handle_clear_cron'));
        add_action('wp_ajax_fio_clear_cache', array($this, 'handle_clear_cache'));
        add_action('wp_ajax_fio_test_ajax', array($this, 'handle_test_ajax'));
    }
    
    /**
     * Tek görsel optimizasyonu
     */
    public function handle_optimize_image() {
        // Nonce kontrolü
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'optimize_image_nonce')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Güvenlik hatası')));
        }
        
        $url = sanitize_url($_POST['url'] ?? '');
        $device = sanitize_text_field($_POST['device'] ?? 'auto');
        
        if (empty($url)) {
            wp_die(json_encode(array('success' => false, 'data' => 'URL gerekli')));
        }
        
        // URL format kontrolü
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            wp_die(json_encode(array('success' => false, 'data' => 'Geçersiz URL formatı')));
        }
        
        $this->log_info("Optimizing image: $url (device: $device)");
        
        $result = $this->image_processor->optimize_image($url, $device);
        
        if ($result && is_array($result)) {
            $this->log_info("Image optimization successful for: $url");
            wp_die(json_encode(array('success' => true, 'data' => $result)));
        } else {
            $this->log_error("Image optimization failed for: $url");
            wp_die(json_encode(array('success' => false, 'data' => 'Optimizasyon başarısız oldu')));
        }
    }
    
    /**
     * Toplu dönüştürme başlatır - DÜZELTİLMİŞ
     */
    public function handle_batch_convert() {
        $this->log_info('AJAX batch_convert_featured_images called');
        
        // Nonce ve yetki kontrolü
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'batch_convert_nonce') || !current_user_can('manage_options')) {
            $this->log_error('Authorization failed for batch conversion');
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $ashx_only = intval($_POST['ashx_only'] ?? 1);
        
        // Önceki işlemi temizle
        delete_option('fio_conversion_status');
        delete_option('fio_conversion_progress');
        wp_clear_scheduled_hook('fio_process_batch_conversion');
        wp_clear_scheduled_hook('fio_continue_batch_conversion');
        
        $this->log_info("Batch conversion started. Batch size: $batch_size, ASHX only: $ashx_only");
        
        // Toplam post sayısını hesapla - DÜZELTİLMİŞ
        $total_posts = $this->count_posts_with_featured_images($batch_size, $ashx_only);
        
        if ($total_posts == 0) {
            wp_die(json_encode(array('success' => false, 'data' => 'İşlenecek post bulunamadı')));
        }
        
        $this->log_info("Total posts found: $total_posts");
        
        // İşlem durumunu başlat
        update_option('fio_conversion_status', array(
            'active' => true,
            'batch_size' => $batch_size,
            'ashx_only' => $ashx_only,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'total' => $total_posts,
            'total_savings' => 0,
            'start_time' => time(),
            'completed' => false,
            'offset' => 0 // YENİ: Offset takibi için
        ));
        
        // Progress'i başlat
        update_option('fio_conversion_progress', array(
            'current_post' => array(
                'id' => 0,
                'title' => 'Başlatılıyor...',
                'success' => true
            )
        ));
        
        // İlk batch'i hemen başlat
        wp_schedule_single_event(time() + 1, 'fio_continue_batch_conversion');
        
        $this->log_info('Batch conversion started successfully');
        wp_die(json_encode(array('success' => true, 'message' => 'İşlem başlatıldı')));
    }
    
    /**
     * Background batch processing - YENİ FONKSİYON
     */
    public function continue_batch_processing() {
        $this->log_info('continue_batch_processing started');
        
        $status = get_option('fio_conversion_status', array());
        
        if (empty($status) || !$status['active']) {
            $this->log_info('No active conversion found');
            return;
        }
        
        // Her seferinde 3-5 post işle (performans için)
        $posts_per_batch = 3;
        $ashx_only = $status['ashx_only'];
        $offset = $status['offset'] ?? 0;
        
        // İşlenecek postları bul
        $posts = $this->find_posts_with_featured_images($posts_per_batch, $ashx_only, $offset);
        
        if (empty($posts)) {
            // İşlem tamamlandı
            $status['active'] = false;
            $status['completed'] = true;
            update_option('fio_conversion_status', $status);
            $this->log_info('Batch processing completed - no more posts');
            return;
        }
        
        $this->log_info('Processing ' . count($posts) . ' posts from offset ' . $offset);
        
        // Her post'u işle
        foreach ($posts as $post) {
            $result = $this->process_single_post($post, $ashx_only);
            
            // Status'u güncelle
            $status['processed']++;
            if ($result['success']) {
                $status['successful']++;
                $status['total_savings'] += $result['savings_bytes'];
            } else {
                $status['failed']++;
            }
            
            // Progress güncelle
            update_option('fio_conversion_progress', array(
                'current_post' => $result['post_data']
            ));
            
            // Memory kontrolü
            if ($this->check_memory_limit()) {
                $this->log_info("Memory limit reached, pausing batch");
                break;
            }
        }
        
        // Offset'i güncelle
        $status['offset'] = $offset + count($posts);
        update_option('fio_conversion_status', $status);
        
        // Daha işlenecek post var mı?
        if ($status['processed'] < $status['total']) {
            // Bir sonraki batch'i planla
            $this->log_info("Scheduling next batch: {$status['processed']}/{$status['total']} processed");
            wp_schedule_single_event(time() + 2, 'fio_continue_batch_conversion');
        } else {
            // İşlem tamamlandı
            $status['active'] = false;
            $status['completed'] = true;
            update_option('fio_conversion_status', $status);
            $this->log_info('Batch processing completed successfully');
        }
    }
    
    /**
     * Post sayısını hesaplar - DÜZELTİLMİŞ
     */
    private function count_posts_with_featured_images($batch_size, $ashx_only) {
        $limit = ($batch_size == -1) ? -1 : $batch_size;
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids'
        );
        
        $post_ids = get_posts($args);
        
        if (!$ashx_only) {
            return count($post_ids);
        }
        
        // ASHX filtresi - sadece sayma
        $count = 0;
        foreach ($post_ids as $post_id) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                $attachment_url = wp_get_attachment_url($thumbnail_id);
                if (preg_match('/\.ashx(\?.*)?$/i', $attachment_url)) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Postları bulur - DÜZELTİLMİŞ OFFSET DESTEĞİ İLE
     */
    private function find_posts_with_featured_images($posts_per_batch, $ashx_only, $offset = 0) {
        // ASHX filtrelemesi gerekiyorsa daha fazla post al
        $fetch_count = $ashx_only ? ($posts_per_batch * 5) : $posts_per_batch;
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $fetch_count,
            'offset' => $offset,
            'meta_query' => array(
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'EXISTS'
                )
            ),
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $posts = get_posts($args);
        
        // ASHX filtresi gerekiyorsa uygula
        if ($ashx_only) {
            $filtered_posts = array();
            foreach ($posts as $post) {
                if (count($filtered_posts) >= $posts_per_batch) {
                    break;
                }
                
                $thumbnail_id = get_post_thumbnail_id($post->ID);
                if ($thumbnail_id) {
                    $attachment_url = wp_get_attachment_url($thumbnail_id);
                    if (preg_match('/\.ashx(\?.*)?$/i', $attachment_url)) {
                        $filtered_posts[] = $post;
                    }
                }
            }
            return $filtered_posts;
        }
        
        return array_slice($posts, 0, $posts_per_batch);
    }
    
    /**
     * Tek post işleme - DÜZELTİLMİŞ
     */
    private function process_single_post($post, $ashx_only) {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        
        $result = array(
            'success' => false,
            'savings_bytes' => 0,
            'post_data' => array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'success' => false,
                'error' => null,
                'savings' => null
            )
        );
        
        if (!$thumbnail_id) {
            $result['post_data']['error'] = 'Öne çıkarılan görsel bulunamadı';
            $this->log_info("Post {$post->ID}: No featured image");
            return $result;
        }
        
        $attachment_url = wp_get_attachment_url($thumbnail_id);
        
        // ASHX kontrolü
        if ($ashx_only && !preg_match('/\.ashx(\?.*)?$/i', $attachment_url)) {
            $result['post_data']['error'] = 'ASHX görseli değil';
            $this->log_info("Post {$post->ID}: Not ASHX image, skipped");
            return $result;
        }
        
        // Zaten optimize edilmiş mi kontrol et
        if ($this->is_already_optimized($post->ID)) {
            $result['post_data']['error'] = 'Zaten optimize edilmiş';
            $this->log_info("Post {$post->ID}: Already optimized, skipped");
            return $result;
        }
        
        $this->log_info("Processing post {$post->ID}: {$post->post_title}");
        
        // İmajı optimize et
        $optimized = $this->image_processor->optimize_image($attachment_url);
        
        if ($optimized && isset($optimized['optimized_url'])) {
            // Yeni attachment oluştur
            $new_attachment_id = $this->image_processor->create_attachment_from_optimized($optimized, $post->ID);
            
            if ($new_attachment_id) {
                // Öne çıkarılan görseli güncelle
                set_post_thumbnail($post->ID, $new_attachment_id);
                
                // Optimize edildiğini işaretle (tekrar işlenmemesi için)
                update_post_meta($post->ID, '_fio_optimized', time());
                update_post_meta($post->ID, '_fio_original_attachment_id', $thumbnail_id);
                
                // Eski attachment'ı sil (isteğe bağlı)
                if ($this->settings->should_delete_original()) {
                    wp_delete_attachment($thumbnail_id, true);
                    $this->log_info("Post {$post->ID}: Original image deleted");
                }
                
                $result['success'] = true;
                $result['post_data']['success'] = true;
                $result['post_data']['savings'] = is_numeric($optimized['savings']) ? $optimized['savings'] : 0;
                $result['savings_bytes'] = $this->calculate_savings_bytes($optimized);
                
                $this->log_info("Post {$post->ID}: Conversion successful");
            } else {
                $result['post_data']['error'] = 'Attachment oluşturulamadı';
                $this->log_error("Post {$post->ID}: Failed to create attachment");
            }
        } else {
            $result['post_data']['error'] = 'Optimizasyon başarısız';
            $this->log_error("Post {$post->ID}: Optimization failed");
        }
        
        return $result;
    }
    
    /**
     * Post'un zaten optimize edilip edilmediğini kontrol eder - YENİ
     */
    private function is_already_optimized($post_id) {
        $optimized_time = get_post_meta($post_id, '_fio_optimized', true);
        return !empty($optimized_time);
    }
    
    /**
     * Memory limit kontrolü - DÜZELTİLMİŞ
     */
    private function check_memory_limit() {
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $current_memory = memory_get_usage(true);
        
        // %85'e ulaştıysa durdur
        $threshold = $memory_limit * 0.85;
        
        if ($current_memory > $threshold) {
            $this->log_warning("Memory usage: " . $this->format_bytes($current_memory) . " / " . $this->format_bytes($memory_limit));
            return true;
        }
        
        return false;
    }
    
    /**
     * Tasarruf hesaplama - DÜZELTİLMİŞ
     */
    private function calculate_savings_bytes($optimized_data) {
        if (!isset($optimized_data['original_size']) || !isset($optimized_data['optimized_size'])) {
            return 0;
        }
        
        $original_bytes = $this->parse_formatted_size($optimized_data['original_size']);
        $optimized_bytes = $this->parse_formatted_size($optimized_data['optimized_size']);
        
        return max(0, $original_bytes - $optimized_bytes);
    }
    
    /**
     * Formatlanmış boyutu byte'a çevirir - DÜZELTİLMİŞ
     */
    private function parse_formatted_size($formatted_size) {
        if (is_numeric($formatted_size)) {
            return floatval($formatted_size);
        }
        
        $formatted_size = strtoupper(str_replace(' ', '', $formatted_size));
        $numeric_value = floatval($formatted_size);
        
        if (strpos($formatted_size, 'KB') !== false) {
            return $numeric_value * 1024;
        } elseif (strpos($formatted_size, 'MB') !== false) {
            return $numeric_value * 1024 * 1024;
        } elseif (strpos($formatted_size, 'GB') !== false) {
            return $numeric_value * 1024 * 1024 * 1024;
        } else {
            return $numeric_value; // Bytes
        }
    }
    
    /**
     * Byte formatı - YENİ
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Dönüştürme progress'ini döndürür - DÜZELTİLMİŞ
     */
    public function handle_get_progress() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'progress_nonce')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        $status = get_option('fio_conversion_status', array());
        $progress = get_option('fio_conversion_progress', array());
        
        if (empty($status)) {
            wp_die(json_encode(array('success' => false, 'data' => 'İşlem bulunamadı')));
        }
        
        $response_data = array_merge($status, $progress);
        
        // Progress yüzdesi hesapla
        if (isset($response_data['total']) && $response_data['total'] > 0) {
            $response_data['percentage'] = round(($response_data['processed'] / $response_data['total']) * 100, 2);
        } else {
            $response_data['percentage'] = 0;
        }
        
        wp_die(json_encode(array('success' => true, 'data' => $response_data)));
    }
    
    /**
     * Dönüştürme durumunu temizler
     */
    public function handle_clear_status() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'fio_clear_nonce') || !current_user_can('manage_options')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        delete_option('fio_conversion_status');
        delete_option('fio_conversion_progress');
        wp_clear_scheduled_hook('fio_process_batch_conversion');
        wp_clear_scheduled_hook('fio_continue_batch_conversion');
        $this->log_info('Status cleared by user');
        
        wp_die(json_encode(array('success' => true)));
    }
    
    /**
     * Cron job'ları temizler
     */
    public function handle_clear_cron() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'fio_clear_nonce') || !current_user_can('manage_options')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        wp_clear_scheduled_hook('fio_process_batch_conversion');
        wp_clear_scheduled_hook('fio_continue_batch_conversion');
        $this->log_info('Cron cleared by user');
        
        wp_die(json_encode(array('success' => true)));
    }
    
    /**
     * Cache'i temizler
     */
    public function handle_clear_cache() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'fio_clear_nonce') || !current_user_can('manage_options')) {
            wp_die(json_encode(array('success' => false, 'data' => 'Yetki hatası')));
        }
        
        $deleted_count = $this->cache_manager->clear_cache(0); // Tüm dosyaları sil
        $this->log_info("Cache cleared by user: $deleted_count files deleted");
        
        wp_die(json_encode(array(
            'success' => true, 
            'message' => "$deleted_count dosya silindi"
        )));
    }
    
    /**
     * AJAX bağlantısını test eder
     */
    public function handle_test_ajax() {
        wp_die('AJAX çalışıyor! ' . date('H:i:s'));
    }
    
    /**
     * Image processor'ı döndürür (diğer modüller için)
     */
    public function get_image_processor() {
        return $this->image_processor;
    }
    
    /**
     * Cache manager'ı döndürür (diğer modüller için)
     */
    public function get_cache_manager() {
        return $this->cache_manager;
    }
    
    /**
     * Settings'i döndürür (diğer modüller için)
     */
    public function get_settings() {
        return $this->settings;
    }
}