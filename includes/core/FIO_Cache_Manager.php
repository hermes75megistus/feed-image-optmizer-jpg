<?php
/**
 * Cache Yöneticisi
 * 
 * Optimize edilmiş imajların cache işlemlerini yönetir
 * Single Responsibility: Sadece cache işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Cache_Manager {
    
    use FIO_Logger_Trait;
    
    private $settings;
    private $upload_dir;
    private $cache_dir;
    private $cache_url;
    
    public function __construct(FIO_Settings $settings) {
        $this->settings = $settings;
        $this->upload_dir = wp_upload_dir();
        $this->cache_dir = $this->upload_dir['basedir'] . '/feed-image-cache';
        $this->cache_url = $this->upload_dir['baseurl'] . '/feed-image-cache';
    }
    
    /**
     * Gerekli klasörleri oluşturur
     */
    public function create_directories() {
        if (!file_exists($this->cache_dir)) {
            if (wp_mkdir_p($this->cache_dir)) {
                $this->log_info('Cache directory created: ' . $this->cache_dir);
            } else {
                $this->log_error('Failed to create cache directory: ' . $this->cache_dir);
                return false;
            }
        }
        
        // .htaccess dosyası ekle (güvenlik)
        $htaccess_file = $this->cache_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<FilesMatch \"\.(webp|jpg|jpeg|png)$\">\n";
            $htaccess_content .= "    Allow from all\n";
            $htaccess_content .= "</FilesMatch>\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        return true;
    }
    
    /**
     * Cache dosyası yolunu oluşturur
     */
    public function get_cache_path($url, $device = 'auto') {
        $url_hash = md5($url . $device);
        $file_extension = function_exists('imagewebp') ? '.webp' : '.jpg';
        return $this->cache_dir . '/' . $url_hash . $file_extension;
    }
    
    /**
     * Cache URL'sini oluşturur
     */
    public function get_cache_url($url, $device = 'auto') {
        $url_hash = md5($url . $device);
        $file_extension = function_exists('imagewebp') ? '.webp' : '.jpg';
        return $this->cache_url . '/' . $url_hash . $file_extension;
    }
    
    /**
     * Cache'de dosya var mı kontrol eder
     */
    public function is_cached($url, $device = 'auto') {
        $cache_file = $this->get_cache_path($url, $device);
        
        if (!file_exists($cache_file)) {
            return false;
        }
        
        // Cache süresi kontrolü
        $cache_time = $this->settings->get_cache_days() * 24 * 60 * 60;
        $file_age = time() - filemtime($cache_file);
        
        if ($file_age > $cache_time) {
            $this->log_info("Cache expired for: $url");
            unlink($cache_file);
            return false;
        }
        
        return true;
    }
    
    /**
     * Cache'den dosya bilgilerini alır
     */
    public function get_cached_info($url, $device = 'auto') {
        if (!$this->is_cached($url, $device)) {
            return false;
        }
        
        $cache_file = $this->get_cache_path($url, $device);
        $cache_url = $this->get_cache_url($url, $device);
        
        return array(
            'optimized_url' => $cache_url,
            'file_path' => $cache_file,
            'file_size' => filesize($cache_file),
            'cached' => true
        );
    }
    
    /**
     * Dosyayı cache'e kaydeder
     */
    public function save_to_cache($image_resource, $url, $device = 'auto') {
        $cache_file = $this->get_cache_path($url, $device);
        $quality = $this->settings->get_webp_quality();
        
        // Cache dizini yoksa oluştur
        if (!$this->create_directories()) {
            return false;
        }
        
        $result = false;
        
        try {
            // WebP veya JPEG formatında kaydet
            if (function_exists('imagewebp') && strpos($cache_file, '.webp') !== false) {
                $result = imagewebp($image_resource, $cache_file, $quality);
            } else {
                $result = imagejpeg($image_resource, $cache_file, $quality);
            }
            
            if ($result && file_exists($cache_file)) {
                $this->log_info("Image cached: $cache_file");
                return $cache_file;
            } else {
                $this->log_error("Failed to save cache file: $cache_file");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log_error("Cache save exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cache'i temizler
     */
    public function clear_cache($older_than_days = null) {
        if (!is_dir($this->cache_dir)) {
            return true;
        }
        
        $older_than_days = $older_than_days ?: $this->settings->get_cache_days();
        $cutoff_time = time() - ($older_than_days * 24 * 60 * 60);
        
        $files = glob($this->cache_dir . '/*');
        $deleted_count = 0;
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff_time) {
                if (unlink($file)) {
                    $deleted_count++;
                }
            }
        }
        
        $this->log_info("Cache cleanup: $deleted_count files deleted");
        return $deleted_count;
    }
    
    /**
     * Cache istatistikleri
     */
    public function get_cache_stats() {
        if (!is_dir($this->cache_dir)) {
            return array(
                'file_count' => 0,
                'total_size' => 0,
                'total_size_formatted' => '0 B'
            );
        }
        
        $files = glob($this->cache_dir . '/*');
        $file_count = 0;
        $total_size = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $file_count++;
                $total_size += filesize($file);
            }
        }
        
        return array(
            'file_count' => $file_count,
            'total_size' => $total_size,
            'total_size_formatted' => $this->format_bytes($total_size)
        );
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
    
    /**
     * Cache dizinini döndürür
     */
    public function get_cache_dir() {
        return $this->cache_dir;
    }
    
    /**
     * Cache URL'sini döndürür
     */
    public function get_cache_base_url() {
        return $this->cache_url;
    }
}