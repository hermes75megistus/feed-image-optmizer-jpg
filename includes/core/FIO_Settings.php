<?php
/**
 * Ayarlar Sınıfı
 * 
 * Tüm plugin ayarlarını yönetir
 * Single Responsibility: Sadece ayar yönetimi
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Settings {
    
    use FIO_Logger_Trait;
    
    private $default_settings = array(
        'fio_webp_quality' => 80,
        'fio_mobile_width' => 480,
        'fio_tablet_width' => 768,
        'fio_cache_days' => 30,
        'fio_lazy_loading' => 1,
        'fio_auto_convert' => 0,
        'fio_delete_original' => 0
    );
    
    /**
     * Ayar değeri al
     */
    public function get($key, $default = null) {
        if ($default === null && isset($this->default_settings[$key])) {
            $default = $this->default_settings[$key];
        }
        
        return get_option($key, $default);
    }
    
    /**
     * Ayar değeri kaydet
     */
    public function set($key, $value) {
        return update_option($key, $value);
    }
    
    /**
     * Varsayılan ayarları kur
     */
    public function set_defaults() {
        foreach ($this->default_settings as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
        $this->log_info('Default settings initialized');
    }
    
    /**
     * WebP kalitesi
     */
    public function get_webp_quality() {
        return intval($this->get('fio_webp_quality'));
    }
    
    /**
     * Mobil maksimum genişlik
     */
    public function get_mobile_width() {
        return intval($this->get('fio_mobile_width'));
    }
    
    /**
     * Tablet maksimum genişlik
     */
    public function get_tablet_width() {
        return intval($this->get('fio_tablet_width'));
    }
    
    /**
     * Cache süresi (gün)
     */
    public function get_cache_days() {
        return intval($this->get('fio_cache_days'));
    }
    
    /**
     * Lazy loading aktif mi?
     */
    public function is_lazy_loading_enabled() {
        return (bool) $this->get('fio_lazy_loading');
    }
    
    /**
     * Otomatik dönüştürme aktif mi?
     */
    public function is_auto_convert_enabled() {
        return (bool) $this->get('fio_auto_convert');
    }
    
    /**
     * Orijinal dosyalar silinsin mi?
     */
    public function should_delete_original() {
        return (bool) $this->get('fio_delete_original');
    }
    
    /**
     * Cihaza göre maksimum genişlik
     */
    public function get_max_width_for_device($device) {
        switch ($device) {
            case 'mobile':
                return $this->get_mobile_width();
            case 'tablet':
                return $this->get_tablet_width();
            default:
                return 1200; // Desktop default
        }
    }
    
    /**
     * Tüm ayarları dizi olarak döndür
     */
    public function get_all() {
        $settings = array();
        foreach ($this->default_settings as $key => $default) {
            $settings[$key] = $this->get($key, $default);
        }
        return $settings;
    }
    
    /**
     * WordPress Settings API'yi kaydet
     */
    public function register_settings() {
        foreach ($this->default_settings as $key => $default) {
            register_setting('feed_image_optimizer_settings', $key);
        }
    }
}