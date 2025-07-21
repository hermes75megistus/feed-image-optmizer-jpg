<?php
/**
 * Plugin Name: Feed Image Optimizer (Fixed)
 * Description: ASHX feed imajlarını WebP formatına dönüştürür, optimize eder ve öne çıkarılan görselleri günceller
 * Version: 3.0.2
 * Author: Your Name
 * Text Domain: feed-image-optimizer
 */

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Plugin sabitleri
define('FIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FIO_VERSION', '3.0.2');

/**
 * Ana Plugin Sınıfı - Düzeltilmiş Versiyon
 */
class FeedImageOptimizerMain {
    
    private static $instance = null;
    private $modules = array();
    
    /**
     * Singleton Pattern
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Fatal error'ları yakalamak için
        register_shutdown_function(array($this, 'handle_fatal_error'));
        
        try {
            $this->load_dependencies();
            $this->init_modules();
            $this->setup_hooks();
        } catch (Exception $e) {
            error_log('FIO Plugin Error: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="error"><p>Feed Image Optimizer hatası: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
    
    /**
     * Fatal error handler
     */
    public function handle_fatal_error() {
        $error = error_get_last();
        if ($error && $error['type'] === E_ERROR) {
            error_log('FIO Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
        }
    }
    
    /**
     * Gerekli dosyaları güvenli şekilde yükler
     */
    private function load_dependencies() {
        $required_files = array(
            'includes/interfaces/FIO_Module_Interface.php',
            'includes/traits/FIO_Logger_Trait.php',
            'includes/core/FIO_Settings.php',
            'includes/core/FIO_Cache_Manager.php',
            'includes/core/FIO_Image_Processor.php',
            'includes/modules/FIO_Admin_Module.php',
            'includes/modules/FIO_AJAX_Module.php',
            'includes/modules/FIO_Batch_Converter.php',
            'includes/modules/FIO_Frontend_Module.php',
            'includes/modules/FIO_Auto_Converter.php'
        );
        
        foreach ($required_files as $file) {
            $file_path = FIO_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                throw new Exception("Required file missing: $file");
            }
        }
    }
    
    /**
     * Modülleri güvenli şekilde başlatır
     */
    private function init_modules() {
        try {
            // Core bileşenler
            $settings = new FIO_Settings();
            $cache_manager = new FIO_Cache_Manager($settings);
            $image_processor = new FIO_Image_Processor($settings, $cache_manager);
            
            // Modülleri sadece ilgili sayfalarda yükle
            if (is_admin()) {
                $this->modules['admin'] = new FIO_Admin_Module($settings);
            }
            
            // AJAX modülü her zaman gerekli
            $this->modules['ajax'] = new FIO_AJAX_Module($image_processor, $settings);
            
            // Diğer modüller isteğe bağlı
            if ($settings->is_auto_convert_enabled()) {
                $this->modules['auto'] = new FIO_Auto_Converter($image_processor, $settings);
            }
            
            // Frontend modülü sadece frontend'de
            if (!is_admin()) {
                $this->modules['frontend'] = new FIO_Frontend_Module($image_processor, $settings);
            }
            
            // Batch converter sadece gerektiğinde
            $this->modules['batch'] = new FIO_Batch_Converter($image_processor, $settings);
            
        } catch (Exception $e) {
            error_log('FIO Module Init Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * WordPress hook'larını ayarlar
     */
    private function setup_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'plugins_loaded'));
        
        // Her modülün kendi hook'larını ayarlamasına izin ver
        foreach ($this->modules as $module) {
            if (method_exists($module, 'register_hooks')) {
                try {
                    $module->register_hooks();
                } catch (Exception $e) {
                    error_log('FIO Hook Registration Error: ' . $e->getMessage());
                }
            }
        }
        
        // Plugin yaşam döngüsü
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Her modülün başlatılmasına izin ver
        foreach ($this->modules as $module) {
            if (method_exists($module, 'init')) {
                try {
                    $module->init();
                } catch (Exception $e) {
                    error_log('FIO Module Init Error: ' . $e->getMessage());
                }
            }
        }
    }
    
