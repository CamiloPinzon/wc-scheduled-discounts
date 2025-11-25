<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scheduled_Discounts_Badge_Display {
    
    private static $instance = null;
    private $settings = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
        add_action('woocommerce_before_shop_loop_item_title', array($this, 'display_badge_loop'), 9);
        
        add_action('woocommerce_product_thumbnails', array($this, 'display_badge_single'), 5);
        add_action('woocommerce_before_single_product_summary', array($this, 'display_badge_single'), 21);
        
        add_filter('woocommerce_sale_flash', array($this, 'modify_sale_flash'), 10, 3);
    }
    
    private function get_settings() {
        if ($this->settings === null) {
            $this->settings = WC_Scheduled_Discounts::get_settings();
        }
        return $this->settings;
    }
    
    public function enqueue_styles() {
        if (!WC_Scheduled_Discounts::is_campaign_active()) {
            return;
        }
        
        wp_enqueue_style(
            'wc-sched-disc-badges',
            WC_SCHED_DISC_PLUGIN_URL . 'public/css/badge-styles.css',
            array(),
            WC_SCHED_DISC_VERSION
        );
    }
    
    public function display_badge_loop() {
        global $product;
        
        if (!$product || !WC_Scheduled_Discounts::is_campaign_active()) {
            return;
        }
        
        $this->render_badge($product->get_id(), 'loop');
    }
    
    public function display_badge_single() {
        global $product;
        
        if (!$product || !WC_Scheduled_Discounts::is_campaign_active()) {
            return;
        }
        
        static $displayed = false;
        
        if ($displayed) {
            return;
        }
        
        $discount = WC_Scheduled_Discounts::get_product_discount($product->get_id());
        
        if ($discount !== false) {
            $this->render_badge($product->get_id(), 'single');
            $displayed = true;
        }
    }
    
    private function render_badge($product_id, $context = 'loop') {
        $discount = WC_Scheduled_Discounts::get_product_discount($product_id);
        
        if ($discount === false) {
            return;
        }
        
        $settings = $this->get_settings();
        $badge_url = '';
        
        if ($discount === '10' && !empty($settings['badge_10'])) {
            $badge_url = $settings['badge_10'];
        } elseif ($discount === '15' && !empty($settings['badge_15'])) {
            $badge_url = $settings['badge_15'];
        }
        
        if (empty($badge_url)) {
            return;
        }
        
        $classes = array(
            'wc-sched-disc-badge',
            'wc-sched-disc-badge--' . $context,
            'wc-sched-disc-badge--' . $discount
        );
        
        printf(
            '<div class="%s"><img src="%s" alt="%s" class="wc-sched-disc-badge__image"></div>',
            esc_attr(implode(' ', $classes)),
            esc_url($badge_url),
            esc_attr(sprintf('%s%% de descuento', $discount))
        );
    }
    
    public function modify_sale_flash($html, $post, $product) {
        if (!WC_Scheduled_Discounts::is_campaign_active()) {
            return $html;
        }
        
        $discount = WC_Scheduled_Discounts::get_product_discount($product->get_id());
        
        if ($discount !== false) {
            return '';
        }
        
        return $html;
    }
}