<?php
/**
 * Otomatik Dönüştürme Modülü - Düzeltilmiş Versiyon
 * 
 * Yeni yüklenen görselleri otomatik olarak optimize eder
 * Single Responsibility: Sadece otomatik dönüştürme işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Auto_Converter implements FIO_Module_Interface {
    
    use FIO_Logger_Trait;
    
    private $image_processor;
    private $settings;
    private $processing_posts = array(); // Tekrar işlemeyi önlemek için
    
    public function __construct(FIO_Image_Processor $image_processor, FIO_Settings $settings) {
        $this->image_processor = $image_processor;
        $this->settings = $settings;
    }
    
    public function init() {
        // Auto converter init işlemleri
    }
    
    public function register_hooks() {
        // Sadece otomatik dönüştürme aktifse hook'ları kaydet
        if ($this->settings->is_auto_convert_enabled()) {
            // Yeni post yayınlandığında - SADECE BİR KEZ
            add_action('publish_post', array($this, 'handle_post_publish'), 10, 2);
            
            // Post güncellendi ve öne çıkarılan görsel değiştirildiğinde - KONTROLLÜ
            add_action('updated_post_meta', array($this, 'handle_featured_image_change'), 10, 4);
            
            // Attachment upload edildiğinde - SADECE ASHX İÇİN
            add_action('add_attachment', array($this, 'handle_attachment_upload'));
        }
        
        // Cron job'ları
        add_action('fio_auto_optimize_featured_image', array($this, 'process_auto_optimization'), 10, 2);
        add_action('fio_auto_optimize_attachment', array($this, 'process_attachment_optimization'), 10, 1);
    }
    
    /**
     * Post yayınlandığında öne çıkarılan görseli kontrol eder - DÜZELTİLMİŞ
     */
    public function handle_post_publish($post_id, $post) {
        // Çoklu işlemeyi önle
        if (in_array($post_id, $this->processing_posts)) {
            $this->log_info("Post $post_id already being processed, skipping");
            return;
        }
        
        if (!$this->should_process_post($post)) {
            return;
        }
        
        // Zaten optimize edilmiş mi?
        if ($this->is_already_optimized($post_id)) {
            $this->log_info("Post $post_id already optimized, skipping");
            return;
        }
        
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if (!$thumbnail_id) {
            return;
        }
        
        $attachment_url = wp_get_attachment_url($thumbnail_id);
        
        // ASHX kontrolü
        if (!$this->is_ashx_image($attachment_url)) {
            return;
        }
        
        // İşleme listesine ekle
        $this->processing_posts[] = $post_id;
        
        $this->log_info("Auto-converting featured image for newly published post $post_id");
        
        // Background'da optimize et (delay ile)
        wp_schedule_single_event(time() + 15, 'fio_auto_optimize_featured_image', array($post_id, $attachment_url));
    }
    
    /**
     * Öne çıkarılan görsel değiştirildiğinde - DÜZELTİLMİŞ KONTROL İLE
     */
    public function handle_featured_image_change($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_thumbnail_id') {
            return;
        }
        
        // Çoklu işlemeyi önle
        if (in_array($post_id, $this->processing_posts)) {
            $this->log_info("Post $post_id already being processed, skipping featured image change");
            return;
        }
        
        $post = get_post($post_id);
        if (!$this->should_process_post($post)) {
            return;
        }
        
        // Önceki değeri kontrol et (gerçekten değişti mi?)
        $previous_thumbnail_id = get_post_meta($post_id, '_fio_previous_thumbnail_id', true);
        if ($previous_thumbnail_id == $meta_value) {
            return; // Aynı görsel, işleme gerek yok
        }
        
        // Yeni thumbnail'i kaydet
        update_post_meta($post_id, '_fio_previous_thumbnail_id', $meta_value);
        
        $attachment_url = wp_get_attachment_url($meta_value);
        
        // ASHX kontrolü
        if (!$this->is_ashx_image($attachment_url)) {
            return;
        }
        
        // Zaten optimize edilmiş mi?
        if ($this->is_attachment_optimized($meta_value)) {
            $this->log_info("Attachment $meta_value already optimized, skipping");
            return;
        }
        
        // İşleme listesine ekle
        $this->processing_posts[] = $post_id;
        
        $this->log_info("Auto-converting updated featured image for post $post_id");
        
        // Background'da optimize et (delay ile)
        wp_schedule_single_event(time() + 10, 'fio_auto_optimize_featured_image', array($post_id, $attachment_url));
    }
    
    /**
     * Yeni attachment yüklendiğinde - SADECE ASHX İÇİN
     */
    public function handle_attachment_upload($attachment_id) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        
        // ASHX kontrolü
        if (!$this->is_ashx_image($attachment_url)) {
            return;
        }
        
        // Zaten optimize edilmiş mi?
        if ($this->is_attachment_optimized($attachment_id)) {
            $this->log_info("Attachment $attachment_id already optimized, skipping");
            return;
        }
        
        $this->log_info("Auto-converting uploaded ASHX attachment $attachment_id");
        
        // Background'da optimize et (büyük delay ile)
        wp_schedule_single_event(time() + 30, 'fio_auto_optimize_attachment', array($attachment_id));
    }
    
    /**
     * Öne çıkarılan görsel otomatik optimizasyonu - DÜZELTİLMİŞ
     */
    public function process_auto_optimization($post_id, $attachment_url) {
        $this->log_info("Processing auto optimization for post $post_id");
        
        // Tekrar kontrol: zaten optimize edilmiş mi?
        if ($this->is_already_optimized($post_id)) {
            $this->log_info("Post $post_id already optimized during processing, skipping");
            return;
        }
        
        try {
            $optimized = $this->image_processor->optimize_image($attachment_url);
            
            if ($optimized && isset($optimized['optimized_url'])) {
                $new_attachment_id = $this->image_processor->create_attachment_from_optimized($optimized, $post_id);
                
                if ($new_attachment_id) {
                    // Eski thumbnail ID'yi al
                    $old_thumbnail_id = get_post_thumbnail_id($post_id);
                    
                    // Öne çıkarılan görseli güncelle
                    set_post_thumbnail($post_id, $new_attachment_id);
                    
                    // Optimize edildiğini işaretle
                    update_post_meta($post_id, '_fio_optimized', time());
                    update_post_meta($post_id, '_fio_original_attachment_id', $old_thumbnail_id);
                    update_post_meta($post_id, '_fio_optimized_attachment_id', $new_attachment_id);
                    
                    // Attachment'a da işaretle
                    update_post_meta($new_attachment_id, '_fio_optimized_attachment', time());
                    
                    // Eski attachment'ı sil (eğer ayar aktifse ve farklıysa)
                    if ($this->settings->should_delete_original() && $old_thumbnail_id && $old_thumbnail_id != $new_attachment_id) {
                        wp_delete_attachment($old_thumbnail_id, true);
                        $this->log_info("Deleted original attachment $old_thumbnail_id for post $post_id");
                    }
                    
                    $this->log_info("Auto optimization completed for post $post_id (new attachment: $new_attachment_id)");
                    
                    // Hook çalıştır (diğer plugin'ler için)
                    do_action('fio_auto_optimization_completed', $post_id, $new_attachment_id, $optimized);
                } else {
                    $this->log_error("Failed to create optimized attachment for post $post_id");
                }
            } else {
                $this->log_error("Failed to optimize image for post $post_id");
            }
            
        } catch (Exception $e) {
            $this->log_error("Auto optimization exception for post $post_id: " . $e->getMessage());
        } finally {
            // İşleme listesinden çıkar
            $this->processing_posts = array_diff($this->processing_posts, array($post_id));
        }
    }
    
    /**
     * Attachment otomatik optimizasyonu - DÜZELTİLMİŞ
     */
    public function process_attachment_optimization($attachment_id) {
        $this->log_info("Processing auto optimization for attachment $attachment_id");
        
        // Tekrar kontrol: zaten optimize edilmiş mi?
        if ($this->is_attachment_optimized($attachment_id)) {
            $this->log_info("Attachment $attachment_id already optimized during processing, skipping");
            return;
        }
        
        $attachment_url = wp_get_attachment_url($attachment_id);
        $parent_post_id = wp_get_post_parent_id($attachment_id);
        
        try {
            $optimized = $this->image_processor->optimize_image($attachment_url);
            
            if ($optimized && isset($optimized['optimized_url'])) {
                $new_attachment_id = $this->image_processor->create_attachment_from_optimized($optimized, $parent_post_id);
                
                if ($new_attachment_id) {
                    // Optimize edildiğini işaretle
                    update_post_meta($new_attachment_id, '_fio_optimized_attachment', time());
                    update_post_meta($new_attachment_id, '_fio_original_attachment_id', $attachment_id);
                    
                    // Orijinal attachment'ı sil (eğer ayar aktifse)
                    if ($this->settings->should_delete_original()) {
                        wp_delete_attachment($attachment_id, true);
                        $this->log_info("Deleted original attachment $attachment_id");
                    } else {
                        // Silinmeyecekse optimize edildiğini işaretle
                        update_post_meta($attachment_id, '_fio_replaced_by', $new_attachment_id);
                    }
                    
                    $this->log_info("Auto optimization completed for attachment $attachment_id (new: $new_attachment_id)");
                    
                    // Hook çalıştır
                    do_action('fio_attachment_auto_optimization_completed', $attachment_id, $new_attachment_id, $optimized);
                } else {
                    $this->log_error("Failed to create optimized attachment for $attachment_id");
                }
            } else {
                $this->log_error("Failed to optimize attachment $attachment_id");
            }
            
        } catch (Exception $e) {
            $this->log_error("Auto optimization exception for attachment $attachment_id: " . $e->getMessage());
        }
    }
    
    /**
     * Post'un işlenip işlenmeyeceğini kontrol eder
     */
    private function should_process_post($post) {
        if (!$post || is_wp_error($post)) {
            return false;
        }
        
        // Sadece post tipini kontrol et
        if ($post->post_type !== 'post') {
            return false;
        }
        
        // Post durumunu kontrol et
        if ($post->post_status !== 'publish') {
            return false;
        }
        
        // Revision'ları atla
        if (wp_is_post_revision($post->ID)) {
            return false;
        }
        
        // Autosave'leri atla
        if (wp_is_post_autosave($post->ID)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * URL'nin ASHX görseli olup olmadığını kontrol eder
     */
    private function is_ashx_image($url) {
        if (empty($url)) {
            return false;
        }
        
        return preg_match('/\.ashx(\?.*)?$/i', $url);
    }
    
    /**
     * Post'un zaten optimize edilip edilmediğini kontrol eder - YENİ
     */
    private function is_already_optimized($post_id) {
        $optimized_time = get_post_meta($post_id, '_fio_optimized', true);
        return !empty($optimized_time);
    }
    
    /**
     * Attachment'ın zaten optimize edilip edilmediğini kontrol eder - YENİ
     */
    private function is_attachment_optimized($attachment_id) {
        // Optimized attachment mi?
        $optimized_time = get_post_meta($attachment_id, '_fio_optimized_attachment', true);
        if (!empty($optimized_time)) {
            return true;
        }
        
        // Başka bir attachment ile değiştirilmiş mi?
        $replaced_by = get_post_meta($attachment_id, '_fio_replaced_by', true);
        if (!empty($replaced_by)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Bekleyen otomatik optimizasyon işlerini döndürür
     */
    public function get_pending_jobs() {
        $pending_jobs = array();
        
        // Cron job'ları kontrol et
        $cron_array = _get_cron_array();
        
        if (!$cron_array) {
            return $pending_jobs;
        }
        
        foreach ($cron_array as $timestamp => $cron) {
            foreach ($cron as $hook => $dings) {
                if (in_array($hook, array('fio_auto_optimize_featured_image', 'fio_auto_optimize_attachment'))) {
                    foreach ($dings as $sig => $data) {
                        $pending_jobs[] = array(
                            'hook' => $hook,
                            'timestamp' => $timestamp,
                            'args' => $data['args'],
                            'schedule' => date('Y-m-d H:i:s', $timestamp)
                        );
                    }
                }
            }
        }
        
        return $pending_jobs;
    }
    
    /**
     * Belirli bir post için bekleyen işleri iptal eder
     */
    public function cancel_pending_jobs_for_post($post_id) {
        $cancelled_count = 0;
        
        // Featured image job'larını iptal et
        while ($scheduled = wp_next_scheduled('fio_auto_optimize_featured_image', array($post_id))) {
            wp_unschedule_event($scheduled, 'fio_auto_optimize_featured_image', array($post_id));
            $cancelled_count++;
        }
        
        // İşleme listesinden çıkar
        $this->processing_posts = array_diff($this->processing_posts, array($post_id));
        
        if ($cancelled_count > 0) {
            $this->log_info("Cancelled $cancelled_count pending auto optimization jobs for post $post_id");
        }
        
        return $cancelled_count;
    }
    
    /**
     * Tekrar optimize edilen postları sıfırla (debug için)
     */
    public function reset_optimization_flags($post_ids = array()) {
        if (empty($post_ids)) {
            // Tüm optimize edilmiş postları sıfırla
            global $wpdb;
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_optimized'));
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_original_attachment_id'));
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_optimized_attachment_id'));
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_previous_thumbnail_id'));
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_optimized_attachment'));
            $wpdb->delete($wpdb->postmeta, array('meta_key' => '_fio_replaced_by'));
            
            $this->log_info('All optimization flags reset');
        } else {
            // Sadece belirtilen postları sıfırla
            foreach ($post_ids as $post_id) {
                delete_post_meta($post_id, '_fio_optimized');
                delete_post_meta($post_id, '_fio_original_attachment_id');
                delete_post_meta($post_id, '_fio_optimized_attachment_id');
                delete_post_meta($post_id, '_fio_previous_thumbnail_id');
            }
            
            $this->log_info('Optimization flags reset for posts: ' . implode(', ', $post_ids));
        }
    }
    
    /**
     * İstatistikleri döndür
     */
    public function get_stats() {
        global $wpdb;
        
        $optimized_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_fio_optimized'");
        $optimized_attachments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_fio_optimized_attachment'");
        $pending_jobs = count($this->get_pending_jobs());
        
        return array(
            'optimized_posts' => intval($optimized_posts),
            'optimized_attachments' => intval($optimized_attachments),
            'pending_jobs' => $pending_jobs,
            'processing_posts' => count($this->processing_posts)
        );
    }
    
    /**
     * Modül deaktivasyonu
     */
    public function deactivate() {
        // Bekleyen tüm auto optimization job'larını temizle
        wp_clear_scheduled_hook('fio_auto_optimize_featured_image');
        wp_clear_scheduled_hook('fio_auto_optimize_attachment');
        
        // İşleme listesini temizle
        $this->processing_posts = array();
        
        $this->log_info('Auto converter deactivated - all pending jobs cleared');
    }
}