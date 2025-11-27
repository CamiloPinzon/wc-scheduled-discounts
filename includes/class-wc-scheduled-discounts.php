<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scheduled_Discounts {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    public static function get_settings() {
        $defaults = array(
            'products' => array(),
            'product_quantities' => array(),
            'start_date' => '',
            'end_date' => '',
            'badge_10' => '',
            'badge_15' => '',
            'is_active' => false
        );
        
        $settings = get_option('wc_sched_disc_settings', array());
        
        if (!is_array($settings)) {
            $settings = array();
        }
        
        $settings = wp_parse_args($settings, $defaults);
        
        // Backward compatibility: migrate previous badge fields if present.
        if (empty($settings['badge_10']) && !empty($settings['badge_20'])) {
            $settings['badge_10'] = $settings['badge_20'];
        }
        if (empty($settings['badge_15']) && !empty($settings['badge_30'])) {
            $settings['badge_15'] = $settings['badge_30'];
        }
        
        return $settings;
    }
    
    public static function update_settings($settings) {
        return update_option('wc_sched_disc_settings', $settings, false);
    }
    
    public static function is_campaign_active() {
        $settings = self::get_settings();
        
        if (empty($settings['start_date']) || empty($settings['end_date'])) {
            return false;
        }
        
        $timezone = wp_timezone();
        
        try {
            $now = new DateTime('now', $timezone);
            $start = new DateTime($settings['start_date'], $timezone);
            $end = new DateTime($settings['end_date'], $timezone);
            
            return ($now >= $start && $now <= $end);
        } catch (Exception $e) {
            return false;
        }
    }
    
    public static function get_product_discount($product_id) {
        $settings = self::get_settings();
        $product_id = absint($product_id);
        
        if (!isset($settings['products'][$product_id])) {
            return false;
        }
        
        return $settings['products'][$product_id];
    }
    
    public static function get_discounted_products() {
        $settings = self::get_settings();
        return isset($settings['products']) && is_array($settings['products']) 
            ? $settings['products'] 
            : array();
    }
    
    public static function format_datetime_for_input($datetime_string) {
        if (empty($datetime_string)) {
            return '';
        }
        
        try {
            $dt = new DateTime($datetime_string, wp_timezone());
            return $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return '';
        }
    }
    
    public static function format_datetime_for_storage($datetime_string) {
        if (empty($datetime_string)) {
            return '';
        }
        
        try {
            $dt = new DateTime($datetime_string, wp_timezone());
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }
}