    public function plugins_loaded() {
        // Text domain yükle
        load_plugin_textdomain('feed-image-optimizer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Belirli bir modülü döndürür
     */
    public function get_module($name) {
        return isset($this->modules[$name]) ? $this->modules[$name] : null;
    }
    
    /**
     * Plugin aktivasyonu - Güvenli versiyon
     */
    public function activate() {
        try {
            // Sistem gereksinimlerini kontrol et
            $this->check_system_requirements();
            
            // Varsayılan ayarları kur
            $settings = new FIO_Settings();
            $settings->set_defaults();
            
            // Gerekli klasörleri oluştur
            $cache_manager = new FIO_Cache_Manager($settings);
            $cache_manager->create_directories();
            
            // Başarılı aktivasyon
            add_option('fio_plugin_activated', true);
            
        } catch (Exception $e) {
            // Aktivasyon hatası
            error_log('FIO Activation Error: ' . $e->getMessage());
            wp_die('Feed Image Optimizer aktivasyon hatası: ' . $e->getMessage());
        }
    }
    
    /**
     * Sistem gereksinimlerini kontrol eder
     */
    private function check_system_requirements() {
        // PHP versiyonu
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            throw new Exception('PHP 7.4 veya üzeri gerekli. Mevcut versiyon: ' . PHP_VERSION);
        }
        
        // WordPress versiyonu
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            throw new Exception('WordPress 5.0 veya üzeri gerekli. Mevcut versiyon: ' . $wp_version);
        }
        
        // GD extension
        if (!extension_loaded('gd')) {
            throw new Exception('GD extension gerekli ancak yüklü değil.');
        }
        
        // Yazma izni
        $upload_dir = wp_upload_dir();
        if (!is_writable($upload_dir['basedir'])) {
            throw new Exception('Upload dizinine yazma izni yok: ' . $upload_dir['basedir']);
        }
    }
    
    /**
     * Plugin deaktivasyonu
     */
    public function deactivate() {
        try {
            // Her modülün deaktivasyon işlemlerini çalıştır
            foreach ($this->modules as $module) {
                if (method_exists($module, 'deactivate')) {
                    $module->deactivate();
                }
            }
            
            // Scheduled events'leri temizle
            wp_clear_scheduled_hook('fio_process_batch_conversion');
            wp_clear_scheduled_hook('fio_continue_batch_conversion');
            
            // Geçici ayarları temizle
            delete_option('fio_conversion_status');
            delete_option('fio_conversion_progress');
            delete_option('fio_plugin_activated');
            
        } catch (Exception $e) {
            error_log('FIO Deactivation Error: ' . $e->getMessage());
        }
    }
}

// Plugin'i güvenli şekilde başlat
add_action('plugins_loaded', function() {
    // Sadece gereksinimler karşılanıyorsa çalıştır
    if (version_compare(PHP_VERSION, '7.4', '>=') && 
        function_exists('add_action') && 
        extension_loaded('gd')) {
        FeedImageOptimizerMain::get_instance();
    }
});

/**
 * Helper fonksiyon - API
 */
function get_optimized_feed_image($url, $device = 'auto') {
    try {
        $plugin = FeedImageOptimizerMain::get_instance();
        $ajax_module = $plugin->get_module('ajax');
        
        if ($ajax_module) {
            return $ajax_module->get_image_processor()->optimize_image($url, $device);
        }
    } catch (Exception $e) {
        error_log('FIO API Error: ' . $e->getMessage());
    }
    
    return false;
}

/**
 * Shortcode desteği - Güvenli versiyon
 */
add_shortcode('optimized_feed_image', function($atts) {
    try {
        $plugin = FeedImageOptimizerMain::get_instance();
        $frontend_module = $plugin->get_module('frontend');
        
        if ($frontend_module) {
            return $frontend_module->handle_shortcode($atts);
        }
    } catch (Exception $e) {
        error_log('FIO Shortcode Error: ' . $e->getMessage());
    }
    
    return '';
});
