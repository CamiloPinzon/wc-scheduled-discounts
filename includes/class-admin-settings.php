<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scheduled_Discounts_Admin_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        add_action('wp_ajax_wc_sched_disc_search_products', array($this, 'ajax_search_products'));
        
        add_action('update_option_wc_sched_disc_settings', array($this, 'after_settings_saved'), 10, 2);
        add_action('add_option_wc_sched_disc_settings', array($this, 'after_settings_saved'), 10, 2);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Descuentos Programados',
            'Descuentos Programados',
            'manage_woocommerce',
            'wc-scheduled-discounts',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting(
            'wc_sched_disc_settings_group',
            'wc_sched_disc_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'products' => array(),
                    'product_quantities' => array(),
                    'start_date' => '',
                    'end_date' => '',
                    'badge_10' => '',
                    'badge_15' => '',
                    'is_active' => false
                )
            )
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_wc-scheduled-discounts' !== $hook) {
            return;
        }
        
        wp_enqueue_media();
        
        wp_enqueue_style(
            'wc-sched-disc-admin',
            WC_SCHED_DISC_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            WC_SCHED_DISC_VERSION
        );
        
        wp_enqueue_script(
            'wc-sched-disc-admin',
            WC_SCHED_DISC_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery'),
            WC_SCHED_DISC_VERSION,
            true
        );
        
        wp_localize_script('wc-sched-disc-admin', 'wcSchedDiscAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_sched_disc_admin_nonce'),
            'i18n' => array(
                'selectImage' => 'Seleccionar Insignia',
                'useImage' => 'Usar esta imagen',
                'noResults' => 'No se encontraron productos',
                'alreadyAdded' => 'Este producto ya está agregado',
                'searchMin' => 'Escribe al menos 2 caracteres para buscar'
            )
        ));
    }
    
    public function ajax_search_products() {
        check_ajax_referer('wc_sched_disc_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(array());
        }
        
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            's' => $search,
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        $query = new WP_Query($args);
        $results = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                
                if ($product) {
                    $price = $product->get_regular_price();
                    $results[] = array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'price' => $price ? wc_price($price) : 'N/A',
                        'sku' => $product->get_sku()
                    );
                }
            }
            wp_reset_postdata();
        }
        
        wp_send_json_success($results);
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No tienes permisos para acceder a esta página.');
        }
        
        $settings = WC_Scheduled_Discounts::get_settings();
        $is_active = WC_Scheduled_Discounts::is_campaign_active();
        
        $start_date_value = WC_Scheduled_Discounts::format_datetime_for_input($settings['start_date']);
        $end_date_value = WC_Scheduled_Discounts::format_datetime_for_input($settings['end_date']);
        
        ?>
        <div class="wrap wc-sched-disc-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($is_active): ?>
                <div class="notice notice-success inline">
                    <p>
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                        <strong>Campaña ACTIVA:</strong> Los descuentos se están aplicando actualmente.
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p>
                        <span class="dashicons dashicons-clock"></span>
                        <strong>Campaña INACTIVA:</strong> Los descuentos no se están aplicando en este momento.
                    </p>
                </div>
            <?php endif; ?>
            
            <?php settings_errors('wc_sched_disc_settings'); ?>
            
            <form method="post" action="options.php" id="wc-sched-disc-form">
                <?php settings_fields('wc_sched_disc_settings_group'); ?>
                
                <div class="wc-sched-disc-section">
                    <h2>Insignias de Descuento</h2>
                    <p class="description">Sube las imágenes PNG que se mostrarán sobre los productos con descuento.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="badge_10_url">Insignia 10%</label>
                            </th>
                            <td>
                                <div class="badge-upload-wrapper">
                                    <input type="hidden" 
                                           name="wc_sched_disc_settings[badge_10]" 
                                           id="badge_10_url" 
                                           value="<?php echo esc_url($settings['badge_10']); ?>">
                                    
                                    <button type="button" 
                                            class="button button-secondary wc-sched-disc-upload-btn" 
                                            data-input="badge_10_url" 
                                            data-preview="badge_10_preview">
                                        <span class="dashicons dashicons-upload"></span>
                                        Subir Insignia 10%
                                    </button>
                                    
                                    <div class="badge-preview-wrapper" id="badge_10_preview">
                                        <?php if (!empty($settings['badge_10'])): ?>
                                            <img src="<?php echo esc_url($settings['badge_10']); ?>" alt="Badge 10%">
                                            <button type="button" 
                                                    class="button wc-sched-disc-remove-btn" 
                                                    data-input="badge_10_url" 
                                                    data-preview="badge_10_preview">
                                                <span class="dashicons dashicons-no"></span> Eliminar
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="badge_15_url">Insignia 15%</label>
                            </th>
                            <td>
                                <div class="badge-upload-wrapper">
                                    <input type="hidden" 
                                           name="wc_sched_disc_settings[badge_15]" 
                                           id="badge_15_url" 
                                           value="<?php echo esc_url($settings['badge_15']); ?>">
                                    
                                    <button type="button" 
                                            class="button button-secondary wc-sched-disc-upload-btn" 
                                            data-input="badge_15_url" 
                                            data-preview="badge_15_preview">
                                        <span class="dashicons dashicons-upload"></span>
                                        Subir Insignia 15%
                                    </button>
                                    
                                    <div class="badge-preview-wrapper" id="badge_15_preview">
                                        <?php if (!empty($settings['badge_15'])): ?>
                                            <img src="<?php echo esc_url($settings['badge_15']); ?>" alt="Badge 15%">
                                            <button type="button" 
                                                    class="button wc-sched-disc-remove-btn" 
                                                    data-input="badge_15_url" 
                                                    data-preview="badge_15_preview">
                                                <span class="dashicons dashicons-no"></span> Eliminar
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="wc-sched-disc-section">
                    <h2>Programación de la Campaña</h2>
                    <p class="description">Define cuándo comenzará y terminará la aplicación de descuentos.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="start_date">Fecha y Hora de Inicio</label>
                            </th>
                            <td>
                                <input type="datetime-local" 
                                       name="wc_sched_disc_settings[start_date]" 
                                       id="start_date" 
                                       class="regular-text"
                                       value="<?php echo esc_attr($start_date_value); ?>">
                                <p class="description">
                                    Zona horaria del sitio: <?php echo esc_html(wp_timezone_string()); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="end_date">Fecha y Hora de Finalización</label>
                            </th>
                            <td>
                                <input type="datetime-local" 
                                       name="wc_sched_disc_settings[end_date]" 
                                       id="end_date" 
                                       class="regular-text"
                                       value="<?php echo esc_attr($end_date_value); ?>">
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="wc-sched-disc-section">
                    <h2>Productos con Descuento</h2>
                    <p class="description">Busca y selecciona los productos a los que deseas aplicar descuento.</p>
                    
                    <div class="product-selector-wrapper">
                        <div class="product-search-container">
                            <label for="product-search" class="screen-reader-text">Buscar producto</label>
                            <input type="text" 
                                   id="product-search" 
                                   class="regular-text" 
                                   placeholder="Escribe el nombre del producto para buscar..."
                                   autocomplete="off">
                            <div id="product-search-results" class="product-search-results"></div>
                        </div>
                        
                        <div id="selected-products" class="selected-products-container">
                            <h4>Productos seleccionados:</h4>
                            <div id="products-list">
                                <?php
                                if (!empty($settings['products']) && is_array($settings['products'])) {
                                    foreach ($settings['products'] as $product_id => $discount) {
                                        $product = wc_get_product($product_id);
                                        if ($product) {
                                            $quantity = isset($settings['product_quantities'][$product_id]) ? $settings['product_quantities'][$product_id] : '';
                                            $this->render_product_row($product_id, $product->get_name(), $discount, $quantity);
                                        }
                                    }
                                }
                                ?>
                            </div>
                            <p class="no-products-message" <?php echo (!empty($settings['products'])) ? 'style="display:none;"' : ''; ?>>
                                No hay productos seleccionados. Usa el buscador de arriba para agregar productos.
                            </p>
                        </div>
                    </div>
                </div>
                
                <p class="submit">
                    <?php submit_button('Guardar Configuración', 'primary', 'submit', false); ?>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function render_product_row($product_id, $product_name, $discount = '10', $quantity = '') {
        $product_id = absint($product_id);
        $discount = in_array($discount, array('10', '15')) ? $discount : '10';
        $quantity = !empty($quantity) ? absint($quantity) : '';
        ?>
        <div class="product-row" data-product-id="<?php echo esc_attr($product_id); ?>">
            <div class="product-info">
                <strong class="product-name"><?php echo esc_html($product_name); ?></strong>
                <span class="product-id">ID: <?php echo esc_html($product_id); ?></span>
            </div>
            <div class="product-actions">
                <label class="screen-reader-text" for="discount_<?php echo esc_attr($product_id); ?>">
                    Descuento para <?php echo esc_html($product_name); ?>
                </label>
                <select name="wc_sched_disc_settings[products][<?php echo esc_attr($product_id); ?>]" 
                        id="discount_<?php echo esc_attr($product_id); ?>"
                        class="discount-select">
                    <option value="10" <?php selected($discount, '10'); ?>>10% descuento</option>
                    <option value="15" <?php selected($discount, '15'); ?>>15% descuento</option>
                </select>
                <label class="screen-reader-text" for="quantity_<?php echo esc_attr($product_id); ?>">
                    Cantidad para <?php echo esc_html($product_name); ?>
                </label>
                <input type="number" 
                       name="wc_sched_disc_settings[product_quantities][<?php echo esc_attr($product_id); ?>]" 
                       id="quantity_<?php echo esc_attr($product_id); ?>"
                       class="quantity-input" 
                       value="<?php echo esc_attr($quantity); ?>"
                       placeholder="Cantidad"
                       min="0"
                       step="1">
                <button type="button" class="button button-link-delete remove-product-btn">
                    <span class="dashicons dashicons-trash"></span>
                    Eliminar
                </button>
            </div>
        </div>
        <?php
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $old_settings = get_option('wc_sched_disc_settings', array());
        if (!is_array($old_settings)) {
            $old_settings = array();
        }
        
        $sanitized['badge_10'] = '';
        if (!empty($input['badge_10'])) {
            $sanitized['badge_10'] = esc_url_raw($input['badge_10']);
        }
        
        $sanitized['badge_15'] = '';
        if (!empty($input['badge_15'])) {
            $sanitized['badge_15'] = esc_url_raw($input['badge_15']);
        }
        
        $sanitized['start_date'] = '';
        if (!empty($input['start_date'])) {
            $sanitized['start_date'] = WC_Scheduled_Discounts::format_datetime_for_storage($input['start_date']);
        }
        
        $sanitized['end_date'] = '';
        if (!empty($input['end_date'])) {
            $sanitized['end_date'] = WC_Scheduled_Discounts::format_datetime_for_storage($input['end_date']);
        }
        
        $sanitized['products'] = array();
        if (isset($input['products']) && is_array($input['products'])) {
            foreach ($input['products'] as $product_id => $discount) {
                $product_id = absint($product_id);
                if ($product_id > 0) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $sanitized['products'][$product_id] = in_array($discount, array('10', '15')) ? $discount : '10';
                    }
                }
            }
        }
        
        $sanitized['product_quantities'] = array();
        if (isset($input['product_quantities']) && is_array($input['product_quantities'])) {
            foreach ($input['product_quantities'] as $product_id => $quantity) {
                $product_id = absint($product_id);
                if ($product_id > 0 && isset($sanitized['products'][$product_id])) {
                    // Only store quantity if product exists in products array
                    // Allow 0 as a valid quantity (out of stock)
                    // Check for both empty string and null, and allow 0
                    $quantity = trim($quantity);
                    if ($quantity !== '' && $quantity !== null) {
                        $quantity_value = absint($quantity);
                        // Store even if 0 (out of stock is valid)
                        $sanitized['product_quantities'][$product_id] = $quantity_value;
                    }
                }
            }
        }
        
        $sanitized['is_active'] = isset($old_settings['is_active']) ? $old_settings['is_active'] : false;
        
        return $sanitized;
    }
    
    public function after_settings_saved($old_value, $new_value) {
        if (!is_array($old_value)) {
            $old_value = array();
        }
        if (!is_array($new_value)) {
            $new_value = array();
        }
        
        if (class_exists('WC_Scheduled_Discounts_Discount_Manager')) {
            // Force immediate sync
            WC_Scheduled_Discounts_Discount_Manager::sync_campaign($new_value, $old_value);
            
            // Also trigger a manual check to ensure everything is updated
            $manager = WC_Scheduled_Discounts_Discount_Manager::get_instance();
            if (method_exists($manager, 'check_campaign_status')) {
                $manager->check_campaign_status();
            }
        }
    }
}