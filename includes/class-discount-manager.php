<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scheduled_Discounts_Discount_Manager {
    
    private static $instance = null;
    private static $check_processing = false;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'check_campaign_status'), 99);
        add_action('wc_sched_disc_check_campaign', array($this, 'scheduled_check'));
        
        // Ensure discounts are applied when products are loaded (for edge cases)
        add_action('woocommerce_product_query', array($this, 'ensure_campaign_active'), 10);
        
        $this->schedule_cron();
    }
    
    public function ensure_campaign_active($query) {
        // This runs on product queries to ensure campaign is active
        // The main check happens in check_campaign_status, but this is a safety net
        if (!WC_Scheduled_Discounts::is_campaign_active()) {
            return;
        }
        
        $settings = WC_Scheduled_Discounts::get_settings();
        if (empty($settings['is_active'])) {
            // Campaign should be active but isn't marked - trigger activation
            $this->check_campaign_status();
        }
    }
    
    private function schedule_cron() {
        if (!wp_next_scheduled('wc_sched_disc_check_campaign')) {
            wp_schedule_event(time(), 'hourly', 'wc_sched_disc_check_campaign');
        }
    }
    
    public function scheduled_check() {
        $this->check_campaign_status();
    }
    
    public function check_campaign_status() {
        if (self::$check_processing) {
            return;
        }
        
        self::$check_processing = true;
        
        $settings = WC_Scheduled_Discounts::get_settings();
        $is_time_active = WC_Scheduled_Discounts::is_campaign_active();
        $was_active = !empty($settings['is_active']);
        
        if ($is_time_active && !$was_active) {
            // Campaign just became active
            $this->activate_campaign();
        } elseif ($is_time_active && $was_active) {
            // Campaign is active - verify all products have discounts applied
            if (!empty($settings['products'])) {
                $this->verify_discounts_applied($settings['products']);
            }
        } elseif (!$is_time_active && $was_active) {
            // Campaign just became inactive
            $this->deactivate_campaign();
        }
        
        self::$check_processing = false;
    }
    
    private function verify_discounts_applied($products) {
        // Verify that all products in the campaign have discounts applied
        // This is a safety check in case discounts weren't applied for some reason
        foreach ($products as $product_id => $discount) {
            $applied_discount = get_post_meta($product_id, '_wc_sched_disc_applied', true);
            
            // If discount is not applied, apply it now
            if ($applied_discount !== $discount) {
                $this->apply_discount_to_product($product_id, $discount);
            }
        }
    }
    
    private function activate_campaign() {
        $settings = WC_Scheduled_Discounts::get_settings();
        
        if (!empty($settings['products'])) {
            $this->apply_discounts_to_products($settings['products']);
        }
        
        $settings['is_active'] = true;
        update_option('wc_sched_disc_settings', $settings, false);
        
        $this->clear_all_caches();
    }
    
    private function deactivate_campaign() {
        $settings = WC_Scheduled_Discounts::get_settings();
        
        if (!empty($settings['products'])) {
            $this->restore_products_prices(array_keys($settings['products']));
        }
        
        $settings['is_active'] = false;
        update_option('wc_sched_disc_settings', $settings, false);
        
        $this->clear_all_caches();
    }
    
    private function apply_discounts_to_products($products) {
        if (empty($products) || !is_array($products)) {
            return;
        }
        
        foreach ($products as $product_id => $discount) {
            $this->apply_discount_to_product($product_id, $discount);
        }
        
        $this->clear_all_caches();
    }
    
    private function apply_discount_to_product($product_id, $discount) {
        $product_id = absint($product_id);
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        // Handle variable products differently - they may not have a price on the parent
        if ($product->is_type('variable')) {
            // Backup parent product stock settings if quantity is specified
            $settings = WC_Scheduled_Discounts::get_settings();
            if (isset($settings['product_quantities'][$product_id]) && $settings['product_quantities'][$product_id] > 0) {
                $backup_manage_stock = get_post_meta($product_id, '_wc_sched_disc_original_manage_stock', true);
                if (empty($backup_manage_stock)) {
                    $was_managing_stock = $product->managing_stock();
                    update_post_meta($product_id, '_wc_sched_disc_original_manage_stock', $was_managing_stock ? 'yes' : 'no');
                    
                    if ($was_managing_stock) {
                        $current_stock = $product->get_stock_quantity('edit');
                        if ($current_stock === null || $current_stock === '') {
                            $current_stock = $product->get_stock_quantity();
                        }
                        update_post_meta($product_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
                    } else {
                        update_post_meta($product_id, '_wc_sched_disc_original_stock', '');
                    }
                }
            }
            
            $variations = $product->get_children();
            $variations_processed = false;
            
            foreach ($variations as $variation_id) {
                if ($this->apply_discount_to_variation($variation_id, $discount)) {
                    $variations_processed = true;
                }
            }
            
            if ($variations_processed) {
                // Mark parent product as having discount applied
                update_post_meta($product_id, '_wc_sched_disc_applied', $discount);
                
                WC_Cache_Helper::get_transient_version('product', true);
                delete_transient('wc_var_prices_' . $product_id);
                $this->clear_product_caches($product_id);
                
                return true;
            }
            
            return false;
        }
        
        // For simple products, get regular price
        $regular_price = $product->get_regular_price('edit');
        
        // Fallback: try without 'edit' context if empty
        if (empty($regular_price) || !is_numeric($regular_price) || floatval($regular_price) <= 0) {
            $regular_price = $product->get_regular_price();
        }
        
        if (empty($regular_price) || !is_numeric($regular_price) || floatval($regular_price) <= 0) {
            return false;
        }
        
        $backup_regular = get_post_meta($product_id, '_wc_sched_disc_original_regular', true);
        
        if (empty($backup_regular)) {
            $current_sale = $product->get_sale_price('edit');
            if (empty($current_sale)) {
                $current_sale = $product->get_sale_price();
            }
            update_post_meta($product_id, '_wc_sched_disc_original_regular', $regular_price);
            update_post_meta($product_id, '_wc_sched_disc_original_sale', $current_sale ? $current_sale : '');
            
            // Backup stock management setting and quantity
            $was_managing_stock = $product->managing_stock();
            update_post_meta($product_id, '_wc_sched_disc_original_manage_stock', $was_managing_stock ? 'yes' : 'no');
            
            if ($was_managing_stock) {
                $current_stock = $product->get_stock_quantity('edit');
                if ($current_stock === null || $current_stock === '') {
                    $current_stock = $product->get_stock_quantity();
                }
                update_post_meta($product_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
            } else {
                update_post_meta($product_id, '_wc_sched_disc_original_stock', '');
            }
        }
        
        $discount_decimal = floatval($discount) / 100;
        $new_sale_price = floatval($regular_price) * (1 - $discount_decimal);
        $new_sale_price = round($new_sale_price, wc_get_price_decimals());
        
        $product->set_sale_price($new_sale_price);
        $product->set_price($new_sale_price);
        
        // Update stock quantity if specified in settings
        $settings = WC_Scheduled_Discounts::get_settings();
        if (isset($settings['product_quantities'][$product_id]) && $settings['product_quantities'][$product_id] > 0) {
            $new_quantity = absint($settings['product_quantities'][$product_id]);
            
            // Enable stock management
            $product->set_manage_stock(true);
            $product->set_stock_quantity($new_quantity);
            
            // Set stock status to instock if quantity > 0
            if ($new_quantity > 0) {
                $product->set_stock_status('instock');
            } else {
                $product->set_stock_status('outofstock');
            }
        }
        
        $product->save();
        
        update_post_meta($product_id, '_wc_sched_disc_applied', $discount);
        
        $this->clear_product_caches($product_id);
        
        return true;
    }
    
    private function apply_discount_to_variation($variation_id, $discount) {
        $variation_id = absint($variation_id);
        
        $variation = wc_get_product($variation_id);
        
        if (!$variation) {
            return false;
        }
        
        $regular_price = $variation->get_regular_price('edit');
        
        // Fallback: try without 'edit' context if empty
        if (empty($regular_price) || !is_numeric($regular_price) || floatval($regular_price) <= 0) {
            $regular_price = $variation->get_regular_price();
        }
        
        if (empty($regular_price) || !is_numeric($regular_price) || floatval($regular_price) <= 0) {
            return false;
        }
        
        $backup_regular = get_post_meta($variation_id, '_wc_sched_disc_original_regular', true);
        
        if (empty($backup_regular)) {
            $current_sale = $variation->get_sale_price('edit');
            if (empty($current_sale)) {
                $current_sale = $variation->get_sale_price();
            }
            update_post_meta($variation_id, '_wc_sched_disc_original_regular', $regular_price);
            update_post_meta($variation_id, '_wc_sched_disc_original_sale', $current_sale ? $current_sale : '');
            
            // Backup stock management setting and quantity
            $was_managing_stock = $variation->managing_stock();
            update_post_meta($variation_id, '_wc_sched_disc_original_manage_stock', $was_managing_stock ? 'yes' : 'no');
            
            if ($was_managing_stock) {
                $current_stock = $variation->get_stock_quantity('edit');
                if ($current_stock === null || $current_stock === '') {
                    $current_stock = $variation->get_stock_quantity();
                }
                update_post_meta($variation_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
            } else {
                update_post_meta($variation_id, '_wc_sched_disc_original_stock', '');
            }
        }
        
        $discount_decimal = floatval($discount) / 100;
        $new_sale_price = floatval($regular_price) * (1 - $discount_decimal);
        $new_sale_price = round($new_sale_price, wc_get_price_decimals());
        
        $variation->set_sale_price($new_sale_price);
        $variation->set_price($new_sale_price);
        
        // Update stock quantity if specified in settings (for parent product)
        $settings = WC_Scheduled_Discounts::get_settings();
        $parent_id = $variation->get_parent_id();
        if ($parent_id && isset($settings['product_quantities'][$parent_id]) && $settings['product_quantities'][$parent_id] > 0) {
            $new_quantity = absint($settings['product_quantities'][$parent_id]);
            
            // Enable stock management
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($new_quantity);
            
            // Set stock status to instock if quantity > 0
            if ($new_quantity > 0) {
                $variation->set_stock_status('instock');
            } else {
                $variation->set_stock_status('outofstock');
            }
        }
        
        $variation->save();
        
        update_post_meta($variation_id, '_wc_sched_disc_applied', $discount);
        
        $this->clear_product_caches($variation_id);
        
        return true;
    }
    
    private function restore_products_prices($product_ids) {
        if (empty($product_ids)) {
            return;
        }
        
        foreach ($product_ids as $product_id) {
            $this->restore_product_price($product_id);
        }
        
        $this->clear_all_caches();
    }
    
    public static function restore_product_price($product_id) {
        $product_id = absint($product_id);
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        // Handle variable products - restore variations first
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            $variations_restored = false;
            
            foreach ($variations as $variation_id) {
                if (self::restore_variation_price($variation_id)) {
                    $variations_restored = true;
                }
            }
            
            if ($variations_restored) {
                // Clean up parent meta if exists
                delete_post_meta($product_id, '_wc_sched_disc_original_regular');
                delete_post_meta($product_id, '_wc_sched_disc_original_sale');
                delete_post_meta($product_id, '_wc_sched_disc_original_stock');
                delete_post_meta($product_id, '_wc_sched_disc_original_manage_stock');
                delete_post_meta($product_id, '_wc_sched_disc_applied');
                
                $manager = self::get_instance();
                $manager->clear_product_caches($product_id);
                
                WC_Cache_Helper::get_transient_version('product', true);
                delete_transient('wc_var_prices_' . $product_id);
            }
            
            return;
        }
        
        // For simple products
        $original_regular = get_post_meta($product_id, '_wc_sched_disc_original_regular', true);
        $original_sale = get_post_meta($product_id, '_wc_sched_disc_original_sale', true);
        $original_stock = get_post_meta($product_id, '_wc_sched_disc_original_stock', true);
        $original_manage_stock = get_post_meta($product_id, '_wc_sched_disc_original_manage_stock', true);
        
        if ($original_regular !== '' && $original_regular !== false) {
            
            $product->set_regular_price($original_regular);
            
            if (!empty($original_sale) && $original_sale !== '' && $original_sale !== false) {
                $product->set_sale_price($original_sale);
                $product->set_price($original_sale);
            } else {
                $product->set_sale_price('');
                $product->set_price($original_regular);
            }
            
            // Restore stock management setting and quantity
            if ($original_manage_stock === 'yes') {
                $product->set_manage_stock(true);
                if ($original_stock !== '' && $original_stock !== false && $original_stock !== null) {
                    $product->set_stock_quantity($original_stock);
                    // Update stock status based on quantity
                    if ($original_stock > 0) {
                        $product->set_stock_status('instock');
                    } else {
                        $product->set_stock_status('outofstock');
                    }
                }
            } else {
                $product->set_manage_stock(false);
            }
            
            $product->save();
            
            delete_post_meta($product_id, '_wc_sched_disc_original_regular');
            delete_post_meta($product_id, '_wc_sched_disc_original_sale');
            delete_post_meta($product_id, '_wc_sched_disc_original_stock');
            delete_post_meta($product_id, '_wc_sched_disc_original_manage_stock');
            delete_post_meta($product_id, '_wc_sched_disc_applied');
            
            $manager = self::get_instance();
            $manager->clear_product_caches($product_id);
        }
    }
    
    private static function restore_variation_price($variation_id) {
        $variation_id = absint($variation_id);
        
        $variation = wc_get_product($variation_id);
        
        if (!$variation) {
            return false;
        }
        
        $original_regular = get_post_meta($variation_id, '_wc_sched_disc_original_regular', true);
        $original_sale = get_post_meta($variation_id, '_wc_sched_disc_original_sale', true);
        $original_stock = get_post_meta($variation_id, '_wc_sched_disc_original_stock', true);
        $original_manage_stock = get_post_meta($variation_id, '_wc_sched_disc_original_manage_stock', true);
        
        if ($original_regular !== '' && $original_regular !== false) {
            
            $variation->set_regular_price($original_regular);
            
            if (!empty($original_sale) && $original_sale !== '' && $original_sale !== false) {
                $variation->set_sale_price($original_sale);
                $variation->set_price($original_sale);
            } else {
                $variation->set_sale_price('');
                $variation->set_price($original_regular);
            }
            
            // Restore stock management setting and quantity
            if ($original_manage_stock === 'yes') {
                $variation->set_manage_stock(true);
                if ($original_stock !== '' && $original_stock !== false && $original_stock !== null) {
                    $variation->set_stock_quantity($original_stock);
                    // Update stock status based on quantity
                    if ($original_stock > 0) {
                        $variation->set_stock_status('instock');
                    } else {
                        $variation->set_stock_status('outofstock');
                    }
                }
            } else {
                $variation->set_manage_stock(false);
            }
            
            $variation->save();
            
            delete_post_meta($variation_id, '_wc_sched_disc_original_regular');
            delete_post_meta($variation_id, '_wc_sched_disc_original_sale');
            delete_post_meta($variation_id, '_wc_sched_disc_original_stock');
            delete_post_meta($variation_id, '_wc_sched_disc_original_manage_stock');
            delete_post_meta($variation_id, '_wc_sched_disc_applied');
            
            $manager = self::get_instance();
            $manager->clear_product_caches($variation_id);
            
            return true;
        }
        
        return false;
    }
    
    public static function restore_all_prices() {
        $settings = WC_Scheduled_Discounts::get_settings();
        
        if (!empty($settings['products'])) {
            foreach (array_keys($settings['products']) as $product_id) {
                self::restore_product_price($product_id);
            }
        }
        
        global $wpdb;
        $applied_products = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wc_sched_disc_applied'"
        );
        
        if (!empty($applied_products)) {
            foreach ($applied_products as $product_id) {
                self::restore_product_price($product_id);
            }
        }
        
        $settings['is_active'] = false;
        update_option('wc_sched_disc_settings', $settings, false);
        
        $manager = self::get_instance();
        $manager->clear_all_caches();
    }
    
    public static function sync_campaign($new_settings, $old_settings) {
        if (!is_array($new_settings)) {
            $new_settings = array();
        }
        if (!is_array($old_settings)) {
            $old_settings = array();
        }
        
        $old_products = isset($old_settings['products']) && is_array($old_settings['products']) 
            ? array_keys($old_settings['products']) 
            : array();
            
        $new_products = isset($new_settings['products']) && is_array($new_settings['products']) 
            ? array_keys($new_settings['products']) 
            : array();
        
        // Restore prices for removed products
        $removed_products = array_diff($old_products, $new_products);
        foreach ($removed_products as $product_id) {
            self::restore_product_price($product_id);
        }
        
        // Check if campaign should be active now
        $is_now_active = WC_Scheduled_Discounts::is_campaign_active();
        
        if ($is_now_active && !empty($new_settings['products'])) {
            // Campaign is active - apply discounts to all products
            $manager = self::get_instance();
            
            // First, restore any products that were previously discounted but are no longer in the list
            // Then apply discounts to all current products
            foreach ($new_products as $product_id) {
                // Check if this product already has a discount applied from this plugin
                $applied_discount = get_post_meta($product_id, '_wc_sched_disc_applied', true);
                if ($applied_discount) {
                    // Restore first to ensure clean state
                    self::restore_product_price($product_id);
                }
            }
            
            // Now apply discounts to all products
            $manager->apply_discounts_to_products($new_settings['products']);
            
            $new_settings['is_active'] = true;
        } else {
            // Campaign is not active - restore all product prices
            foreach ($new_products as $product_id) {
                self::restore_product_price($product_id);
            }
            
            $new_settings['is_active'] = false;
        }
        
        update_option('wc_sched_disc_settings', $new_settings, false);
        
        $manager = self::get_instance();
        $manager->clear_all_caches();
    }
    
    private function clear_product_caches($product_id) {
        wc_delete_product_transients($product_id);
        
        delete_transient('wc_products_onsale');
        delete_transient('wc_featured_products');
        delete_transient('wc_var_prices_' . $product_id);
        
        wp_cache_delete('product-' . $product_id, 'products');
        wp_cache_delete('wc_product_meta_lookup_' . $product_id, 'product_meta');
        
        WC_Cache_Helper::incr_cache_prefix('product_' . $product_id);
    }
    
    private function clear_all_caches() {
        delete_transient('wc_products_onsale');
        delete_transient('wc_featured_products');
        
        wc_delete_shop_order_transients();
        
        WC_Cache_Helper::get_transient_version('product', true);
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        do_action('wc_sched_disc_caches_cleared');
    }
}