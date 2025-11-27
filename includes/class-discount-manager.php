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
            if (isset($settings['product_quantities'][$product_id]) && $settings['product_quantities'][$product_id] !== '') {
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
        
        // Save product with discount first
        $product->save();
        
        // Update stock quantity if specified in settings
        $settings = WC_Scheduled_Discounts::get_settings();
        if (isset($settings['product_quantities'][$product_id]) && $settings['product_quantities'][$product_id] !== '') {
            $new_quantity = absint($settings['product_quantities'][$product_id]);
            
            // Update stock meta directly (this is the most reliable way)
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock', $new_quantity);
            update_post_meta($product_id, '_stock_status', $new_quantity > 0 ? 'instock' : 'outofstock');
            
            // Also use WooCommerce methods to ensure consistency
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($new_quantity);
                $product->set_stock_status($new_quantity > 0 ? 'instock' : 'outofstock');
                $product->save();
                
                // Clear product caches to ensure changes are visible immediately
                wc_delete_product_transients($product_id);
                delete_transient('wc_product_' . $product_id);
            }
        }
        
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
        
        // Save variation with discount first
        $variation->save();
        
        // Update stock quantity if specified in settings (for parent product)
        $settings = WC_Scheduled_Discounts::get_settings();
        $parent_id = $variation->get_parent_id();
        if ($parent_id && isset($settings['product_quantities'][$parent_id]) && $settings['product_quantities'][$parent_id] !== '') {
            $new_quantity = absint($settings['product_quantities'][$parent_id]);
            
            // Update stock meta directly BEFORE reloading (this is the most reliable way)
            update_post_meta($variation_id, '_manage_stock', 'yes');
            update_post_meta($variation_id, '_stock', $new_quantity);
            update_post_meta($variation_id, '_stock_status', $new_quantity > 0 ? 'instock' : 'outofstock');
            
            // Force WooCommerce to recognize the stock change by using product object
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($new_quantity);
                $variation->set_stock_status($new_quantity > 0 ? 'instock' : 'outofstock');
                
                // Save variation
                $variation->save();
                
                // Clear all related caches
                wc_delete_product_transients($variation_id);
                wc_delete_product_transients($parent_id);
                delete_transient('wc_product_' . $variation_id);
                delete_transient('wc_var_prices_' . $parent_id);
                
                // Also update parent product caches
                WC_Cache_Helper::get_transient_version('product', true);
                $this->clear_product_caches($parent_id);
            }
        }
        
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
                    $stock_status = ($original_stock > 0) ? 'instock' : 'outofstock';
                    $product->set_stock_status($stock_status);
                    
                    // Update meta directly for consistency
                    update_post_meta($product_id, '_manage_stock', 'yes');
                    update_post_meta($product_id, '_stock', $original_stock);
                    update_post_meta($product_id, '_stock_status', $stock_status);
                }
            } else {
                $product->set_manage_stock(false);
                // Update meta directly
                update_post_meta($product_id, '_manage_stock', 'no');
                delete_post_meta($product_id, '_stock');
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
                    $stock_status = ($original_stock > 0) ? 'instock' : 'outofstock';
                    $variation->set_stock_status($stock_status);
                    
                    // Update meta directly for consistency
                    update_post_meta($variation_id, '_manage_stock', 'yes');
                    update_post_meta($variation_id, '_stock', $original_stock);
                    update_post_meta($variation_id, '_stock_status', $stock_status);
                }
            } else {
                $variation->set_manage_stock(false);
                // Update meta directly
                update_post_meta($variation_id, '_manage_stock', 'no');
                delete_post_meta($variation_id, '_stock');
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
        
        // Update stock quantities immediately if specified (regardless of campaign status)
        $manager = self::get_instance();
        if (isset($new_settings['product_quantities']) && is_array($new_settings['product_quantities'])) {
            foreach ($new_settings['product_quantities'] as $product_id => $quantity) {
                $product_id = absint($product_id);
                if ($product_id > 0 && isset($new_settings['products'][$product_id])) {
                    // Get quantity - accept any numeric value (including 0)
                    // Only skip if quantity is explicitly empty/null, not if it's 0
                    if (!isset($quantity) || ($quantity === '' && $quantity !== '0' && $quantity !== 0)) {
                        continue;
                    }
                    
                    // Convert to integer - 0 is valid
                    $quantity_value = is_numeric($quantity) ? absint($quantity) : 0;
                    
                    // Update stock immediately - always update if quantity is provided
                    $result = $manager->update_product_stock_quantity($product_id, $quantity_value);
                    
                    // Force cache clear and reload - very aggressive
                    wp_cache_delete($product_id, 'posts');
                    wp_cache_delete('product-' . $product_id, 'products');
                    wp_cache_delete('wc_product_meta_lookup_' . $product_id, 'product_meta');
                    wc_delete_product_transients($product_id);
                    delete_transient('wc_product_' . $product_id);
                    delete_transient('wc_var_prices_' . $product_id);
                    
                    // Force WooCommerce to reload
                    if (function_exists('wc_get_product')) {
                        $test_product = wc_get_product($product_id);
                        if ($test_product) {
                            // Clear all caches again
                            wc_delete_product_transients($product_id);
                            if ($test_product->is_type('variable')) {
                                $variations = $test_product->get_children();
                                foreach ($variations as $var_id) {
                                    wc_delete_product_transients($var_id);
                                    wp_cache_delete($var_id, 'posts');
                                }
                            }
                        }
                    }
                    
                    // Force WooCommerce cache version update
                    if (class_exists('WC_Cache_Helper')) {
                        WC_Cache_Helper::get_transient_version('product', true);
                        WC_Cache_Helper::incr_cache_prefix('product_' . $product_id);
                    }
                }
            }
        }
        
        // Check if campaign should be active now
        $is_now_active = WC_Scheduled_Discounts::is_campaign_active();
        
        if ($is_now_active && !empty($new_settings['products'])) {
            // Campaign is active - apply discounts to all products
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
            // Campaign is not active - restore all product prices (but keep stock quantities)
            // Only restore prices for products that have discounts applied
            foreach ($new_products as $product_id) {
                $applied_discount = get_post_meta($product_id, '_wc_sched_disc_applied', true);
                if ($applied_discount) {
                    // Only restore price, not stock (stock was updated separately above)
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $original_regular = get_post_meta($product_id, '_wc_sched_disc_original_regular', true);
                        $original_sale = get_post_meta($product_id, '_wc_sched_disc_original_sale', true);
                        
                        if ($original_regular !== '' && $original_regular !== false) {
                            $product->set_regular_price($original_regular);
                            if (!empty($original_sale) && $original_sale !== '' && $original_sale !== false) {
                                $product->set_sale_price($original_sale);
                                $product->set_price($original_sale);
                            } else {
                                $product->set_sale_price('');
                                $product->set_price($original_regular);
                            }
                            $product->save();
                            
                            // Only delete price-related meta, keep stock backup meta
                            delete_post_meta($product_id, '_wc_sched_disc_original_regular');
                            delete_post_meta($product_id, '_wc_sched_disc_original_sale');
                            delete_post_meta($product_id, '_wc_sched_disc_applied');
                            
                            $manager->clear_product_caches($product_id);
                        }
                    }
                }
            }
            
            $new_settings['is_active'] = false;
        }
        
        update_option('wc_sched_disc_settings', $new_settings, false);
        
        $manager->clear_all_caches();
    }
    
    public function update_product_stock_quantity($product_id, $quantity) {
        $product_id = absint($product_id);
        $quantity = absint($quantity);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        // Handle variable products
        if ($product->is_type('variable')) {
            // For variable products, stock can be managed at parent or variation level
            // First, check if parent manages stock
            $parent_manages_stock = $product->managing_stock();
            
            // Backup parent stock settings if not already backed up
            $parent_backup_stock = get_post_meta($product_id, '_wc_sched_disc_original_stock', true);
            if ($parent_backup_stock === '' || $parent_backup_stock === false) {
                $was_managing = $parent_manages_stock;
                $current_stock = $parent_manages_stock ? $product->get_stock_quantity() : '';
                update_post_meta($product_id, '_wc_sched_disc_original_manage_stock', $was_managing ? 'yes' : 'no');
                update_post_meta($product_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
            }
            
            // Update parent product stock (for parent-level stock management)
            // Use WooCommerce's official stock update function if available
            if (function_exists('wc_update_product_stock')) {
                wc_update_product_stock($product_id, $quantity, 'set');
            } else {
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', $quantity);
                update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
            }
            
            $product->set_manage_stock(true);
            $product->set_stock_quantity($quantity);
            $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
            $product->save();
            
            // Reload product to ensure changes took effect
            $product = wc_get_product($product_id);
            
            // Double-check: update meta again after save to ensure it's persisted
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock', $quantity);
            update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
            
            // Use WooCommerce stock status function if available
            if (function_exists('wc_update_product_stock_status')) {
                wc_update_product_stock_status($product_id, $quantity > 0 ? 'instock' : 'outofstock');
            }
            
            // Final save to ensure everything is committed
            if ($product) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($quantity);
                $product->save();
            }
            
            // Also update all variations (for variation-level stock management)
            $variations = $product->get_children();
            $updated = false;
            
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    // Backup original stock if not already backed up
                    $backup_stock = get_post_meta($variation_id, '_wc_sched_disc_original_stock', true);
                    if ($backup_stock === '' || $backup_stock === false) {
                        $was_managing = $variation->managing_stock();
                        $current_stock = $variation->managing_stock() ? $variation->get_stock_quantity() : '';
                        update_post_meta($variation_id, '_wc_sched_disc_original_manage_stock', $was_managing ? 'yes' : 'no');
                        update_post_meta($variation_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
                    }
                    
                    // Update stock - use WooCommerce's official function if available
                    if (function_exists('wc_update_product_stock')) {
                        wc_update_product_stock($variation_id, $quantity, 'set');
                    } else {
                        update_post_meta($variation_id, '_manage_stock', 'yes');
                        update_post_meta($variation_id, '_stock', $quantity);
                        update_post_meta($variation_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
                    }
                    
                    // Use WooCommerce methods
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($quantity);
                    $variation->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
                    $variation->save();
                    
                    // Double-check: update meta again after save to ensure it's persisted
                    update_post_meta($variation_id, '_manage_stock', 'yes');
                    update_post_meta($variation_id, '_stock', $quantity);
                    update_post_meta($variation_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
                    
                    // Use WooCommerce stock status function if available
                    if (function_exists('wc_update_product_stock_status')) {
                        wc_update_product_stock_status($variation_id, $quantity > 0 ? 'instock' : 'outofstock');
                    }
                    
                    // Clear caches
                    wc_delete_product_transients($variation_id);
                    $updated = true;
                }
            }
            
            // Clear parent caches
            wc_delete_product_transients($product_id);
            WC_Cache_Helper::get_transient_version('product', true);
            delete_transient('wc_var_prices_' . $product_id);
            $this->clear_product_caches($product_id);
            
            return true;
        }
        
        // Handle simple products
        // Backup original stock if not already backed up
        $backup_stock = get_post_meta($product_id, '_wc_sched_disc_original_stock', true);
        if ($backup_stock === '' || $backup_stock === false) {
            $was_managing = $product->managing_stock();
            $current_stock = $product->managing_stock() ? $product->get_stock_quantity() : '';
            update_post_meta($product_id, '_wc_sched_disc_original_manage_stock', $was_managing ? 'yes' : 'no');
            update_post_meta($product_id, '_wc_sched_disc_original_stock', $current_stock !== null ? $current_stock : '');
        }
        
        // Update stock - use WooCommerce's official function if available
        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($product_id, $quantity, 'set');
        } else {
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock', $quantity);
            update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
        }
        
        // Use WooCommerce methods
        $product->set_manage_stock(true);
        $product->set_stock_quantity($quantity);
        $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        $product->save();
        
        // Double-check: update meta again after save to ensure it's persisted
        update_post_meta($product_id, '_manage_stock', 'yes');
        update_post_meta($product_id, '_stock', $quantity);
        update_post_meta($product_id, '_stock_status', $quantity > 0 ? 'instock' : 'outofstock');
        
        // Use WooCommerce stock status function if available
        if (function_exists('wc_update_product_stock_status')) {
            wc_update_product_stock_status($product_id, $quantity > 0 ? 'instock' : 'outofstock');
        }
        
        // Clear caches
        wc_delete_product_transients($product_id);
        delete_transient('wc_product_' . $product_id);
        $this->clear_product_caches($product_id);
        
        return true;
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