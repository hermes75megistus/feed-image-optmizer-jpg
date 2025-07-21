<?php
/**
 * Toplu Dönüştürme Modülü
 * 
 * ASHX öne çıkarılan görselleri toplu olarak dönüştürür
 * Single Responsibility: Sadece toplu dönüştürme işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Batch_Converter implements FIO_Module_Interface {
    
    use FIO_Logger_Trait;
    
    private $image_processor;
    private $settings;
    
    public function __construct(FIO_Image_Processor $image_processor, FIO_Settings $settings) {
        $this->image_processor = $image_processor;
        $this->settings = $settings;
    }
    
    public function init() {
        // Background process için memory limit artır
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', 300);
        }
    }
    
    public function register_hooks() {
        // Cron hook
        add_action('fio_process_batch_conversion', array($this, 'process_batch_conversion'));
    }
    
    /**
     * Ana toplu dönüştürme fonksiyonu
     */
    public function process_batch_conversion() {
        $this->log_info('process_batch_conversion started');
        
        $status = get_option('fio_conversion_status', array());
        
        if (empty($status) || !$status['active']) {
            $this->log_info('No active conversion found');
            return;
        }
        
        $batch_size = $status['batch_size'];
        $ashx_only = $status['ashx_only'];
        
        // ASHX öne çıkarılan görseli olan yazıları bul
        $posts = $this->find_posts_with_featured_images($batch_size, $ashx_only);
        $total_posts = count($posts);
        
        $this->log_info("Found $total_posts posts with featured images");
        
        if (empty($posts)) {
            $this->complete_conversion($status, 0);
            return;
        }
        
        // Status'u güncelle
        $status['total'] = $total_posts;
        update_option('fio_conversion_status', $status);
        
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
            
            update_option('fio_conversion_status', $status);
            
            // Memory kontrolü
            if ($this->check_memory_limit()) {
                $this->log_info("Memory limit reached, pausing batch");
                break;
            }
        }
        
        // İşlem tamamlandı mı?
        if ($status['processed'] >= $total_posts) {
            $this->complete_conversion($status, $status['processed']);
        } else {
            // Devam etmek için yeni event planla
            $this->log_info("Scheduling next batch: {$status['processed']}/{$status['total']} processed");
            wp_schedule_single_event(time() + 3, 'fio_process_batch_conversion');
        }
        
        update_option('fio_conversion_status', $status);
    }
    
    /**
     * Öne çıkarılan görseli olan yazıları bulur
     */
    private function find_posts_with_featured_images($batch_size, $ashx_only) {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
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
        
        return $posts;
    }
    
    /**
     * Tek bir post'u işler
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
        
        $this->log_info("Processing post {$post->ID}: {$post->post_title}");
        
        // İmajı optimize et
        $optimized = $this->image_processor->optimize_image($attachment_url);
        
        if ($optimized && isset($optimized['optimized_url'])) {
            // Yeni attachment oluştur
            $new_attachment_id = $this->image_processor->create_attachment_from_optimized($optimized, $post->ID);
            
            if ($new_attachment_id) {
                // Öne çıkarılan görseli güncelle
                set_post_thumbnail($post->ID, $new_attachment_id);
                
                // Eski attachment'ı sil (isteğe bağlı)
                if ($this->settings->should_delete_original()) {
                    wp_delete_attachment($thumbnail_id, true);
                    $this->log_info("Post {$post->ID}: Original image deleted");
                }
                
                $result['success'] = true;
                $result['post_data']['success'] = true;
                $result['post_data']['savings'] = $optimized['savings'];
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
     * Tasarruf edilen byte'ları hesaplar
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
     * Formatlanmış boyutu byte'a çevirir
     */
    private function parse_formatted_size($formatted_size) {
        if (strpos($formatted_size, 'KB') !== false) {
            return floatval($formatted_size) * 1024;
        } elseif (strpos($formatted_size, 'MB') !== false) {
            return floatval($formatted_size) * 1024 * 1024;
        } elseif (strpos($formatted_size, 'GB') !== false) {
            return floatval($formatted_size) * 1024 * 1024 * 1024;
        } else {
            return floatval($formatted_size); // Bytes
        }
    }
    
    /**
     * Memory limit kontrolü yapar
     */
    private function check_memory_limit() {
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $current_memory = memory_get_usage();
        
        // %80'e ulaştıysa durdur
        return $current_memory > ($memory_limit * 0.8);
    }
    
    /**
     * Dönüştürme işlemini tamamlar
     */
    private function complete_conversion($status, $processed_count) {
        $status['active'] = false;
        $status['completed'] = true;
        
        $this->log_info("Batch completed: $processed_count posts processed");
        
        update_option('fio_conversion_status', $status);
    }
    
    /**
     * Modül deaktivasyonu
     */
    public function deactivate() {
        // Aktif işlemleri durdur
        delete_option('fio_conversion_status');
        delete_option('fio_conversion_progress');
        
        // Scheduled events'leri temizle
        wp_clear_scheduled_hook('fio_process_batch_conversion');
        
        $this->log_info('Batch converter deactivated');
    }
}