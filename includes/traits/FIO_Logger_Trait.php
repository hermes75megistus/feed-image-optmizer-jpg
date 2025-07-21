<?php
/**
 * Logger Trait
 * 
 * Ortak logging fonksiyonalitesi
 * DRY Principle: Kod tekrarını önler
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FIO_Logger_Trait {
    
    /**
     * Log mesajı yazar
     * 
     * @param string $message Log mesajı
     * @param string $level Log seviyesi (info, warning, error)
     */
    protected function write_log($message, $level = 'info') {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/feed-image-logs/conversion.log';
        
        // Log dizini yoksa oluştur
        $log_dir = dirname($log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $class_name = get_class($this);
        $log_entry = "[$timestamp] [$level] [$class_name] $message\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // WP Debug aktifse ayrıca error_log'a da yaz
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FIO: $log_entry");
        }
    }
    
    /**
     * Hata log'u
     */
    protected function log_error($message) {
        $this->write_log($message, 'error');
    }
    
    /**
     * Uyarı log'u
     */
    protected function log_warning($message) {
        $this->write_log($message, 'warning');
    }
    
    /**
     * Bilgi log'u
     */
    protected function log_info($message) {
        $this->write_log($message, 'info');
    }
}