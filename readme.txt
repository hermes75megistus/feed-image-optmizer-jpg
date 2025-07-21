=== Feed Image Optimizer - JPG Version === 
Contributors: yourname 
Tags: image, optimization, jpeg, jpg, ashx, featured-image
Requires at least: 5.0 
Tested up to: 6.4 
Stable tag: 3.0.3 
License: GPL v2 or later 
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ASHX feed imajlarını optimize edilmiş JPG formatına dönüştürür ve öne çıkarılan görselleri günceller.

== Description ==

Feed Image Optimizer - JPG Version, ASHX formatındaki feed görsellerini yüksek kaliteli JPG formatına dönüştüren güçlü bir WordPress plugin'idir. Plugin, görsel boyutlarını optimize ederek site performansınızı artırır ve kullanıcı deneyimini geliştirir.

= Ana Özellikler =

* **ASHX → JPG Dönüştürme**: ASHX formatındaki görüntüleri optimize edilmiş JPG formatına dönüştürür
* **Akıllı Boyutlandırma**: Cihaz tipine göre (mobil, tablet, desktop) optimal boyutlarda görüntü üretir
* **Otomatik Optimizasyon**: Yeni yüklenen görselleri otomatik olarak optimize eder
* **Toplu Dönüştürme**: Mevcut postlardaki öne çıkarılan görselleri toplu olarak dönüştürür
* **Cache Sistemi**: Optimize edilmiş görüntüleri cache'leyerek performansı artırır
* **Lazy Loading**: Sayfa yükleme performansını artıran lazy loading desteği
* **Admin Paneli**: Kolay kullanılabilir admin arayüzü ile tüm ayarları yönetin

= Teknik Özellikler =

* PHP 7.4+ desteği
* GD Extension kullanımı
* Güvenli dosya işlemleri
* WordPress Standards uyumlu
* Multi-device optimizasyon
* Background processing
* Comprehensive logging

= Kullanım =

1. Plugin'i aktifleştirin
2. Ayarlar > Image Optimizer menüsünden konfigürasyonu yapın
3. JPG kalitesi ve boyut ayarlarını belirleyin
4. Otomatik dönüştürmeyi aktifleştirin (isteğe bağlı)
5. Mevcut görseller için toplu dönüştürme çalıştırın

= Shortcode Kullanımı =

`[optimized_feed_image url="https://example.com/image.ashx" alt="Görsel açıklaması" width="600"]`

= Template Kullanımı =

`<?php 
$optimized_html = get_optimized_feed_image('https://example.com/image.ashx', 'mobile');
echo $optimized_html;
?>`

== Installation ==

1. WordPress admin panelinde Eklentiler > Yeni Ekle menüsüne gidin
2. "Feed Image Optimizer" araması yapın ve plugin'i bulun
3. "Şimdi Yükle" butonuna tıklayın
4. Plugin yüklendikten sonra "Etkinleştir" butonuna tıklayın
5. Ayarlar > Image Optimizer menüsünden yapılandırmayı tamamlayın

= Manuel Yükleme =

1. Plugin dosyalarını indirin
2. `/wp-content/plugins/feed-image-optimizer/` dizinine yükleyin
3. WordPress admin panelinden plugin'i etkinleştirin
4. Yapılandırma için Ayarlar > Image Optimizer'a gidin

== Frequently Asked Questions ==

= JPG kalitesi ne kadar olmalı? =

Optimal kalite için 85-90 arası değer önerilir. Bu değer dosya boyutu ile görsel kalite arasında iyi bir denge sağlar.

= Hangi cihazlar için optimizasyon yapılır? =

* Mobil: 480px maksimum genişlik
* Tablet: 768px maksimum genişlik  
* Desktop: 1200px maksimum genişlik

= Cache sistemi nasıl çalışır? =

Optimize edilmiş görseller `wp-content/uploads/feed-image-cache/` dizininde saklanır. Cache süresi varsayılan olarak 30 gündür.

= Orijinal dosyalar silinir mi? =

Bu ayara bağlıdır. Admin panelinden "Orijinal dosyalar silinsin" seçeneğini aktifleştirebilir veya pasifleştirebilirsiniz.

= Toplu dönüştürme ne kadar sürer? =

Bu, post sayısına ve görsel boyutlarına bağlıdır. Plugin background'da çalışır, böylece site performansını etkilemez.

== Screenshots ==

1. Admin paneli ana görünümü
2. JPG dönüştürme ayarları
3. Toplu dönüştürme arayüzü
4. Sistem durumu ve istatistikler
5. Debug ve log görünümü

== Changelog ==

= 3.0.3 =
* ASHX görüntüleri artık JPG formatına dönüştürülüyor (WebP yerine)
* JPG kalite ayarları eklendi
* Cache sistemi JPG formatı için optimize edildi
* Admin paneli JPG versiyonu için güncellendi
* Sistem gereksinimleri JPG desteği için güncellendi
* Backward compatibility desteği eklendi

= 3.0.2 =
* Improved error handling
* Better memory management
* Enhanced logging system
* Fixed batch conversion issues
* Performance optimizations

= 3.0.1 =
* Enhanced auto conversion module
* Improved cache management
* Better device detection
* Bug fixes and stability improvements

= 3.0.0 =
* Complete rewrite with modular architecture
* Improved error handling and logging
* Better batch processing
* Enhanced admin interface
* Auto conversion feature
* Comprehensive system requirements check

== Upgrade Notice ==

= 3.0.3 =
Önemli güncelleme: Plugin artık görüntüleri WebP yerine JPG formatına dönüştürüyor. Mevcut WebP ayarlarınız otomatik olarak JPG ayarlarına taşınacaktır.

== Requirements ==

* WordPress 5.0 veya üzeri
* PHP 7.4 veya üzeri
* GD Extension (JPEG desteği ile)
* Minimum 256MB RAM önerilir
* Dosya yazma izinleri

== Support ==

Plugin desteği için:
* GitHub: [Repository URL]
* WordPress.org forum
* E-mail: [Your email]

== License ==

Bu plugin GPL v2 veya üzeri lisansı altında dağıtılmaktadır.
