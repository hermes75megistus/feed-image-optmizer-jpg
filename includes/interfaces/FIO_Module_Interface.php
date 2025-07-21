<?php
/**
 * Modül Arayüzü
 * 
 * Her modülün uygulaması gereken temel metodları tanımlar
 * Interface Segregation Principle: Sadece gerekli metodlar
 */

if (!defined('ABSPATH')) {
    exit;
}

interface FIO_Module_Interface {
    
    /**
     * Modülü başlatır
     */
    public function init();
    
    /**
     * WordPress hook'larını kaydeder
     */
    public function register_hooks();
}