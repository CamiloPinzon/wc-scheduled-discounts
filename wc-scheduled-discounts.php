<?php

/**
 * Plugin Name: WooCommerce Scheduled Discounts Manager
 * Plugin URI: https://github.com/CamiloPinzon/wc-scheduled-discounts.git
 * Description: Permite programar descuentos del 10% o 15% en productos seleccionados con insignias personalizables
 * Version: 1.4.3
 * Author: Camilo Pinzón
 * Author URI: https://camilopinzon.netlify.app
 * Text Domain: wc-scheduled-discounts
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_SCHED_DISC_VERSION', '1.4.3');
define('WC_SCHED_DISC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_SCHED_DISC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_SCHED_DISC_PLUGIN_BASENAME', plugin_basename(__FILE__));

final class WC_Scheduled_Discounts_Init {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->load_dependencies();
        $this->init_classes();
    }
    
    private function load_dependencies() {
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-wc-scheduled-discounts.php';
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-discount-manager.php';
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-badge-display.php';
    }
    
    private function init_classes() {
        WC_Scheduled_Discounts::get_instance();
        
        if (is_admin()) {
            WC_Scheduled_Discounts_Admin_Settings::get_instance();
        }
        
        WC_Scheduled_Discounts_Discount_Manager::get_instance();
        WC_Scheduled_Discounts_Badge_Display::get_instance();
    }
    
    public function activate() {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(WC_SCHED_DISC_PLUGIN_BASENAME);
            wp_die(
                'Este plugin requiere WooCommerce para funcionar.',
                'Plugin no activado',
                array('back_link' => true)
            );
        }
        
        $default_settings = array(
            'products' => array(),
            'product_quantities' => array(),
            'start_date' => '',
            'end_date' => '',
            'badge_10' => '',
            'badge_15' => '',
            'is_active' => false
        );
        
        if (get_option('wc_sched_disc_settings') === false) {
            add_option('wc_sched_disc_settings', $default_settings);
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-wc-scheduled-discounts.php';
        require_once WC_SCHED_DISC_PLUGIN_DIR . 'includes/class-discount-manager.php';
        
        WC_Scheduled_Discounts_Discount_Manager::restore_all_prices();
        
        wp_clear_scheduled_hook('wc_sched_disc_check_campaign');
        
        flush_rewrite_rules();
    }
    
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>WooCommerce Scheduled Discounts Manager</strong> 
                requiere que WooCommerce esté instalado y activado.
            </p>
        </div>
        <?php
    }
}

WC_Scheduled_Discounts_Init::get_instance();