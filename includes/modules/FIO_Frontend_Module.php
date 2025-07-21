<?php
/**
 * Frontend Modülü
 * 
 * Site ön yüzü ile ilgili işlemleri yönetir
 * Single Responsibility: Sadece frontend işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIO_Frontend_Module implements FIO_Module_Interface {
    
    use FIO_Logger_Trait;
    
    private $image_processor;
    private $settings;
    
    public function __construct(FIO_Image_Processor $image_processor, FIO_Settings $settings) {
        $this->image_processor = $image_processor;
        $this->settings = $settings;
    }
    
    public function init() {
        // Frontend init işlemleri
    }
    
    public function register_hooks() {
        // Frontend scripts ve styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // API endpoint'leri
        add_action('wp_ajax_get_optimized_image', array($this, 'handle_api_request'));
        add_action('wp_ajax_nopriv_get_optimized_image', array($this, 'handle_api_request'));
        
        // Content filtreleri (isteğe bağlı)
        if ($this->settings->is_auto_convert_enabled()) {
            add_filter('the_content', array($this, 'filter_content_images'));
        }
    }
    
    /**
     * Frontend scripts yükler
     */
    public function enqueue_frontend_scripts() {
        // Lazy loading aktifse script yükle
        if ($this->settings->is_lazy_loading_enabled()) {
            wp_enqueue_script(
                'fio-lazy-loading',
                FIO_PLUGIN_URL . 'assets/js/lazy-loading.js',
                array('jquery'),
                FIO_VERSION,
                true
            );
            
            // AJAX URL'sini script'e geç
            wp_localize_script('fio-lazy-loading', 'fioAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('optimize_image_nonce')
            ));
        }
        
        // Frontend CSS
        wp_enqueue_style(
            'fio-frontend',
            FIO_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            FIO_VERSION
        );
    }
    
    /**
     * Shortcode handler
     */
    public function handle_shortcode($atts) {
        $atts = shortcode_atts(array(
            'url' => '',
            'alt' => '',
            'class' => 'optimized-feed-image',
            'device' => 'auto',
            'width' => '',
            'height' => ''
        ), $atts);
        
        if (empty($atts['url'])) {
            $this->log_warning('Shortcode called without URL');
            return '';
        }
        
        // URL'yi optimize et
        $optimized = $this->image_processor->optimize_image($atts['url'], $atts['device']);
        
        // Optimizasyon başarısızsa orijinal URL'yi kullan
        $image_url = $optimized ? $optimized['optimized_url'] : $atts['url'];
        
        // HTML attributes hazırla
        $attributes = array(
            'src' => esc_url($image_url),
            'alt' => esc_attr($atts['alt']),
            'class' => esc_attr($atts['class'])
        );
        
        // Boyut attributes'ları ekle
        if (!empty($atts['width'])) {
            $attributes['width'] = esc_attr($atts['width']);
        }
        if (!empty($atts['height'])) {
            $attributes['height'] = esc_attr($atts['height']);
        }
        
        // Lazy loading ekle
        if ($this->settings->is_lazy_loading_enabled()) {
            $attributes['loading'] = 'lazy';
        }
        
        // Optimize edilmişse boyutları ekle
        if ($optimized && isset($optimized['dimensions'])) {
            if (empty($attributes['width'])) {
                $attributes['width'] = $optimized['dimensions']['width'];
            }
            if (empty($attributes['height'])) {
                $attributes['height'] = $optimized['dimensions']['height'];
            }
        }
        
        // HTML oluştur
        $html = '<img';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . $key . '="' . $value . '"';
        }
        $html .= ' />';
        
        return $html;
    }
    
    /**
     * API isteklerini handle eder
     */
    public function handle_api_request() {
        $url = sanitize_url($_POST['url'] ?? $_GET['url'] ?? '');
        $device = sanitize_text_field($_POST['device'] ?? $_GET['device'] ?? 'auto');
        
        if (empty($url)) {
            wp_die(json_encode(array('success' => false, 'message' => 'URL gerekli')));
        }
        
        $result = $this->image_processor->optimize_image($url, $device);
        
        if ($result) {
            wp_die(json_encode(array('success' => true, 'data' => $result)));
        } else {
            wp_die(json_encode(array('success' => false, 'message' => 'Optimizasyon başarısız')));
        }
    }
    
    /**
     * İçerikteki görselleri otomatik optimize eder
     */
    public function filter_content_images($content) {
        // ASHX görsellerini bul ve değiştir
        $pattern = '/<img[^>]*src=["\']([^"\']*\.ashx[^"\']*)["\'][^>]*>/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $full_img_tag = $matches[0];
            $image_url = $matches[1];
            
            // Optimize et
            $optimized = $this->image_processor->optimize_image($image_url);
            
            if ($optimized && isset($optimized['optimized_url'])) {
                // Orijinal src'yi değiştir
                $new_img_tag = str_replace($image_url, $optimized['optimized_url'], $full_img_tag);
                
                // Lazy loading ekle
                if ($this->settings->is_lazy_loading_enabled() && strpos($new_img_tag, 'loading=') === false) {
                    $new_img_tag = str_replace('<img', '<img loading="lazy"', $new_img_tag);
                }
                
                return $new_img_tag;
            }
            
            return $full_img_tag;
        }, $content);
    }
    
    /**
     * REST API endpoint'leri kaydet
     */
    public function register_rest_routes() {
        register_rest_route('fio/v1', '/optimize', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_optimize_image'),
            'permission_callback' => '__return_true',
            'args' => array(
                'url' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return filter_var($param, FILTER_VALIDATE_URL);
                    }
                ),
                'device' => array(
                    'default' => 'auto',
                    'validate_callback' => function($param) {
                        return in_array($param, array('auto', 'mobile', 'tablet', 'desktop'));
                    }
                )
            )
        ));
    }
    
    /**
     * REST API optimize callback
     */
    public function rest_optimize_image($request) {
        $url = $request->get_param('url');
        $device = $request->get_param('device');
        
        $result = $this->image_processor->optimize_image($url, $device);
        
        if ($result) {
            return new WP_REST_Response($result, 200);
        } else {
            return new WP_Error('optimization_failed', 'Görsel optimizasyonu başarısız', array('status' => 400));
        }
    }
    
    /**
     * Template fonksiyonları için helper
     */
    public function get_optimized_image_html($url, $args = array()) {
        $defaults = array(
            'alt' => '',
            'class' => 'optimized-image',
            'device' => 'auto',
            'lazy' => true
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Shortcode formatına çevir
        $shortcode_atts = array(
            'url' => $url,
            'alt' => $args['alt'],
            'class' => $args['class'],
            'device' => $args['device']
        );
        
        if (isset($args['width'])) {
            $shortcode_atts['width'] = $args['width'];
        }
        if (isset($args['height'])) {
            $shortcode_atts['height'] = $args['height'];
        }
        
        return $this->handle_shortcode($shortcode_atts);
    }
}