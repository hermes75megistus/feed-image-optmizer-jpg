<?php
/**
 * Görsel İşleyici - JPG Version
 * 
 * Görsel optimizasyonu ve dönüştürme işlemlerini yönetir
 * Single Responsibility: Sadece görsel işleme
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Image_Processor {
    
    use FIO_Logger_Trait;
    
    private $settings;
    private $cache_manager;
    
    public function __construct(FIO_Settings $settings, FIO_Cache_Manager $cache_manager) {
        $this->settings = $settings;
        $this->cache_manager = $cache_manager;
    }
    
    /**
     * Ana optimizasyon fonksiyonu
     */
    public function optimize_image($url, $device = 'auto') {
        // URL validasyonu
        if (!$this->validate_url($url)) {
            $this->log_error("Invalid URL: $url");
            return false;
        }
        
        // Cache kontrolü
        $cached_info = $this->cache_manager->get_cached_info($url, $device);
        if ($cached_info) {
            $this->log_info("Returning cached image for: $url");
            return $this->format_response($cached_info, 'N/A (Cached)', true);
        }
        
        // Cihaz tipini belirle
        if ($device === 'auto') {
            $device = $this->detect_device();
        }
        
        try {
            // Orijinal görüntüyü indir
            $image_data = $this->download_image($url);
            if (!$image_data) {
                return false;
            }
            
            $original_size = strlen($image_data);
            
            // GD ile işle
            $processed_image = $this->process_image_data($image_data, $device);
            if (!$processed_image) {
                return false;
            }
            
            // Cache'e kaydet (JPG formatında)
            $cache_file = $this->cache_manager->save_to_cache($processed_image['resource'], $url, $device);
            imagedestroy($processed_image['resource']);
            
            if (!$cache_file) {
                return false;
            }
            
            $optimized_size = filesize($cache_file);
            $cache_url = $this->cache_manager->get_cache_url($url, $device);
            
            return $this->format_response(
                array(
                    'optimized_url' => $cache_url,
                    'file_path' => $cache_file,
                    'file_size' => $optimized_size,
                    'dimensions' => $processed_image['dimensions']
                ),
                $original_size,
                false,
                $optimized_size,
                $device
            );
            
        } catch (Exception $e) {
            $this->log_error('Image processing exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * URL validasyonu
     */
    private function validate_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Desteklenen protokoller
        $allowed_schemes = array('http', 'https');
        $parsed_url = parse_url($url);
        
        if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], $allowed_schemes)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Görüntüyü indirir
     */
    private function download_image($url) {
        $args = array(
            'timeout' => 45,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Feed Image Optimizer)',
            'headers' => array(
                'Accept' => 'image/jpeg,image/jpg,image/png,image/*,*/*;q=0.8',  // JPG öncelikli accept header
            ),
            'sslverify' => false
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error("HTTP Error for $url: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log_error("HTTP $response_code for URL: $url");
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        
        if (empty($image_data) || strlen($image_data) < 1024) {
            $this->log_error("Invalid or too small image data for: $url");
            return false;
        }
        
        return $image_data;
    }
    
    /**
     * Görüntü verisini işler
     */
    private function process_image_data($image_data, $device) {
        $image = @imagecreatefromstring($image_data);
        if (!$image) {
            $this->log_error("Cannot create image from string");
            return false;
        }
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        if ($width === false || $height === false) {
            imagedestroy($image);
            $this->log_error("Cannot get image dimensions");
            return false;
        }
        
        $max_width = $this->settings->get_max_width_for_device($device);
        $processed_image = $image;
        $new_width = $width;
        $new_height = $height;
        
        // Yeniden boyutlandırma gerekli mi?
        if ($width > $max_width) {
            $new_width = $max_width;
            $new_height = intval(($height / $width) * $max_width);
            
            $resized_image = imagecreatetruecolor($new_width, $new_height);
            
            if (!$resized_image) {
                imagedestroy($image);
                $this->log_error("Cannot create resized image");
                return false;
            }
            
            // JPG için beyaz background (transparency olmadığı için)
            $white = imagecolorallocate($resized_image, 255, 255, 255);
            imagefill($resized_image, 0, 0, $white);
            
            if (!imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height)) {
                imagedestroy($image);
                imagedestroy($resized_image);
                $this->log_error("Cannot resample image");
                return false;
            }
            
            imagedestroy($image);
            $processed_image = $resized_image;
        }
        
        return array(
            'resource' => $processed_image,
            'dimensions' => array(
                'width' => $new_width,
                'height' => $new_height
            )
        );
    }
    
    /**
     * Cihaz tipini algılar
     */
    private function detect_device() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $user_agent)) {
            return 'tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', $user_agent)) {
            return 'mobile';
        } else {
            return 'desktop';
        }
    }
    
    /**
     * Yanıt formatını hazırlar
     */
    private function format_response($optimized_data, $original_size, $cached = false, $optimized_size = null, $device = null) {
        $response = array(
            'optimized_url' => $optimized_data['optimized_url'],
            'cached' => $cached,
            'format' => 'jpg'  // Format bilgisi eklendi
        );
        
        if ($cached) {
            $response['original_size'] = $original_size;
            $response['optimized_size'] = $this->format_bytes($optimized_data['file_size']);
            $response['savings'] = 'N/A';
        } else {
            $response['original_size'] = $this->format_bytes($original_size);
            $response['optimized_size'] = $this->format_bytes($optimized_size);
            $savings = round((($original_size - $optimized_size) / $original_size) * 100, 2);
            $response['savings'] = max(0, $savings);
            
            if ($device) {
                $response['device'] = $device;
            }
            
            if (isset($optimized_data['dimensions'])) {
                $response['dimensions'] = $optimized_data['dimensions'];
            }
        }
        
        return $response;
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
     * WordPress attachment oluşturur - JPG için
     */
    public function create_attachment_from_optimized($optimized_data, $post_id) {
        $optimized_url = $optimized_data['optimized_url'];
        
        // Cache dosyasının yolunu bul
        $cache_dir = $this->cache_manager->get_cache_dir();
        $filename = basename(parse_url($optimized_url, PHP_URL_PATH));
        $file_path = $cache_dir . '/' . $filename;
        
        if (!file_exists($file_path)) {
            $this->log_error("Cache file not found: $file_path");
            return false;
        }
        
        // WordPress upload dizinine kopyala
        $upload_dir = wp_upload_dir();
        $new_filename = 'optimized-' . time() . '-' . $filename;
        $new_file_path = $upload_dir['path'] . '/' . $new_filename;
        
        if (!copy($file_path, $new_file_path)) {
            $this->log_error("Failed to copy cache file to uploads");
            return false;
        }
        
        // Attachment data hazırla
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $new_filename,
            'post_mime_type' => 'image/jpeg',  // MIME type JPG olarak güncellendi
            'post_title' => 'Optimized Featured Image (JPG)',
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post_id
        );
        
        // Attachment'ı veritabanına ekle
        $attachment_id = wp_insert_attachment($attachment, $new_file_path, $post_id);
        
        if (is_wp_error($attachment_id)) {
            unlink($new_file_path);
            $this->log_error("Attachment insert error: " . $attachment_id->get_error_message());
            return false;
        }
        
        // Attachment metadata oluştur
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $new_file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        $this->log_info("Created JPG attachment $attachment_id for post $post_id");
        return $attachment_id;
    }
    
    /**
     * Sistem gereksinimlerini kontrol eder
     */
    public function check_system_requirements() {
        return array(
            'gd_extension' => extension_loaded('gd'),
            'jpeg_support' => function_exists('imagejpeg'),  // JPEG desteği kontrol et
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        );
    }
}
